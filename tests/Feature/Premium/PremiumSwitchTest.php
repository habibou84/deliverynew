<?php

namespace Tests\Feature\Premium;

use App\Models\PremiumLevel;
use App\Services\Premium\PremiumCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Programme Premium éteint par défaut : rallumé depuis la console ; éteint, le menu disparaît pour l'entreprise (sauf
 * si elle a déjà des niveaux à gérer).
 */
class PremiumSwitchTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['services.jibiat_marchand.premium_secret' => 'secret-de-test']);
    }

    public function test_the_program_is_off_by_default_and_the_console_switches_it_on(): void
    {
        $premium = app(PremiumCards::class);
        $this->assertFalse($premium->settings()['enabled']);
        $this->assertFalse($premium->active());

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.company.premium_program', false);
        $this->getJson('/api/v1/premium')->assertOk()->assertJsonPath('data.program.enabled', false);

        $premium->updateSettings(['enabled' => true]);
        $this->assertTrue($premium->active());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.company.premium_program', true);
    }

    public function test_an_off_program_stays_visible_to_a_company_that_already_has_levels(): void
    {
        PremiumLevel::create(['company_id' => $this->company->id, 'rank' => 1, 'name' => 'Argent']);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.company.premium_program', true);
    }
}
