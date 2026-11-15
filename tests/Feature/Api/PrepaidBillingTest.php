<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\User;
use App\Services\Ai\JibiatAi;
use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Tout prépayé : facture de la période suivante émise 5 jours avant (2 pour une semaine), à payer au premier jour,
 * 3 jours de grâce ; achats payés tout de suite (livreur en plus au prorata, pack de courses, pack de points IA),
 * pris en compte au paiement ; courses limitées à la formule et aux packs ; passage au prépayé sans rien facturer deux
 * fois ; formule « À la livraison » restée après coup.
 */
class PrepaidBillingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Plan $plan;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Africa/Abidjan'));
        $this->buildWorld();
        $this->plan = Plan::create([
            'name' => 'Pro', 'price_monthly' => 60000, 'included_orders' => 3, 'overage_per_order' => 25,
            'max_couriers' => 2, 'extra_courier_price' => 3000, 'features' => [], 'ai_points' => 100,
        ]);
        $this->company->forceFill(['plan_id' => $this->plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
    }

    private function pay(PlatformInvoice $invoice): void
    {
        app(Billing::class)->recordPayment($invoice->fresh(), ['amount' => $invoice->fresh()->balance(), 'method' => 'mobile_money', 'paid_on' => now()->toDateString()], $this->root);
    }

    private function prepaidDecember(): PlatformInvoice
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 01:30', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();

        return PlatformInvoice::query()->where('period', '2026-12')->sole();
    }

    private function pickedUpOrder(): Order
    {
        $order = $this->createOrder([], $this->admin);
        $order->forceFill(['status' => OrderStatus::PickedUp, 'picked_up_at' => now()])->save();

        return $order;
    }

    public function test_the_next_month_is_invoiced_five_days_before_and_due_on_the_first(): void
    {
        // Le 25 : trop tôt (6 jours avant)
        $this->travelTo(CarbonImmutable::parse('2026-11-25 01:30', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->assertSame(0, PlatformInvoice::count());

        $december = $this->prepaidDecember();
        $this->assertTrue($december->prepaid);
        $this->assertSame(['subscription', '2026-12-01', '2026-12-01', 60000, 3, 0], [
            $december->kind, $december->due_at->toDateString(), $december->period_start->toDateString(), $december->total, $december->included_orders, $december->overage_amount,
        ]);
        // Une seule fois
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->assertSame(1, PlatformInvoice::count());

        // Le 1er : novembre (passage au prépayé) facturé après coup ; décembre déjà payé d'avance, pas refait
        $this->travelTo(CarbonImmutable::parse('2026-12-01 01:30', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->assertSame(['2026-11', '2026-12'], PlatformInvoice::query()->orderBy('period')->pluck('period')->all());
        $this->assertFalse(PlatformInvoice::query()->where('period', '2026-11')->sole()->prepaid);

        // Fin décembre : janvier d'avance ; le 1er janvier, rien pour décembre (déjà payé d'avance)
        $this->travelTo(CarbonImmutable::parse('2026-12-27 01:30', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 01:30', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->assertSame(1, PlatformInvoice::query()->where('period', '2026-12')->count());
        $this->assertTrue(PlatformInvoice::query()->where('period', '2027-01')->sole()->prepaid);
    }

    public function test_three_days_of_grace_then_no_new_orders_until_paid(): void
    {
        $december = $this->prepaidDecember();
        $billing = app(Billing::class);
        Sanctum::actingAs($this->admin);
        $state = fn () => $this->getJson('/api/v1/auth/me')->json('data.company.billing_state');

        // Dû le 1er ; bandeau dès le 2 ; encore des courses jusqu'au 3
        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'Africa/Abidjan'));
        $this->assertSame('warning', $state());
        $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00', 'Africa/Abidjan'));
        $this->assertFalse($billing->isRestricted($this->company->fresh()));
        $this->createOrder([], $this->admin);

        // Le 4 : plus de nouvelles courses
        $this->travelTo(CarbonImmutable::parse('2026-12-04 09:00', 'Africa/Abidjan'));
        $this->assertSame('restricted', $state());
        try {
            $this->createOrder([], $this->admin);
            $this->fail('Course acceptée malgré l\'abonnement impayé.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Mon abonnement', $e->getMessage());
        }

        // Payé : tout redevient normal
        $this->pay($december);
        $this->assertFalse($billing->isRestricted($this->company->fresh()));
        $this->createOrder([], $this->admin);
    }

    public function test_a_prepaid_month_limits_orders_until_a_pack_is_bought(): void
    {
        $december = $this->prepaidDecember();
        $this->pay($december);
        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'Africa/Abidjan'));
        // Novembre (après coup) payé aussi
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
        $this->pay(PlatformInvoice::query()->where('period', '2026-11')->sole());

        foreach ([1, 2, 3] as $n) {
            $this->pickedUpOrder();
        }
        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/subscription')->assertOk()->json('data.prepaid');
        $this->assertSame(['included' => 3, 'packs' => 0, 'used' => 3, 'left' => 0], $data['quota']);
        try {
            $this->createOrder([], $this->admin);
            $this->fail('Course acceptée au-delà de la formule.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('pack de courses', $e->getMessage());
        }

        // Pack de 100 courses à 25 F : à payer, puis pris en compte
        $pack = $this->postJson('/api/v1/subscription/purchases', ['kind' => 'orders'])->assertCreated()
            ->assertJsonPath('data.total', 2500)->assertJsonPath('data.status', 'open')->assertJsonPath('data.kind', 'orders')->json('data');
        $this->assertSame(0, $this->getJson('/api/v1/subscription')->json('data.prepaid.quota.left'));
        // Un achat pas payé ne bloque rien d'autre
        $this->assertFalse(app(Billing::class)->isRestricted($this->company->fresh()));
        $this->pay(PlatformInvoice::find($pack['id']));
        $this->assertSame(100, $this->getJson('/api/v1/subscription')->json('data.prepaid.quota.left'));
        $this->createOrder([], $this->admin);
    }

    public function test_an_extra_courier_is_paid_prorata_now_then_on_each_renewal(): void
    {
        $this->pay($this->prepaidDecember());
        $this->travelTo(CarbonImmutable::parse('2026-12-17 09:00', 'Africa/Abidjan'));
        Sanctum::actingAs($this->admin->fresh());

        // 15 jours sur 31 de 3 000 F
        $courier = $this->postJson('/api/v1/subscription/purchases', ['kind' => 'courier'])->assertCreated()->assertJsonPath('data.total', 1452)->json('data');
        $this->assertSame(0, $this->company->fresh()->extra_couriers);
        $this->pay(PlatformInvoice::find($courier['id']));
        $this->assertSame(1, $this->company->fresh()->extra_couriers);

        // Janvier : forfait + le livreur en plus, pour tout le mois
        $this->travelTo(CarbonImmutable::parse('2026-12-27 01:30', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $january = PlatformInvoice::query()->where('period', '2027-01')->sole();
        $this->assertSame([3000, 63000], [$january->extra_couriers_amount, $january->total]);
    }

    public function test_a_pack_of_ai_points_adds_points_for_the_month_once_paid_and_can_be_cancelled_before(): void
    {
        $ai = app(JibiatAi::class);
        Sanctum::actingAs($this->admin);
        $this->assertSame(100, $ai->monthlyPoints($this->company->fresh()));
        $options = collect($this->getJson('/api/v1/subscription')->json('data.prepaid.purchases'))->keyBy('kind');
        $this->assertSame(5000, $options['ai_points']['total']);

        $pack = $this->postJson('/api/v1/subscription/purchases', ['kind' => 'ai_points'])->assertCreated()->json('data');
        $this->assertSame(100, $ai->monthlyPoints($this->company->fresh()));
        $this->pay(PlatformInvoice::find($pack['id']));
        $this->assertSame(600, $ai->monthlyPoints($this->company->fresh()));

        // Achat abandonné : annulé ; un abonnement ne s'annule pas ici
        $other = $this->postJson('/api/v1/subscription/purchases', ['kind' => 'ai_points'])->assertCreated()->json('data');
        $this->deleteJson("/api/v1/subscription/purchases/{$other['id']}")->assertOk();
        $this->assertSame('void', PlatformInvoice::find($other['id'])->status);
        $december = $this->prepaidDecember();
        $this->deleteJson("/api/v1/subscription/purchases/{$december->id}")->assertUnprocessable();

        // Le mois suivant : retour aux points de la formule
        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'Africa/Abidjan'));
        $this->assertSame(100, $ai->monthlyPoints($this->company->fresh()));
    }

    public function test_the_trial_end_is_invoiced_in_advance_and_the_per_delivery_plan_stays_after_the_fact(): void
    {
        // Essai jusqu'au 22/11 : reste de novembre (8 jours sur 30) facturé d'avance, dû le 23
        $this->company->forceFill(['trial_ends_at' => '2026-11-22'])->save();
        $this->artisan('billing:invoice')->assertSuccessful();
        $november = PlatformInvoice::query()->where('period', '2026-11')->sole();
        $this->assertTrue($november->prepaid);
        $this->assertSame(['2026-11-23', '2026-11-23', 16000], [$november->period_start->toDateString(), $november->due_at->toDateString(), $november->total]);

        // À la livraison : pas d'avance possible
        $percent = Plan::create(['name' => 'À la livraison', 'pricing' => Plan::PERCENT, 'price_monthly' => 0, 'percent_rate' => 100, 'features' => []]);
        $this->company->forceFill(['plan_id' => $percent->id, 'trial_ends_at' => null])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-27 01:30', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->assertSame(0, PlatformInvoice::query()->where('period', '2026-12')->count());
    }

    public function test_the_console_sets_the_grace_days_the_notice_and_the_orders_pack(): void
    {
        config(['platform.domain' => 'jibiat.test']);
        Sanctum::actingAs($this->root);
        $this->getJson('http://admin.jibiat.test/api/v1/console/settings')->assertOk()
            ->assertJsonPath('data.prepaid', ['warning_days' => 1, 'grace_days' => 3, 'notice_days' => 5, 'weekly_notice_days' => 2, 'orders_pack' => 100]);
        $this->putJson('http://admin.jibiat.test/api/v1/console/settings', ['prepaid' => ['grace_days' => 5, 'notice_days' => 7, 'orders_pack' => 50]])->assertOk()
            ->assertJsonPath('data.prepaid.grace_days', 5)->assertJsonPath('data.prepaid.orders_pack', 50)->assertJsonPath('data.prepaid.warning_days', 1);
        $this->putJson('http://admin.jibiat.test/api/v1/console/settings', ['prepaid' => ['grace_days' => -1]])->assertJsonValidationErrors('prepaid.grace_days');

        // Préavis de 7 jours : décembre facturé dès le 24
        $this->travelTo(CarbonImmutable::parse('2026-11-24 01:30', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $december = PlatformInvoice::query()->where('period', '2026-12')->sole();

        // Grâce de 5 jours : encore des courses le 5, plus le 6
        $billing = app(Billing::class);
        $this->travelTo(CarbonImmutable::parse('2026-12-05 09:00', 'Africa/Abidjan'));
        $this->assertFalse($billing->isRestricted($this->company->fresh()));
        $this->travelTo(CarbonImmutable::parse('2026-12-06 09:00', 'Africa/Abidjan'));
        $this->assertTrue($billing->isRestricted($this->company->fresh()));
        $this->pay($december);

        // Pack de 50 courses
        Sanctum::actingAs($this->admin);
        $orders = collect($this->getJson("http://{$this->company->slug}.jibiat.test/api/v1/subscription")->assertOk()->json('data.prepaid.purchases'))->firstWhere('kind', 'orders');
        $this->assertSame([50, 1250], [$orders['quantity'], $orders['total']]);
    }
}
