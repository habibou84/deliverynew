<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Clients sous contrat (dans la durée) et clients ponctuels (une distribution à la fois) : type, dates du contrat,
 * périodicité de facturation, filtres de la liste.
 */
class MerchantContractTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Sanctum::actingAs($this->admin);
    }

    public function test_existing_and_new_clients_are_under_contract_unless_said_occasional(): void
    {
        $this->assertSame('contract', $this->merchant->fresh()->client_type->value);

        $occasional = $this->postJson('/api/v1/merchants', ['business_name' => 'Papeterie Koffi', 'phone' => '05 11 22 33 44', 'client_type' => 'occasional'])
            ->assertCreated()->assertJsonPath('data.client_type', 'occasional')->assertJsonPath('data.contract_status', null)->json('data.id');

        $contract = $this->postJson('/api/v1/merchants', [
            'business_name' => 'Pharmacie du Plateau', 'phone' => '05 55 66 77 88', 'client_type' => 'contract',
            'contract_starts_on' => now()->subMonth()->toDateString(), 'contract_ends_on' => now()->addMonths(5)->toDateString(),
            'billing_period' => 'monthly', 'contract_terms' => 'Tarif négocié : 1 000 F la course en zone 1.',
        ])->assertCreated()
            ->assertJsonPath('data.client_type', 'contract')->assertJsonPath('data.contract_status', 'active')
            ->assertJsonPath('data.billing_period', 'monthly')->assertJsonPath('data.contract_terms', 'Tarif négocié : 1 000 F la course en zone 1.')
            ->json('data.id');

        // Fin avant début : refusé ; type inconnu : refusé
        $this->postJson('/api/v1/merchants', ['business_name' => 'X', 'phone' => '05 00 00 00 09', 'contract_starts_on' => '2026-03-01', 'contract_ends_on' => '2026-02-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('contract_ends_on');
        $this->patchJson("/api/v1/merchants/{$occasional}", ['client_type' => 'vip'])->assertUnprocessable();

        // Filtres de la liste
        $this->assertEqualsCanonicalizing([$contract, $this->merchant->id], collect($this->getJson('/api/v1/merchants?type=contract')->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/merchants?type=occasional')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $occasional);
        $this->getJson('/api/v1/merchants?type=expired')->assertJsonCount(0, 'data');
    }

    public function test_contract_status_follows_the_dates_and_an_occasional_client_keeps_no_contract(): void
    {
        $merchant = Merchant::factory()->create(['company_id' => $this->company->id, 'client_type' => 'contract',
            'contract_starts_on' => now()->subYear()->toDateString(), 'contract_ends_on' => now()->subDay()->toDateString()]);
        $this->getJson("/api/v1/merchants/{$merchant->id}")->assertOk()->assertJsonPath('data.contract_status', 'expired');
        $this->getJson('/api/v1/merchants?type=expired')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $merchant->id);

        $this->patchJson("/api/v1/merchants/{$merchant->id}", ['contract_starts_on' => now()->addWeek()->toDateString(), 'contract_ends_on' => null])
            ->assertOk()->assertJsonPath('data.contract_status', 'upcoming');
        // Fin le jour même : encore en cours
        $this->patchJson("/api/v1/merchants/{$merchant->id}", ['contract_starts_on' => null, 'contract_ends_on' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.contract_status', 'active');

        // Passé ponctuel : le contrat s'efface
        $this->patchJson("/api/v1/merchants/{$merchant->id}", ['client_type' => 'occasional', 'contract_starts_on' => null, 'contract_ends_on' => null, 'billing_period' => null, 'contract_terms' => null])
            ->assertOk()->assertJsonPath('data.client_type', 'occasional')->assertJsonPath('data.contract_status', null)->assertJsonPath('data.contract_ends_on', null);
    }

    public function test_the_merchant_sees_its_type_but_not_the_internal_contract_terms(): void
    {
        $this->merchant->update(['contract_terms' => 'Remise de 10 % négociée', 'billing_period' => 'monthly']);
        Sanctum::actingAs($this->merchantUser);

        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson("/api/v1/merchants/{$this->merchant->id}")->assertOk()
            ->assertJsonPath('data.client_type', 'contract')
            ->assertJsonMissingPath('data.contract_terms')
            ->assertJsonMissingPath('data.billing_period');
    }
}
