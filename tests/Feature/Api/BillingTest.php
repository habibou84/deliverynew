<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\User;
use App\Notifications\PlatformBillingNotice;
use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Abonnements, étape 6B : factures mensuelles, paiements, impayés progressifs, rappels.
 */
class BillingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Abidjan'));
        $this->buildWorld();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        $this->plan = Plan::create([
            'name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => 2,
            'overage_per_order' => 30, 'max_couriers' => null, 'features' => [],
        ]);
        $this->company->forceFill(['plan_id' => $this->plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
    }

    // Course ramassée ce jour-là (prise en charge : c'est elle qui est facturée)
    private function orderOn(string $date): Order
    {
        $order = $this->createOrder();
        $at = CarbonImmutable::parse("{$date} 12:00", 'Africa/Abidjan')->utc();
        $order->forceFill(['created_at' => $at, 'picked_up_at' => $at, 'status' => OrderStatus::PickedUp])->save();

        return $order;
    }

    private function invoiceOctober(): PlatformInvoice
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->expectsOutputToContain('1 facture(s) créée(s) pour 2026-10')->assertSuccessful();

        return PlatformInvoice::query()->sole();
    }

    public function test_monthly_invoice_with_overage(): void
    {
        Notification::fake();
        foreach (['2026-10-02', '2026-10-10', '2026-10-31'] as $day) {
            $this->orderOn($day);
        }
        $this->orderOn('2026-09-30');
        // Enregistrées dans le mois mais jamais ramassées, ou annulées avant : pas facturées
        $this->createOrder()->forceFill(['created_at' => CarbonImmutable::parse('2026-10-05 12:00', 'Africa/Abidjan')->utc()])->save();
        $this->createOrder()->forceFill(['created_at' => CarbonImmutable::parse('2026-10-06 12:00', 'Africa/Abidjan')->utc(), 'status' => OrderStatus::Cancelled])->save();

        $invoice = $this->invoiceOctober();

        $this->assertSame(['FAC-202610-0001', 25000, 2, 3, 1, 30, 25030, '2026-11-11'], [
            $invoice->number, $invoice->base_amount, $invoice->included_orders, $invoice->orders_count,
            $invoice->overage_orders, $invoice->overage_amount, $invoice->total, $invoice->due_at->toDateString(),
        ]);
        Notification::assertSentTo($this->admin, PlatformBillingNotice::class, fn ($n) => $n->stage === 'issued');
        Notification::assertNotSentTo($this->dispatcher, PlatformBillingNotice::class);

        // Une seule facture par mois
        $this->artisan('billing:invoice --month=2026-10')->expectsOutputToContain('0 facture(s)')->assertSuccessful();
    }

    public function test_trial_days_are_not_billed(): void
    {
        // Essai jusqu'au 15 : 16 jours facturés sur 31
        $this->company->update(['trial_ends_at' => '2026-10-15']);
        $this->orderOn('2026-10-10');
        $this->orderOn('2026-10-20');
        $this->orderOn('2026-10-21');

        $invoice = $this->invoiceOctober();
        $this->assertSame(['2026-10-16', 12903, 1, 2, 1, 12933], [
            $invoice->period_start->toDateString(), $invoice->base_amount, $invoice->included_orders,
            $invoice->orders_count, $invoice->overage_orders, $invoice->total,
        ]);

        // Mois entier en essai, ou sans formule : rien à facturer
        $billing = app(Billing::class);
        $this->company->update(['trial_ends_at' => '2026-11-30']);
        $this->assertNull($billing->invoiceMonth($this->company->fresh(), '2026-11'));
        $other = Company::factory()->create();
        $this->assertNull($billing->invoiceMonth($other, '2026-10'));
    }

    public function test_payments_and_void(): void
    {
        $invoice = $this->invoiceOctober();
        Sanctum::actingAs($this->root);
        $url = "/api/v1/console/invoices/{$invoice->id}";

        $this->postJson("{$url}/payments", ['amount' => 10000, 'method' => 'mobile_money', 'reference' => 'OM-123', 'paid_on' => '2026-11-01'])
            ->assertCreated()->assertJsonPath('data.balance', 15000)->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.payments.0.method_label', 'Mobile Money');
        $this->postJson("{$url}/payments", ['amount' => 20000, 'method' => 'cash', 'paid_on' => '2026-11-01'])->assertJsonValidationErrors('amount');
        $this->postJson("{$url}/void")->assertJsonValidationErrors('invoice');
        $this->postJson("{$url}/payments", ['amount' => 15000, 'method' => 'cash', 'paid_on' => '2026-11-01'])
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', 0);
        $this->postJson("{$url}/payments", ['amount' => 1, 'method' => 'cash', 'paid_on' => '2026-11-01'])->assertJsonValidationErrors('amount');

        // Facture d'un autre mois annulée
        $this->company->update(['trial_ends_at' => null]);
        $this->postJson('/api/v1/console/invoices/generate', ['period' => '2026-09'])->assertCreated()->assertJsonPath('data.created', 1);
        $september = PlatformInvoice::query()->where('period', '2026-09')->sole();
        $this->postJson("/api/v1/console/invoices/{$september->id}/void", ['reason' => 'Mois offert'])->assertJsonPath('data.status', 'void');

        // Réservé au super administrateur
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/console/invoices')->assertForbidden();
    }

    public function test_unpaid_invoice_restricts_new_orders_progressively(): void
    {
        $invoice = $this->invoiceOctober();
        Sanctum::actingAs($this->admin);
        $state = fn () => $this->getJson('/api/v1/auth/me')->json('data.company.billing_state');

        $this->assertSame('ok', $state());

        // Échéance (11/11) passée
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00', 'Africa/Abidjan'));
        $this->assertSame('due', $state());

        // J+7 : bandeau
        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00', 'Africa/Abidjan'));
        $this->assertSame('warning', $state());
        $this->createOrder();

        // J+15 : plus de nouvelles courses, tout le reste consultable
        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00', 'Africa/Abidjan'));
        $this->assertSame('restricted', $state());
        $this->getJson('/api/v1/orders')->assertOk();
        try {
            $this->createOrder([], $this->admin);
            $this->fail('Création de course acceptée malgré la facture impayée.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Mon abonnement', $e->getMessage());
        }
        try {
            $this->createOrder();
            $this->fail('Création de course acceptée malgré la facture impayée.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Contactez votre entreprise de livraison', $e->getMessage());
        }

        // Payée : tout redevient normal
        app(Billing::class)->recordPayment($invoice, ['amount' => $invoice->total, 'method' => 'bank_transfer', 'paid_on' => '2026-11-26'], $this->root);
        $this->assertSame('ok', $state());
        $this->createOrder();
    }

    public function test_reminders_are_sent_once_per_stage(): void
    {
        $this->invoiceOctober();
        Notification::fake();

        $days = ['2026-11-10' => 0, '2026-11-11' => 1, '2026-11-12' => 0, '2026-11-18' => 1, '2026-11-19' => 0, '2026-11-26' => 1, '2026-11-27' => 0];
        foreach ($days as $day => $expected) {
            $this->travelTo(CarbonImmutable::parse("{$day} 08:05", 'Africa/Abidjan'));
            $this->assertSame($expected, app(Billing::class)->remind(), "Rappels du {$day}");
        }

        foreach (['due', 'warning', 'restricted'] as $stage) {
            Notification::assertSentTo($this->admin, PlatformBillingNotice::class, fn ($n) => $n->stage === $stage);
        }
    }

    public function test_company_sees_its_invoices_and_how_to_pay(): void
    {
        $invoice = $this->invoiceOctober();
        Sanctum::actingAs($this->root);
        $this->putJson('/api/v1/console/settings', ['payment_instructions' => 'Orange Money : 07 00 00 00 00'])->assertOk();
        $this->getJson('/api/v1/console/invoices')->assertOk()
            ->assertJsonPath('data.0.number', 'FAC-202610-0001')
            ->assertJsonPath('meta.totals.invoiced', 0)
            ->assertJsonPath('meta.totals.outstanding', 25000)
            ->assertJsonPath('meta.totals.mrr', 25000);
        $this->getJson("/api/v1/console/companies/{$this->company->id}")->assertJsonPath('data.invoices.0.total', 25000)
            ->assertJsonPath('data.billing.amount_due', 25000);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.invoices.0.number', 'FAC-202610-0001')
            ->assertJsonPath('data.billing.amount_due', 25000)
            ->assertJsonPath('data.payment_instructions', 'Orange Money : 07 00 00 00 00');
        $this->getJson("/api/v1/subscription/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.total', 25000);

        // Facture d'une autre entreprise : introuvable
        $other = PlatformInvoice::query()->create([...$invoice->only(['period', 'period_start', 'period_end', 'plan_name', 'base_amount', 'orders_count', 'overage_orders', 'overage_per_order', 'overage_amount', 'total', 'issued_at', 'due_at']),
            'company_id' => Company::factory()->create()->id, 'number' => 'FAC-X', 'included_orders' => 2]);
        $this->getJson("/api/v1/subscription/invoices/{$other->id}")->assertNotFound();
    }

    public function test_weekly_billing_from_the_next_monday_without_billing_a_day_twice(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Africa/Abidjan'));
        Sanctum::actingAs($this->admin);

        // À la semaine à partir du lundi suivant ; la semaine coûte le mois ramené à la semaine (12 mois = 52 semaines)
        $this->putJson('/api/v1/subscription/billing-cycle', ['billing_cycle' => 'weekly'])->assertOk()
            ->assertJsonPath('data.billing_period.cycle', 'monthly')->assertJsonPath('data.billing_period.next_cycle', 'weekly')
            ->assertJsonPath('data.billing_period.next_cycle_from', '2026-10-19')->assertJsonPath('data.plan.price_weekly', 5770);
        $this->dispatcherCannotChangeCycle();

        $this->orderOn('2026-10-10');
        $this->orderOn('2026-10-20');
        $this->orderOn('2026-10-21');

        // Lundi : facture de la semaine écoulée (du 19 au 25), à régler sous 4 jours
        $this->travelTo(CarbonImmutable::parse('2026-10-26 02:00', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->expectsOutputToContain('1 facture(s) créée(s) pour 2026-W43')->assertSuccessful();
        $week = PlatformInvoice::query()->where('period', '2026-W43')->sole();
        $this->assertSame(['FAC-2026W43-0001', 'weekly', '2026-10-19', '2026-10-25', 5770, 0, 2, 60, 5830, '2026-10-30'], [
            $week->number, $week->cycle, $week->period_start->toDateString(), $week->period_end->toDateString(),
            $week->base_amount, $week->included_orders, $week->orders_count, $week->overage_amount, $week->total, $week->due_at->toDateString(),
        ]);
        $this->assertSame('semaine du 19/10 au 25/10/2026', $week->periodLabel());

        // Le 1er : octobre facturé au mois seulement jusqu'au 18 (18 jours sur 31)
        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->expectsOutputToContain('1 facture(s) créée(s) pour 2026-10')->assertSuccessful();
        $month = PlatformInvoice::query()->where('period', '2026-10')->sole();
        $this->assertSame(['monthly', '2026-10-01', '2026-10-18', 14516, 1, 1, 14516], [
            $month->cycle, $month->period_start->toDateString(), $month->period_end->toDateString(),
            $month->base_amount, $month->included_orders, $month->orders_count, $month->total,
        ]);

        // Pas d'aller-retour : un seul changement toutes les cinq semaines
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Africa/Abidjan'));
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/subscription/billing-cycle', ['billing_cycle' => 'monthly'])->assertJsonValidationErrors('billing_cycle');
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.billing_period.cycle', 'weekly')->assertJsonPath('data.usage.included_orders', 0);

        // Console : une semaine pas encore commencée ne se facture pas
        Sanctum::actingAs($this->root);
        $this->postJson('/api/v1/console/invoices/generate', ['period' => '2026-W46'])->assertJsonValidationErrors('period');
        $this->postJson('/api/v1/console/invoices/generate', ['period' => '2026-W44'])->assertCreated()->assertJsonPath('data.created', 1);
        $this->getJson('/api/v1/console/invoices')->assertJsonPath('data.0.period_label', 'semaine du 26/10 au 01/11/2026');
    }

    public function test_a_pending_change_can_be_cancelled_before_it_starts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Africa/Abidjan'));
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/subscription/billing-cycle', ['billing_cycle' => 'weekly'])->assertJsonPath('data.billing_period.next_cycle', 'weekly');
        $this->putJson('/api/v1/subscription/billing-cycle', ['billing_cycle' => 'monthly'])->assertOk()
            ->assertJsonPath('data.billing_period.cycle', 'monthly')->assertJsonPath('data.billing_period.next_cycle', null);
        $this->assertSame(['monthly', null], [$this->company->fresh()->billing_cycle, $this->company->fresh()->billing_cycle_from]);
    }

    private function dispatcherCannotChangeCycle(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->putJson('/api/v1/subscription/billing-cycle', ['billing_cycle' => 'monthly'])->assertForbidden();
        Sanctum::actingAs($this->admin);
    }
}
