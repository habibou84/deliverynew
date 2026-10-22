<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
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
 * Formule « à la livraison » : pourcentage des frais de chaque course prise en charge, minimum et maximum par course,
 * taux réduit au-delà d'un volume, plafond, petites factures reportées, comparaison des formules.
 */
class PercentPlanTest extends TestCase
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
            'name' => 'À la livraison', 'pricing' => Plan::PERCENT, 'price_monthly' => 0, 'included_orders' => null,
            'overage_per_order' => 0, 'features' => [], 'percent_rate' => 100, 'percent_min' => 10, 'percent_max' => 60,
        ]);
        $this->company->forceFill(['plan_id' => $this->plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
    }

    // Course ramassée ce jour-là, avec ses frais et suppléments
    private function orderOn(string $date, int $fee, int $surcharges = 0): void
    {
        $at = CarbonImmutable::parse("{$date} 12:00", 'Africa/Abidjan')->utc();
        $this->createOrder()->forceFill([
            'created_at' => $at, 'picked_up_at' => $at, 'status' => OrderStatus::PickedUp,
            'delivery_fee' => $fee, 'surcharges_total' => $surcharges,
        ])->save();
    }

    private function invoice(string $period): ?PlatformInvoice
    {
        return app(Billing::class)->invoiceFor($this->company->fresh(), $period);
    }

    public function test_each_course_pays_a_percentage_between_the_minimum_and_the_maximum(): void
    {
        Notification::fake();
        $this->orderOn('2026-10-02', 500);          // 5 F → minimum 10 F
        $this->orderOn('2026-10-03', 1500, 500);    // 1 % de 2 000 F (frais et suppléments) → 20 F
        $this->orderOn('2026-10-04', 10000);        // 100 F → maximum 60 F
        $this->createOrder()->forceFill(['status' => OrderStatus::Cancelled, 'delivery_fee' => 9000])->save();

        $invoice = $this->invoice('2026-10');

        $this->assertSame([PlatformInvoice::OPEN, 'percent', 3, 0, 0, 90, false, 90], [
            $invoice->status, $invoice->pricing, $invoice->orders_count, $invoice->base_amount,
            $invoice->overage_amount, $invoice->deliveries_amount, $invoice->cap_applied, $invoice->total,
        ]);
        Notification::assertSentTimes(PlatformBillingNotice::class, 1);
    }

    public function test_lower_rate_beyond_the_volume_and_monthly_cap(): void
    {
        $this->plan->update(['volume_threshold' => 2, 'volume_rate' => 50, 'percent_max' => null]);
        foreach (['2026-10-02', '2026-10-03', '2026-10-04'] as $day) {
            $this->orderOn($day, 4000);
        }
        // Deux courses à 1 % (40 F), la troisième à 0,5 % (20 F)
        $amounts = app(Billing::class)->amounts($this->plan, $this->company, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01'), 1.0);
        $this->assertSame([100, false], [$amounts['deliveries_amount'], $amounts['cap_applied']]);

        $this->plan->update(['cap_amount' => 70]);
        $invoice = $this->invoice('2026-10');
        $this->assertSame([70, true, 70], [$invoice->deliveries_amount, $invoice->cap_applied, $invoice->total]);
    }

    public function test_small_invoices_are_carried_to_the_next_one(): void
    {
        Notification::fake();
        $this->plan->update(['min_invoice' => 1000, 'percent_max' => null]);
        $this->orderOn('2026-10-02', 2000);          // 20 F : trop petit
        $october = $this->invoice('2026-10');
        $this->assertSame([PlatformInvoice::CARRIED, 20], [$october->status, $october->total]);
        Notification::assertNotSentTo($this->admin, PlatformBillingNotice::class);

        // Pas d'impayé : la facture reportée n'est pas à payer
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.billing.amount_due', 0);

        $this->orderOn('2026-11-03', 100000);        // 1 000 F
        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00', 'Africa/Abidjan'));
        $november = $this->invoice('2026-11');
        $this->assertSame([PlatformInvoice::OPEN, 1000, 20, 1020], [$november->status, $november->deliveries_amount, $november->carried_amount, $november->total]);
        $this->assertSame($november->id, $october->fresh()->carried_into_id);
        Notification::assertSentTimes(PlatformBillingNotice::class, 1);

        // Le montant reporté n'est repris qu'une fois
        $this->assertSame(0, PlatformInvoice::query()->where('status', PlatformInvoice::CARRIED)->whereNull('carried_into_id')->count());
        $this->getJson("/api/v1/subscription/invoices/{$october->id}")->assertOk()
            ->assertJsonPath('data.status', 'carried')
            ->assertJsonPath('data.carried_into', $november->number);
    }

    public function test_subscription_shows_the_estimate_and_compares_the_plans(): void
    {
        Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => 500, 'overage_per_order' => 30, 'features' => [], 'position' => 1]);
        $this->orderOn('2026-10-02', 2000);
        $this->orderOn('2026-10-03', 3000);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.plan.pricing', 'percent')
            ->assertJsonPath('data.plan.percent_rate', 100)
            ->assertJsonPath('data.estimate.deliveries', 50)
            ->assertJsonPath('data.estimate.total', 50)
            ->assertJsonPath('data.comparison.0.name', 'À la livraison')
            ->assertJsonPath('data.comparison.0.amount', 50)
            ->assertJsonPath('data.comparison.0.current', true)
            ->assertJsonPath('data.comparison.1.amount', 25000);
    }

    public function test_console_creates_a_percent_plan(): void
    {
        Sanctum::actingAs($this->root);
        $this->postJson('/api/v1/console/plans', ['name' => 'Livraison 2', 'pricing' => 'percent', 'price_monthly' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('percent_rate');
        $this->postJson('/api/v1/console/plans', ['name' => 'Livraison 2', 'pricing' => 'percent', 'price_monthly' => 0, 'percent_rate' => 100,
            'percent_min' => 60, 'percent_max' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('percent_max');

        $this->postJson('/api/v1/console/plans', [
            'name' => 'Livraison 2', 'pricing' => 'percent', 'price_monthly' => 0, 'percent_rate' => 150, 'percent_min' => 10,
            'percent_max' => 60, 'volume_threshold' => 1000, 'volume_rate' => 75, 'cap_amount' => 60000, 'min_invoice' => 1000,
        ])->assertCreated()
            ->assertJsonPath('data.pricing', 'percent')
            ->assertJsonPath('data.percent_rate', 150)
            ->assertJsonPath('data.cap_amount', 60000);
        $this->assertSame('1,5 % des frais de chaque course (de 10 F à 60 F), 0,75 % au-delà de 1000 courses, plafonné à 60 000 F par mois',
            Plan::query()->where('name', 'Livraison 2')->sole()->percentSummary());
    }
}
