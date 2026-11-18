<?php

namespace Tests\Feature\Api;

use App\Models\PricingGrid;
use App\Models\PricingGroup;
use App\Services\Zones\StandardZones;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_only_the_settings_managers_change_prices(): void
    {
        Sanctum::actingAs($this->dispatcher);

        $this->setPrice($this->plateau->id, 2000)->assertForbidden();
    }
}
