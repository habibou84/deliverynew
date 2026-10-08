<?php

namespace Tests\Feature\Api;

use App\Events\CourierLocationUpdated;
use App\Models\CourierLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_positions_are_kept_as_a_daily_track(): void
    {
        Carbon::setTestNow('2026-10-08 08:00:00');
        Sanctum::actingAs($this->courierB->user);
        $send = fn (float $lat, float $lng, ?int $accuracy = 20) => $this->patchJson('/api/v1/courier/status', array_filter(['lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy]))->assertOk();

        // Hors service : la position n'est pas historisée
        $this->patchJson('/api/v1/courier/status', ['is_available' => false])->assertOk();
        $send(5.3600, -3.9900);
        $this->assertSame(0, CourierLocation::count());

        $this->patchJson('/api/v1/courier/status', ['is_available' => true])->assertOk();
        $send(5.3600, -3.9900);                       // départ
        Carbon::setTestNow('2026-10-08 08:00:30');
        $send(5.36005, -3.99005);                      // à l'arrêt : ignoré
        $send(5.3700, -3.9900, 900);                   // trop imprécis : ignoré
        Carbon::setTestNow('2026-10-08 08:05:00');
        $send(5.3690, -3.9900);                        // ~1 km plus loin
        Carbon::setTestNow('2026-10-08 08:05:10');
        $send(5.4600, -3.9900);                        // saut de 10 km en 10 s : erreur GPS, ignoré
        Carbon::setTestNow('2026-10-08 08:10:00');
        $send(5.3780, -3.9900);                        // encore ~1 km
        $this->assertSame(3, CourierLocation::count());

        // Étape de course localisée sur le trajet
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up', 'lat' => 5.3690, 'lng' => -3.9900])->assertOk();

        Sanctum::actingAs($this->dispatcher);
        $day = $this->getJson("/api/v1/couriers/{$this->courierB->id}/track?date=2026-10-08")->assertOk()->json('data');

        $this->assertCount(3, $day['points']);
        $this->assertEqualsWithDelta(2.0, $day['summary']['distance_km'], 0.1);
        $this->assertSame(1, $day['summary']['picked_up']);
        $this->assertSame('picked_up', $day['stops'][0]['status']);
        $this->assertSame($order->tracking_code, $day['stops'][0]['tracking_code']);
        $this->assertSame(['2026-10-08'], $day['recent_days']);

        $this->getJson("/api/v1/couriers/{$this->courierB->id}/track?date=2026-10-07")->assertOk()->assertJsonCount(0, 'data.points');
        $this->getJson("/api/v1/couriers/{$this->courierB->id}/track?date=2026-12-01")->assertJsonValidationErrors('date');

        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/couriers/{$this->courierB->id}/track")->assertForbidden();

        // Conservation limitée à 90 jours
        Carbon::setTestNow('2027-01-07 08:00:00');
        $this->artisan('model:prune', ['--model' => [CourierLocation::class]])->assertSuccessful();
        $this->assertSame(0, CourierLocation::count());

        Carbon::setTestNow();
    }
}
