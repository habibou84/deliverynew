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
 * Jibiat IA : abonnement à part, en plus de la formule. Essai une fois, abonnement au mois ou à l'année, arrêt en fin
 * de mois, consommation comptée et pause au plafond, recharges, lignes sur la facture de la plateforme, console.
 */
class JibiatAiTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Africa/Abidjan'));
        $this->buildWorld();
        $plan = Plan::create(['name' => 'Pro', 'price_monthly' => 60000, 'included_orders' => 2000, 'overage_per_order' => 25, 'max_couriers' => null, 'features' => []]);
        $this->company->forceFill(['plan_id' => $plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
    }

    private function orderOn(string $date): Order
    {
        $order = $this->createOrder();
        $at = CarbonImmutable::parse("{$date} 12:00", 'Africa/Abidjan')->utc();
        $order->forceFill(['created_at' => $at, 'picked_up_at' => $at, 'status' => OrderStatus::PickedUp])->save();

        return $order;
    }

    public function test_the_trial_is_free_once_then_the_subscription_stops_at_the_end_of_the_month(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/ai')->assertOk()
            ->assertJsonPath('data.plan.name', 'Pro')->assertJsonPath('data.subscription.on', false)->assertJsonCount(3, 'data.tiers');

        $this->postJson('/api/v1/company/ai/trial', ['tier' => 'pro'])->assertOk()
            ->assertJsonPath('data.subscription.status', 'trial')->assertJsonPath('data.subscription.trial_days_left', 14);
        $this->assertTrue(app(JibiatAi::class)->allows($this->company->fresh(), 'messages'));
        // Fonction absente du niveau Essentiel, présente en Pro
        $this->assertTrue(app(JibiatAi::class)->allows($this->company->fresh(), 'forecast'));

        // Un seul essai
        $this->postJson('/api/v1/company/ai/stop')->assertOk()->assertJsonPath('data.subscription.on', false);
        $this->postJson('/api/v1/company/ai/trial', ['tier' => 'pro'])->assertUnprocessable();

        $this->postJson('/api/v1/company/ai/subscribe', ['tier' => 'essentiel', 'period' => 'monthly'])->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.subscription.tier_name', 'IA Essentiel');
        $this->assertFalse(app(JibiatAi::class)->allows($this->company->fresh(), 'forecast'));

        // Arrêt : l'IA tourne jusqu'à la fin du mois, puis s'arrête
        $stop = $this->postJson('/api/v1/company/ai/stop')->assertOk()->json('data.subscription');
        $this->assertTrue($stop['on']);
        $this->assertSame('2026-10-31', CarbonImmutable::parse($stop['stop_at'])->setTimezone('Africa/Abidjan')->toDateString());
        $this->postJson('/api/v1/company/ai/resume')->assertOk()->assertJsonPath('data.subscription.stop_at', null);
        $this->postJson('/api/v1/company/ai/stop')->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:30', 'Africa/Abidjan'));
        $this->artisan('ai:review')->assertSuccessful();
        $this->assertSame(JibiatAi::STOPPED, $this->company->fresh()->ai_status);
        $this->assertFalse(app(JibiatAi::class)->allows($this->company->fresh(), 'messages'));

        // Les autres membres de l'équipe ne gèrent pas l'abonnement
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/company/ai/subscribe', ['tier' => 'pro', 'period' => 'monthly'])->assertForbidden();
    }

    public function test_every_call_is_counted_and_the_ai_pauses_at_the_cost_cap_until_a_recharge(): void
    {
        Notification::fake();
        $ai = app(JibiatAi::class);
        $ai->subscribe($this->company, 'essentiel', JibiatAi::MONTHLY);

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

    public function test_the_platform_invoice_bills_the_days_the_overage_and_the_recharges(): void
    {
        Notification::fake();
        $ai = app(JibiatAi::class);
        $ai->updateTiers(['essentiel' => ['orders' => 2]]);
        // Essai du 1er au 9 : pas facturé ; abonnement à partir du 10 (22 jours sur 31)
        $ai->startTrial($this->company, 'essentiel');
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00', 'Africa/Abidjan'));
        $ai->subscribe($this->company->fresh(), 'essentiel', JibiatAi::MONTHLY);
        foreach (['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'] as $day) {
            $this->orderOn($day);
        }
        $ai->recharge($this->company->fresh(), 5000, $this->admin);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $invoice = PlatformInvoice::query()->sole();

        // 15 000 × 22 / 31 ; la recharge de 5 000 F couvre 625 courses : aucune course en plus
        $this->assertSame([10645, 5000], array_column($invoice->ai_lines, 'amount'));
        $this->assertSame(15645, $invoice->ai_amount);
        $this->assertSame(60000 + 15645, $invoice->total);
        $this->assertSame($invoice->id, AiRecharge::query()->sole()->platform_invoice_id);
        $this->assertSame(15645, $this->actingAs($this->admin)->getJson("/api/v1/subscription/invoices/{$invoice->id}")->json('data.ai_amount'));
    }

    public function test_courses_beyond_the_volume_and_the_yearly_payment(): void
    {
        $ai = app(JibiatAi::class);
        $ai->updateTiers(['pro' => ['orders' => 2]]);
        $ai->subscribe($this->company, 'pro', JibiatAi::YEARLY);
        foreach (['2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'] as $day) {
            $this->orderOn($day);
        }
        [, $first, $last] = Billing::bounds('2026-10', 'Africa/Abidjan');
        $lines = collect($ai->billable($this->company->fresh(), $first, $last)['lines']);

        // L'année payée d'un coup (10 mois sur 12), et 3 courses au-delà de 2 à 8 F
        $this->assertSame(450000, $lines->firstWhere('kind', 'subscription')['amount']);
        $this->assertSame(24, $lines->firstWhere('kind', 'overage')['amount']);
        // Le mois suivant : pas de nouvelle ligne d'abonnement
        [, $first, $last] = Billing::bounds('2026-11', 'Africa/Abidjan');
        $this->assertNull(collect($ai->billable($this->company->fresh(), $first, $last)['lines'])->firstWhere('kind', 'subscription'));
        // Changer de niveau en cours d'année : par l'équipe Jibiat
        $this->assertThrows(fn () => $ai->subscribe($this->company->fresh(), 'essentiel', JibiatAi::MONTHLY));
    }

    public function test_alerts_at_80_percent_and_at_the_end_of_the_trial(): void
    {
        Notification::fake();
        $ai = app(JibiatAi::class);
        $ai->updateTiers(['essentiel' => ['orders' => 5]]);
        $ai->startTrial($this->company, 'essentiel');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Africa/Abidjan'));
        $this->artisan('ai:review')->assertSuccessful();
        Notification::assertSentTo($this->admin, JibiatAiNotice::class, fn ($n) => $n->stage === 'trial_ending');

        $this->travelTo(CarbonImmutable::parse('2026-10-16 08:00', 'Africa/Abidjan'));
        $this->artisan('ai:review')->assertSuccessful();
        $this->assertSame(JibiatAi::EXPIRED, $this->company->fresh()->ai_status);

        $ai->subscribe($this->company->fresh(), 'essentiel', JibiatAi::MONTHLY);
        foreach (['2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20'] as $day) {
            $this->orderOn($day);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00', 'Africa/Abidjan'));
        $this->artisan('ai:review')->assertSuccessful();
        $this->artisan('ai:review')->assertSuccessful();
        Notification::assertSentToTimes($this->admin, JibiatAiNotice::class, 3);
        Notification::assertSentTo($this->admin, JibiatAiNotice::class, fn ($n) => $n->stage === 'volume_80');
    }

    public function test_the_console_sets_the_tiers_and_sees_cost_and_margin(): void
    {
        config(['platform.domain' => 'jibiat.test']);
        $ai = app(JibiatAi::class);
        $ai->subscribe($this->company, 'pro', JibiatAi::MONTHLY);
        $ai->record($this->company->fresh(), 'messages', 'claude-sonnet-5-5', 1_000_000, 100_000);

        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
        Sanctum::actingAs($root);
        $data = $this->getJson('http://admin.jibiat.test/api/v1/console/ai')->assertOk()->json('data');
        $row = collect($data['companies'])->firstWhere('id', $this->company->id);
        // 1 M jetons lus et 100 000 écrits en Sonnet : 3 $ ≈ 1 800 F
        $this->assertSame([1800, 45000], [$row['cost'], $row['paid']]);
        $this->assertSame(45000 - 1800, $row['margin']);
        $this->assertSame('entreprise', $row['next']);

        $this->putJson('http://admin.jibiat.test/api/v1/console/ai', ['tiers' => ['pro' => ['price' => 50000, 'cost_cap' => 40000]], 'settings' => ['overage_per_order' => 10]])->assertOk()
            ->assertJsonPath('data.tiers.pro.price', 50000)->assertJsonPath('data.settings.overage_per_order', 10);
    }
}
