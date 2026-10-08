<?php

namespace Tests\Feature\Api;

use App\Events\CourierLocationUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Carte des livreurs : dernières positions et missions en cours, mises à jour en direct.
 */
class CourierMapTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_courier_position_is_broadcast_and_shown_on_the_map(): void
    {
        Event::fake([CourierLocationUpdated::class]);

        Sanctum::actingAs($this->courierB->user);
        $this->patchJson('/api/v1/courier/status', ['is_available' => true, 'lat' => 5.3599, 'lng' => -3.9870])->assertOk();

        Event::assertDispatched(CourierLocationUpdated::class, function (CourierLocationUpdated $e) {
            $payload = $e->broadcastWith();

            return $e->courier->is($this->courierB)
                && $payload['lat'] === 5.3599
                && $e->broadcastOn()[0]->name === 'private-company.'.$this->company->id;
        });

        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();

        $map = $this->getJson('/api/v1/couriers/map')->assertOk()->json('data');
        $awa = collect($map)->firstWhere('id', $this->courierB->id);

        $this->assertSame(5.3599, $awa['lat']);
        $this->assertTrue($awa['is_available']);
        $this->assertNotNull($awa['last_location_at']);
        $this->assertSame($order->tracking_code, $awa['missions'][0]['tracking_code']);
        $this->assertSame('Yopougon', $awa['missions'][0]['zone_name']);

        // Livreur sans position : listé après ceux qui en ont une
        $this->assertSame($this->courierB->id, $map[0]['id']);
        $this->assertNull(collect($map)->firstWhere('id', $this->courierA->id)['lat']);
    }

    public function test_map_is_reserved_to_dispatch(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/couriers/map')->assertForbidden();

        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/couriers/map')->assertForbidden();

        // Rien n'est diffusé quand rien ne change
        Event::fake([CourierLocationUpdated::class]);
        $this->patchJson('/api/v1/courier/status', ['is_available' => $this->courierA->is_available])->assertOk();
        Event::assertNotDispatched(CourierLocationUpdated::class);
    }
}
