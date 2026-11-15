<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
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
 * Jibiat IA en points : points du mois et plafond de coût dans la formule, 1 point par commande écrite (réglable),
 * vérification avant chaque appel (interrupteur, coupure par entreprise, points, plafond), alertes, rien sur la
 * facture, console. Les anciens abonnements à part sont encore facturés jusqu'à leur arrêt.
 */
class JibiatAiTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Africa/Abidjan'));
        $this->buildWorld();
        $this->plan = Plan::create(['name' => 'Pro', 'price_monthly' => 60000, 'included_orders' => 2000, 'overage_per_order' => 25, 'max_couriers' => null, 'features' => [], 'ai_points' => 10, 'ai_cost_cap' => 5000]);
        $this->company->forceFill(['plan_id' => $this->plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
    }

    /** Une commande écrite lue par Haiku (environ 0,3 F) */
    private function readMessage(): void
    {
        app(JibiatAi::class)->record($this->company->fresh(), 'messages', 'claude-haiku-5-5', 3000, 300);
    }

    public function test_the_company_sees_its_points_and_each_written_order_burns_one(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/ai')->assertOk()
            ->assertJsonPath('data.on', true)->assertJsonPath('data.points.monthly', 10)->assertJsonPath('data.points.left', 10)
            ->assertJsonPath('data.prices.0.points', 1)->assertJsonPath('data.prices.1.points', 2)
            ->assertJsonMissingPath('data.cost_cap');

        $this->readMessage();
        $this->readMessage();
        $this->getJson('/api/v1/company/ai')->assertOk()
            ->assertJsonPath('data.points.used', 2)->assertJsonPath('data.points.left', 8)
            ->assertJsonPath('data.usage.0.calls', 2)->assertJsonPath('data.usage.0.points', 2);

        // Plus d'essai, d'abonnement à part ni de recharge
        $this->postJson('/api/v1/company/ai/trial', ['tier' => 'pro'])->assertNotFound();
        $this->postJson('/api/v1/company/ai/recharge', ['amount' => 5000])->assertNotFound();
        $this->postJson('/api/v1/company/ai/stop')->assertUnprocessable();

        // Les autres membres de l'équipe ne voient pas ce bloc
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/company/ai')->assertForbidden();
    }

    public function test_the_ai_stops_when_the_points_are_used_up_and_comes_back_next_month(): void
    {
        Notification::fake();
        $ai = app(JibiatAi::class);
        for ($i = 0; $i < 8; $i++) {
            $this->readMessage();
        }
        Notification::assertSentTo($this->admin, JibiatAiNotice::class, fn ($n) => $n->stage === 'points_80');
        $this->assertTrue($ai->allows($this->company->fresh(), 'messages'));
        // Une photo coûte 2 points : il en reste 2
        $this->assertTrue($ai->allows($this->company->fresh(), 'photos'));

        $this->readMessage();
        $this->readMessage();
        Notification::assertSentTo($this->admin, JibiatAiNotice::class, fn ($n) => $n->stage === 'points_100');
        $this->assertFalse($ai->allows($this->company->fresh(), 'messages'));
        Notification::assertSentToTimes($this->admin, JibiatAiNotice::class, 2);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Abidjan'));
        $this->assertTrue($ai->allows($this->company->fresh(), 'messages'));
        $this->assertSame(10, $ai->pointsLeft($this->company->fresh()));
    }

    public function test_the_cost_cap_pauses_the_ai_even_with_points_left(): void
    {
        Notification::fake();
        $ai = app(JibiatAi::class);
        $this->plan->update(['ai_points' => 1000]);
        // Opus, très gros appel : plus de 5 000 F de coût réel → pause
        $ai->record($this->company->fresh(), 'messages', 'claude-opus-5-5', 2_000_000, 100_000);
        $company = $this->company->fresh();
        $this->assertTrue($ai->isPaused($company));
        $this->assertSame(999, $ai->pointsLeft($company));
        $this->assertFalse($ai->allows($company, 'messages'));
        Notification::assertSentTo($this->admin, JibiatAiNotice::class, fn ($n) => $n->stage === 'paused');
    }

    public function test_the_console_switches_the_ai_off_for_everyone_or_one_company(): void
    {
        $ai = app(JibiatAi::class);
        config(['platform.domain' => 'jibiat.test']);
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
        Sanctum::actingAs($root);
        $this->readMessage();

        $data = $this->getJson('http://admin.jibiat.test/api/v1/console/ai')->assertOk()->json('data');
        $row = collect($data['companies'])->firstWhere('id', $this->company->id);
        $this->assertSame([10, 1, 5000, true], [$row['points'], $row['used'], $row['cost_cap'], $row['on']]);

        // Coupée pour une entreprise
        $this->putJson("http://admin.jibiat.test/api/v1/console/ai/companies/{$this->company->id}", ['disabled' => true])->assertOk();
        $this->assertFalse($ai->allows($this->company->fresh(), 'messages'));
        $this->putJson("http://admin.jibiat.test/api/v1/console/ai/companies/{$this->company->id}", ['disabled' => false])->assertOk();
        $this->assertTrue($ai->allows($this->company->fresh(), 'messages'));

        // Éteinte pour tout le monde ; prix des actions en points
        $this->putJson('http://admin.jibiat.test/api/v1/console/ai', ['settings' => ['enabled' => false, 'points_photo' => 3]])->assertOk()
            ->assertJsonPath('data.settings.enabled', 0)->assertJsonPath('data.settings.points_photo', 3);
        $this->assertFalse($ai->allows($this->company->fresh(), 'messages'));

        // Formules : points et plafond
        $this->patchJson("http://admin.jibiat.test/api/v1/console/plans/{$this->plan->id}", ['ai_points' => 1500, 'ai_cost_cap' => 15000])->assertOk()
            ->assertJsonPath('data.ai_points', 1500)->assertJsonPath('data.ai_cost_cap', 15000);
        $this->getJson('http://admin.jibiat.test/api/v1/console/plans')->assertOk()->assertJsonPath('meta.ai_prices.0.key', 'messages');
    }

    public function test_a_plan_without_points_has_no_ai_and_nothing_ai_is_on_the_invoice(): void
    {
        $ai = app(JibiatAi::class);
        $this->readMessage();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->assertSuccessful();
        $invoice = PlatformInvoice::query()->sole();
        $this->assertSame(0, $invoice->ai_amount);
        $this->assertSame(60000, $invoice->total);

        $this->plan->update(['ai_points' => null]);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/ai')->assertOk()->assertJsonPath('data.on', false)->assertJsonPath('data.points.monthly', 0);
        $this->assertFalse($ai->allows($this->company->fresh(), 'messages'));
    }

    public function test_a_legacy_subscription_apart_is_still_billed_and_gives_its_volume_in_points(): void
    {
        $ai = app(JibiatAi::class);
        $this->plan->update(['ai_points' => null, 'ai_cost_cap' => null]);
        $this->company->forceFill([
            'ai_tier' => 'essentiel', 'ai_status' => JibiatAi::ACTIVE, 'ai_period' => JibiatAi::MONTHLY,
            'ai_started_at' => CarbonImmutable::parse('2026-10-10', 'Africa/Abidjan')->utc(),
        ])->save();
        $company = $this->company->fresh();
        $this->assertSame(1500, $ai->monthlyPoints($company));
        $this->assertSame(12000, $ai->costCap($company));

        // 15 000 × 22 / 31
        [, $first, $last] = Billing::bounds('2026-10', 'Africa/Abidjan');
        $this->assertSame([10645], array_column($ai->billable($company, $first, $last)['lines'], 'amount'));

        // Arrêt en fin de mois, puis plus de points
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/company/ai/stop')->assertOk()->assertJsonPath('data.legacy.name', 'IA Essentiel');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:30', 'Africa/Abidjan'));
        $this->artisan('ai:review')->assertSuccessful();
        $this->assertSame(JibiatAi::STOPPED, $this->company->fresh()->ai_status);
        $this->assertSame(0, $ai->monthlyPoints($this->company->fresh()));
    }
}
