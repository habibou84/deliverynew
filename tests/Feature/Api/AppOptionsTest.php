<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\AppOption;
use App\Models\Plan;
use App\Models\User;
use App\Services\Platform\AppOptions;
use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Applications à leur nom dans les formules : incluses, en option (demandée par l'entreprise, acceptée par Jibiat,
 * facturée au prorata) ou non disponibles ; nouvelles versions bloquées sans ce droit.
 */
class AppOptionsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    private Plan $essential;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
        Notification::fake();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        $this->essential = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => null, 'overage_per_order' => 0, 'features' => [],
            'apps' => ['android' => 'option', 'android_price' => 10000, 'ios' => 'none'], 'position' => 1]);
        $this->pro = Plan::create(['name' => 'Pro', 'price_monthly' => 60000, 'included_orders' => null, 'overage_per_order' => 0, 'features' => [],
            'apps' => ['android' => 'included', 'ios' => 'option', 'ios_price' => 15000], 'position' => 2]);
        $this->company->forceFill(['plan_id' => $this->essential->id, 'created_at' => '2026-01-01 00:00:00', 'trial_ends_at' => null])->save();
    }

    public function test_option_is_requested_accepted_billed_then_stopped(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Africa/Abidjan'));

        // Mon abonnement : Android en option (incluse dans le Pro), iPhone non disponible dans l'Essentiel
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.apps.0.status', 'available')
            ->assertJsonPath('data.apps.0.price', 10000)
            ->assertJsonPath('data.apps.0.included_in.0.name', 'Pro')
            ->assertJsonPath('data.apps.1.status', 'unavailable')
            ->assertJsonPath('data.apps.1.option_in.0.name', 'Pro');
        $this->postJson('/api/v1/subscription/apps/android')->assertCreated()->assertJsonPath('data.0.status', 'pending');
        $this->postJson('/api/v1/subscription/apps/android')->assertJsonValidationErrors('platform');
        $this->postJson('/api/v1/subscription/apps/ios')->assertJsonValidationErrors('platform');
        // Réservé aux administrateurs de l'entreprise
        Sanctum::actingAs($this->courierA->user);
        $this->postJson('/api/v1/subscription/apps/android')->assertForbidden();

        // Console : pas de nouvelle version tant que l'option n'est pas acceptée
        Sanctum::actingAs($this->root);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}")->assertCreated()
            ->assertJsonPath('data.access.allowed', false)->assertJsonPath('data.access.status', 'pending');
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertStatus(422);
        $option = AppOption::sole();
        $this->getJson('/api/v1/console/app-options')->assertOk()->assertJsonPath('meta.pending', 1)
            ->assertJsonPath('data.0.company.name', $this->company->name)->assertJsonPath('data.0.price', 10000);
        $this->postJson("/api/v1/console/app-options/{$option->id}/accept")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/console/app-options/{$option->id}/accept")->assertStatus(422);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertCreated();
        $this->assertTrue(app(AppOptions::class)->allows($this->company->fresh()));

        // Mon abonnement : option active, estimation du mois entier (20 jours sur 30)
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.apps.0.status', 'active')
            ->assertJsonPath('data.estimate.app_options', 6667);

        // Arrêtée le 21 : facturée du 11 au 21 compris, plus de nouvelle version
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:00', 'Africa/Abidjan'));
        $this->deleteJson('/api/v1/subscription/apps/android')->assertOk()->assertJsonPath('data.0.status', 'available');
        Sanctum::actingAs($this->root);
        $this->postJson("/api/v1/console/branded-apps/{$this->company->id}/builds")->assertStatus(422);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00', 'Africa/Abidjan'));
        $invoice = app(Billing::class)->invoiceFor($this->company->fresh(), '2026-11');
        $this->assertSame([3667, 28667], [$invoice->app_options_amount, $invoice->total]);
        $this->assertSame(11, $invoice->app_options[0]['days']);
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/subscription/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.app_options_amount', 3667)
            ->assertJsonPath('data.app_options.0.label', 'Applications Android à votre nom');
    }

    public function test_included_in_the_plan_without_option_and_refused_requests(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Africa/Abidjan'));
        // Pro : Android incluse, sans option ni facture en plus
        $this->company->forceFill(['plan_id' => $this->pro->id])->save();
        $this->assertTrue(app(AppOptions::class)->allows($this->company->fresh()));
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.apps.0.status', 'included')
            ->assertJsonPath('data.apps.1.status', 'available')->assertJsonPath('data.apps.1.soon', true);
        $this->postJson('/api/v1/subscription/apps/android')->assertJsonValidationErrors(['platform' => 'déjà incluses']);

        // Sans option dans la formule : rien à demander
        $none = Plan::create(['name' => 'À la livraison', 'price_monthly' => 0, 'pricing' => Plan::PERCENT, 'percent_rate' => 100, 'features' => []]);
        $this->company->forceFill(['plan_id' => $none->id])->save();
        Sanctum::actingAs($this->admin->fresh());
        $this->postJson('/api/v1/subscription/apps/android')->assertJsonValidationErrors(['platform' => 'ne propose pas']);

        // Refus par la console : rien de facturé, nouvelle demande possible
        $this->company->forceFill(['plan_id' => $this->essential->id])->save();
        Sanctum::actingAs($this->admin->fresh());
        $this->postJson('/api/v1/subscription/apps/android')->assertCreated();
        Sanctum::actingAs($this->root);
        $option = AppOption::sole();
        $this->postJson("/api/v1/console/app-options/{$option->id}/refuse", ['note' => 'Logo manquant'])->assertOk()->assertJsonPath('data.status', 'refused');
        $this->assertSame(0, app(AppOptions::class)->billable($this->company->fresh(), $this->essential, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))['amount']);
        Sanctum::actingAs($this->admin->fresh());
        $this->postJson('/api/v1/subscription/apps/android')->assertCreated();

        // Décisions réservées au Propriétaire de la console
        Sanctum::actingAs(User::factory()->withRole(Role::PlatformSupport)->create(['company_id' => null]));
        $this->getJson('/api/v1/console/app-options')->assertOk();
        $this->postJson('/api/v1/console/app-options/'.AppOption::latest('id')->value('id').'/accept')->assertForbidden();
    }

    public function test_the_console_sets_apps_in_each_plan(): void
    {
        Sanctum::actingAs($this->root);
        $this->patchJson("/api/v1/console/plans/{$this->essential->id}", ['apps' => ['android' => 'option']])->assertJsonValidationErrors('apps.android_price');
        $this->patchJson("/api/v1/console/plans/{$this->essential->id}", ['apps' => ['android' => 'option', 'android_price' => 12000, 'ios' => 'included', 'ios_price' => 500]])->assertOk()
            ->assertJsonPath('data.apps', ['android' => 'option', 'android_price' => 12000, 'ios' => 'included', 'ios_price' => null]);
        $this->getJson('/api/v1/console/plans')->assertJsonPath('meta.app_platforms.1.soon', true);

        $this->assertSame(['Applications Android à votre nom en option (12 000 F / mois)', 'Applications iPhone à votre nom (bientôt) incluses'], AppOptions::summaryLines($this->essential->fresh()));
    }
}
