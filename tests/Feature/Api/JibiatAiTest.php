<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\AiRecharge;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\User;
use App\Notifications\JibiatAiNotice;
use App\Services\Ai\JibiatAi;
use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Jibiat IA comprise dans la formule : niveau de la formule, plus d'essai ni d'abonnement à part, consommation comptée
 * et pause au plafond, recharges, courses au-delà du volume sur la facture (au mois et à la semaine), alertes, console.
 * Les anciens abonnements à part continuent d'être facturés jusqu'à leur arrêt.
 */
class JibiatAiTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Africa/Abidjan'));
        $this->buildWorld();
        $this->plan = Plan::create(['name' => 'Pro', 'price_monthly' => 60000, 'included_orders' => 2000, 'overage_per_order' => 25, 'max_couriers' => null, 'features' => [], 'ai_tier' => 'pro']);
        $this->company->forceFill(['plan_id' => $this->plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
    }

    private Plan $plan;

    /** Formule sans IA (pour les anciens abonnements à part) */
    private function withoutAiInPlan(): void
    {
        $this->plan->update(['ai_tier' => null]);
        $this->company->unsetRelation('plan');
    }

    /** Ancien abonnement à part, pris avant que l'IA soit dans les formules */
    private function legacySubscription(string $tier, string $period, string $start): void
    {
        $this->company->forceFill([
            'ai_tier' => $tier, 'ai_status' => JibiatAi::ACTIVE, 'ai_period' => $period, 'ai_started_at' => CarbonImmutable::parse($start, 'Africa/Abidjan')->utc(),
            'ai_paid_until' => $period === JibiatAi::YEARLY ? CarbonImmutable::parse($start)->addYear()->subDay()->toDateString() : null,
        ])->save();
    }

    private function orderOn(string $date): Order
    {
        $order = $this->createOrder();
        $at = CarbonImmutable::parse("{$date} 12:00", 'Africa/Abidjan')->utc();
        $order->forceFill(['created_at' => $at, 'picked_up_at' => $at, 'status' => OrderStatus::PickedUp])->save();

        return $order;
    }

    public function test_the_ai_comes_with_the_plan_and_cannot_be_bought_apart(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/ai')->assertOk()
            ->assertJsonPath('data.plan.name', 'Pro')->assertJsonPath('data.subscription.on', true)
            ->assertJsonPath('data.subscription.included', true)->assertJsonPath('data.subscription.tier', 'pro')
            ->assertJsonPath('data.subscription.tier_name', 'IA Pro')->assertJsonCount(3, 'data.tiers')
            ->assertJsonPath('data.plans_with_ai.0.name', 'Pro');
        $ai = app(JibiatAi::class);
        $this->assertTrue($ai->allows($this->company->fresh(), 'messages'));
        $this->assertTrue($ai->allows($this->company->fresh(), 'forecast'));

        // Plus d'essai ni d'abonnement à part, pas d'arrêt : l'IA vient avec la formule
        $this->postJson('/api/v1/company/ai/trial', ['tier' => 'pro'])->assertUnprocessable();
        $this->postJson('/api/v1/company/ai/subscribe', ['tier' => 'essentiel', 'period' => 'monthly'])->assertUnprocessable();
        $this->postJson('/api/v1/company/ai/stop')->assertUnprocessable();

        // Formule sans IA : pas d'IA, et les formules qui la comprennent sont proposées
        $other = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => 500, 'overage_per_order' => 30, 'features' => []]);
        $this->company->forceFill(['plan_id' => $other->id])->save();
        Sanctum::actingAs($this->admin->fresh());
        $this->getJson('/api/v1/company/ai')->assertOk()
            ->assertJsonPath('data.subscription.on', false)->assertJsonPath('data.subscription.included', false)
            ->assertJsonPath('data.plans_with_ai.0.tier_name', 'IA Pro');
        $this->assertFalse($ai->allows($this->company->fresh(), 'messages'));

        // Les autres membres de l'équipe ne gèrent pas l'IA
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/company/ai/recharge', ['amount' => 5000])->assertForbidden();
    }

    public function test_every_call_is_counted_and_the_ai_pauses_at_the_cost_cap_until_a_recharge(): void
    {
        Notification::fake();
        $this->plan->update(['ai_tier' => 'essentiel']);
        $ai = app(JibiatAi::class);

        // Haiku : 2 000 jetons lus, 300 écrits ≈ 0,18 F
        $ai->record($this->company->fresh(), 'messages', 'claude-haiku-5-5', 2000, 300);
        $this->assertSame(0, $ai->costThisMonth($this->company));
        $this->assertTrue($ai->allows($this->company->fresh(), 'messages'));

        // Sonnet, très gros appels : plus de 12 000 F → pause et alerte
        $ai->record($this->company->fresh(), 'dispatch', 'claude-sonnet-5-5', 10_000_000, 500_000);
        $company = $this->company->fresh();
        $this->assertGreaterThanOrEqual(12000, $ai->costThisMonth($company));
        $this->assertTrue($ai->isPaused($company));
        $this->assertFalse($ai->allows($company, 'messages'));
        Notification::assertSentTo($this->admin, JibiatAiNotice::class, fn ($n) => $n->stage === 'paused');

        // Une recharge relève le plafond et les courses comprises du mois
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/company/ai/recharge', ['amount' => 20000])->assertOk()
            ->assertJsonPath('data.subscription.paused', false)->assertJsonPath('data.usage.included_orders', 1500 + 2500)
            ->assertJsonPath('data.usage.features.1.calls', 1);
        $this->postJson('/api/v1/company/ai/recharge', ['amount' => 1234])->assertUnprocessable();
    }

    public function test_the_platform_invoice_bills_only_the_overage_and_the_recharges_when_the_ai_is_in_the_plan(): void
    {
        Notification::fake();
        $ai = app(JibiatAi::class);
        $ai->updateTiers(['pro' => ['orders' => 2]]);
        foreach (['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $day) {
            $this->orderOn($day);
        }
        $ai->recharge($this->company->fresh(), 5000, $this->admin);
        // Ancien abonnement à part encore en cours : plus facturé, l'IA est dans la formule
        $this->legacySubscription('essentiel', JibiatAi::MONTHLY, '2026-09-01');

        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $invoice = PlatformInvoice::query()->sole();

        // Pas de ligne d'abonnement ; la recharge de 5 000 F couvre 625 courses : aucune course en plus
        $this->assertSame(['recharge'], array_column($invoice->ai_lines, 'kind'));
        $this->assertSame(5000, $invoice->ai_amount);
        $this->assertSame(60000 + 5000, $invoice->total);
        $this->assertSame($invoice->id, AiRecharge::query()->sole()->platform_invoice_id);
        $this->assertSame(5000, $this->actingAs($this->admin)->getJson("/api/v1/subscription/invoices/{$invoice->id}")->json('data.ai_amount'));
    }

    public function test_courses_beyond_the_volume_on_the_month_and_on_the_week_where_the_month_ends(): void
    {
        $ai = app(JibiatAi::class);
        $ai->updateTiers(['pro' => ['orders' => 2]]);
        foreach (['2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'] as $day) {
            $this->orderOn($day);
        }
        // Au mois : 3 courses au-delà de 2, à 8 F
        [, $first, $last] = Billing::bounds('2026-10', 'Africa/Abidjan');
        $lines = collect($ai->billable($this->company->fresh(), $first, $last)['lines']);
        $this->assertNull($lines->firstWhere('kind', 'subscription'));
        $this->assertSame(24, $lines->firstWhere('kind', 'overage')['amount']);

        // À la semaine : seulement sur la semaine où octobre se termine (du 26/10 au 01/11)
        $this->company->forceFill(['billing_cycle' => PlatformInvoice::WEEKLY])->save();
        [, $first, $last] = Billing::bounds('2026-W42', 'Africa/Abidjan');
        $this->assertSame([], $ai->billable($this->company->fresh(), $first, $last, PlatformInvoice::WEEKLY)['lines']);
        [, $first, $last] = Billing::bounds('2026-W44', 'Africa/Abidjan');
        $lines = collect($ai->billable($this->company->fresh(), $first, $last, PlatformInvoice::WEEKLY)['lines']);
        $this->assertSame(24, $lines->firstWhere('kind', 'overage')['amount']);
    }

    public function test_a_legacy_subscription_apart_is_still_billed_until_it_stops(): void
    {
        Notification::fake();
        $this->withoutAiInPlan();
        $ai = app(JibiatAi::class);
        $ai->updateTiers(['essentiel' => ['orders' => 2]]);
        // Abonnement au mois pris le 10 (22 jours sur 31)
        $this->legacySubscription('essentiel', JibiatAi::MONTHLY, '2026-10-10');
        foreach (['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'] as $day) {
            $this->orderOn($day);
        }
        $ai->recharge($this->company->fresh(), 5000, $this->admin);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/ai')->assertOk()->assertJsonPath('data.subscription.on', true)->assertJsonPath('data.subscription.included', false);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $invoice = PlatformInvoice::query()->sole();
        // 15 000 × 22 / 31 ; la recharge de 5 000 F couvre 625 courses : aucune course en plus
        $this->assertSame([10645, 5000], array_column($invoice->ai_lines, 'amount'));

        // Arrêt en fin de mois, comme avant
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Africa/Abidjan'));
        $stop = $this->postJson('/api/v1/company/ai/stop')->assertOk()->json('data.subscription');
        $this->assertSame('2026-11-30', CarbonImmutable::parse($stop['stop_at'])->setTimezone('Africa/Abidjan')->toDateString());
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:30', 'Africa/Abidjan'));
        $this->artisan('ai:review')->assertSuccessful();
        $this->assertSame(JibiatAi::STOPPED, $this->company->fresh()->ai_status);
        $this->assertFalse($ai->allows($this->company->fresh(), 'messages'));

        // Un ancien abonnement à l'année : l'année payée d'un coup (10 mois sur 12)
        $this->legacySubscription('pro', JibiatAi::YEARLY, '2026-12-01');
        $this->company->forceFill(['ai_stop_at' => null])->save();
        [, $first, $last] = Billing::bounds('2026-12', 'Africa/Abidjan');
        $this->assertSame(450000, collect($ai->billable($this->company->fresh(), $first, $last)['lines'])->firstWhere('kind', 'subscription')['amount']);
    }

    public function test_alerts_at_80_percent_of_the_volume(): void
    {
        Notification::fake();
        $ai = app(JibiatAi::class);
        $ai->updateTiers(['pro' => ['orders' => 5]]);
        foreach (['2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20'] as $day) {
            $this->orderOn($day);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00', 'Africa/Abidjan'));
        $this->artisan('ai:review')->assertSuccessful();
        $this->artisan('ai:review')->assertSuccessful();
        Notification::assertSentToTimes($this->admin, JibiatAiNotice::class, 1);
        Notification::assertSentTo($this->admin, JibiatAiNotice::class, fn ($n) => $n->stage === 'volume_80');
    }

    public function test_the_console_puts_a_tier_in_a_plan_and_sees_cost_and_margin(): void
    {
        config(['platform.domain' => 'jibiat.test']);
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
        Sanctum::actingAs($root);

        // Formules : niveau d'IA choisi par la console
        $this->getJson('http://admin.jibiat.test/api/v1/console/plans')->assertOk()
            ->assertJsonPath('data.0.ai_tier', 'pro')->assertJsonPath('meta.ai_tiers.0.key', 'essentiel');
        $this->patchJson("http://admin.jibiat.test/api/v1/console/plans/{$this->plan->id}", ['ai_tier' => 'entreprise'])->assertOk()->assertJsonPath('data.ai_tier', 'entreprise');
        $this->patchJson("http://admin.jibiat.test/api/v1/console/plans/{$this->plan->id}", ['ai_tier' => 'inconnu'])->assertUnprocessable();
        $this->patchJson("http://admin.jibiat.test/api/v1/console/plans/{$this->plan->id}", ['ai_tier' => 'pro'])->assertOk();

        app(JibiatAi::class)->record($this->company->fresh(), 'messages', 'claude-sonnet-5-5', 1_000_000, 100_000);
        $data = $this->getJson('http://admin.jibiat.test/api/v1/console/ai')->assertOk()->json('data');
        $row = collect($data['companies'])->firstWhere('id', $this->company->id);
        // 1 M jetons lus et 100 000 écrits en Sonnet : 3 $ ≈ 1 800 F ; rien de payé à part (dans la formule)
        $this->assertTrue($row['included']);
        $this->assertSame([1800, 0], [$row['cost'], $row['paid']]);
        $this->assertSame('entreprise', $row['next']);

        $this->putJson('http://admin.jibiat.test/api/v1/console/ai', ['tiers' => ['pro' => ['price' => 50000, 'cost_cap' => 40000]], 'settings' => ['overage_per_order' => 10]])->assertOk()
            ->assertJsonPath('data.tiers.pro.price', 50000)->assertJsonPath('data.settings.overage_per_order', 10);
    }
}
