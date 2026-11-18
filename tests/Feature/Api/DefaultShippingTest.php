<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Zone;
use App\Services\Zones\StandardZones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Expédition par défaut : une zone « Expédition » d'office, la ville de destination sur la course ; les villes lointaines
 * passent par l'expédition (Grand-Bassam reste en livraison directe).
 */
class DefaultShippingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        app(StandardZones::class)->sync($this->company);
    }

    private function shippingZone(): Zone
    {
        return Zone::where('default_shipping', true)->sole();
    }

    public function test_every_company_gets_one_shipping_zone_and_far_cities_go_through_it(): void
    {
        app(StandardZones::class)->sync($this->company);

        $zone = $this->shippingZone();
        $this->assertTrue($zone->is_shipping && $zone->is_active);
        $this->assertFalse($zone->is_pickup);
        $this->assertFalse(Zone::where('place_code', 'bouake')->sole()->is_active);
        $this->assertTrue(Zone::where('place_code', 'grand-bassam')->sole()->is_active);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/shipping-cities')->assertOk()->assertJsonFragment(['Bouaké'])->assertJsonMissing(['Abidjan']);
        $this->deleteJson("/api/v1/zones/{$zone->id}")->assertUnprocessable();
        $this->patchJson("/api/v1/zones/{$zone->id}", ['name' => 'Autre nom', 'is_active' => false])->assertOk();
        $this->assertSame(StandardZones::SHIPPING_NAME, $zone->fresh()->name);
        $this->assertFalse($zone->fresh()->is_active);
    }

    public function test_the_destination_city_is_kept_on_the_course(): void
    {
        $this->rule($this->grid, $this->cocody, $this->shippingZone(), 2000);
        Sanctum::actingAs($this->merchantUser);

        $id = $this->postJson('/api/v1/orders', [
            'recipient_name' => 'Awa', 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->shippingZone()->id,
            'delivery_address' => 'Gare de Bouaké', 'items_amount' => 10000, 'fee_payer' => 'merchant', 'shipping_city' => 'Bouaké',
        ])->assertCreated()->assertJsonPath('data.is_shipping', true)->assertJsonPath('data.shipping.city', 'Bouaké')
            ->assertJsonPath('data.amounts.delivery_fee', 2000)->json('data.id');

        Sanctum::actingAs($this->dispatcher);
        $this->patchJson("/api/v1/orders/{$id}", ['shipping_city' => 'Daloa'])->assertOk()->assertJsonPath('data.shipping.city', 'Daloa');
        $this->assertSame('Daloa', Order::find($id)->shipping_city);
    }
}
