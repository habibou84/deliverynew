<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\V1\ZoneResource;
use App\Models\PricingGrid;
use App\Models\PricingGroup;
use App\Models\Zone;
use App\Services\Pricing\PricingService;
use App\Services\Zones\StandardZones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Zones et tarifs › Zones et prix : un prix par lieu, rangé dans le groupe « depuis partout » de ce prix.
 */
class ZonePricesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        app(StandardZones::class)->sync($this->company);
        Sanctum::actingAs($this->admin);
    }

    private function setPrice(int $zoneId, ?int $price, ?PricingGrid $grid = null)
    {
        return $this->putJson('/api/v1/pricing-grids/'.($grid ?? $this->grid)->id."/zone-prices/{$zoneId}", ['price' => $price]);
    }

    private function zoneRow(int $zoneId, ?PricingGrid $grid = null): array
    {
        return collect($this->getJson('/api/v1/pricing-grids/'.($grid ?? $this->grid)->id.'/zone-prices')->assertOk()->json('data.zones'))
            ->firstWhere('id', $zoneId);
    }

    public function test_a_price_on_a_zone_line_joins_the_group_of_that_price(): void
    {
        $this->setPrice($this->plateau->id, 2000)->assertOk()->assertJsonPath('data.price', 2000);
        $this->setPrice($this->yopougon->id, 2000)->assertOk();

        // Un seul groupe pour ce prix, qui donne le prix de la course
        $this->assertSame(1, PricingGroup::where('pricing_grid_id', $this->grid->id)->where('price', 2000)->count());
        $this->postJson('/api/v1/quotes', ['pickup_zone_id' => $this->cocody->id, 'delivery_zone_id' => $this->plateau->id])
            ->assertOk()->assertJsonPath('data.total', 2000);

        // Un quartier sans prix reprend celui de sa commune
        $this->assertNull($this->zoneRow($this->angre->id)['inherited_price']);
        $this->setPrice($this->cocody->id, 1200)->assertOk();
        $this->assertSame(1200, $this->zoneRow($this->angre->id)['inherited_price']);

        // Changer le prix déplace le lieu ; un groupe vidé disparaît
        $this->setPrice($this->plateau->id, 2500)->assertOk();
        $this->setPrice($this->yopougon->id, null)->assertOk()->assertJsonPath('data.price', null);
        $this->assertFalse(PricingGroup::where('pricing_grid_id', $this->grid->id)->where('price', 2000)->exists());
        $this->assertSame(2500, $this->zoneRow($this->plateau->id)['price']);
    }

    public function test_each_negotiated_grid_has_its_own_prices(): void
    {
        $vip = PricingGrid::factory()->create(['company_id' => $this->company->id, 'name' => 'Gros volume']);
        $this->setPrice($this->plateau->id, 2000)->assertOk();
        $this->setPrice($this->plateau->id, 1500, $vip)->assertOk();

        $this->assertSame(2000, $this->zoneRow($this->plateau->id)['price']);
        $this->assertSame(1500, $this->zoneRow($this->plateau->id, $vip)['price']);
    }

    public function test_pickup_can_be_switched_off_where_the_company_only_delivers(): void
    {
        $this->company->update(['merchant_signup' => true]);
        $this->patchJson("/api/v1/zones/{$this->cocody->id}", ['is_pickup' => false])->assertOk()->assertJsonPath('data.is_pickup', false);

        // Ni la commune ni ses quartiers : plus proposés comme ramassage, et une course qui y ramasse est refusée
        $this->assertFalse($this->angre->fresh()->load('parent')->acceptsPickup());
        $this->assertTrue($this->yopougon->fresh()->acceptsPickup());
        $zones = collect($this->getJson('/api/v1/signup')->json('data.zones'))->pluck('id');
        $this->assertNotContains($this->cocody->id, $zones);
        $this->assertNotContains($this->angre->id, $zones);
        $this->assertContains($this->yopougon->id, $zones);

        try {
            $this->createOrder(['pickup_zone_id' => $this->angre->id]);
            $this->fail('Ramassage accepté dans une zone sans ramassage.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Pas de ramassage à Cocody › Angré', $e->getMessage());
        }
        $this->setPrice($this->cocody->id, 1000)->assertOk();
        $this->assertNotNull($this->createOrder(['pickup_zone_id' => $this->yopougon->id, 'delivery_zone_id' => $this->cocody->id])->id);

        // On livre toujours à Cocody ; l'API publique garde la même forme (pas de champ ajouté)
        $this->assertTrue($this->cocody->fresh()->is_active);
        $this->assertArrayNotHasKey('is_pickup', ZoneResource::make($this->cocody->fresh())->resolve(Request::create('/api/public/v1/zones')));
    }

    public function test_only_the_settings_managers_change_prices(): void
    {
        Sanctum::actingAs($this->dispatcher);

        $this->setPrice($this->plateau->id, 2000)->assertForbidden();
    }

    public function test_departure_tiers_are_kept_with_the_grid_and_the_prices_stay_routes(): void
    {
        $other = Zone::withoutGlobalScopes()->where('company_id', '!=', $this->company->id)->value('id');

        // Paliers d'une commune de départ : enregistrés avec la grille (zone d'une autre entreprise ignorée)
        $this->putJson("/api/v1/pricing-grids/{$this->grid->id}", ['tiers' => [
            $this->plateau->id => [1000, 1500, 2000, 3000],
            ...($other ? [$other => [500]] : []),
        ]])->assertOk()->assertJsonPath("data.tiers.{$this->plateau->id}", [1000, 1500, 2000, 3000]);
        $this->assertSame([$this->plateau->id => [1000, 1500, 2000, 3000]], $this->grid->fresh()->tiers);
        $this->getJson("/api/v1/pricing-grids/{$this->grid->id}")->assertJsonPath("data.tiers.{$this->plateau->id}.1", 1500);

        // Plus de quatre paliers : refusé
        $this->putJson("/api/v1/pricing-grids/{$this->grid->id}", ['tiers' => [$this->plateau->id => [1, 2, 3, 4, 5]]])->assertJsonValidationErrors('tiers.'.$this->plateau->id);

        // Le palier touché devient un trajet départ → arrivée, valable dans les deux sens
        $this->putJson("/api/v1/pricing-grids/{$this->grid->id}/rules", ['rules' => [
            ['origin_zone_id' => $this->plateau->id, 'destination_zone_id' => $this->yopougon->id, 'price' => 3000, 'is_symmetric' => true],
        ]])->assertOk();
        $quote = app(PricingService::class)->quote($this->merchant, $this->yopougon, $this->plateau);
        $this->assertSame(3000, $quote->basePrice);
    }
}
