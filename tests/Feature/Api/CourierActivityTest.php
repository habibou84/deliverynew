<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Activité des livreurs, suivi du jour : livraisons et ramassages faits sur prévus, colis et argent en main,
 * signaux à surveiller, missions du jour d'un livreur ; réservé au dispatch.
 */
class CourierActivityTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
    }

    public function test_daily_tracking_counts_each_courier_progress(): void
    {
        $done = $this->createOrder(['items_amount' => 10000]);
        $todo = $this->createOrder(['items_amount' => 5000]);
        Sanctum::actingAs($this->dispatcher);
        foreach ([$done, $todo] as $order) {
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        }
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$done->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$done->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$done->id}/status", ['status' => 'delivered'])->assertOk();

        Sanctum::actingAs($this->dispatcher);
        $response = $this->getJson('/api/v1/couriers/activity')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $b = $rows[$this->courierB->id];
        $this->assertSame(['done' => 1, 'total' => 2], $b['deliveries']);
        $this->assertSame($done->fresh()->cod_amount, $b['cash_in_hand']);
        $this->assertSame('mission', $b['state']);
        $a = $rows[$this->courierA->id];
        $this->assertSame(['done' => 1, 'total' => 2], $a['pickups']);
        $this->assertSame(1, $response->json('totals.delivered'));
        $this->assertSame(50, $response->json('totals.progress'));

        // Missions du jour d'un livreur
        $missions = $this->getJson("/api/v1/couriers/{$this->courierB->id}/activity")->assertOk()->json('data');
        $this->assertCount(2, $missions);
        $this->assertSame(['completed', 'assigned'], array_column($missions, 'status'));

        // Rythme, réussite et fin estimée ; prochaine adresse de la mission à faire
        $this->assertSame(100, $b['success_rate']);
        // Une seule mission faite, pas d'habitude : pas encore de rythme pour estimer la fin
        $this->assertNull($b['estimated_end']);
        $this->assertSame(1, $b['remaining']);
        $this->assertArrayHasKey('habit', $b);

        // Jour passé : seulement ce qui a été fait ce jour-là, rien en main
        $this->travelTo(now()->addDay());
        $past = $this->getJson('/api/v1/couriers/activity?date='.now()->subDay()->toDateString())->assertOk();
        $this->assertFalse($past->json('is_today'));
        $pastB = collect($past->json('data'))->firstWhere('id', $this->courierB->id);
        $this->assertSame(['done' => 1, 'total' => 1], $pastB['deliveries']);
        $this->assertSame('done', $pastB['state']);
        $this->assertSame(0, $pastB['cash_in_hand']);
        $this->getJson('/api/v1/couriers/activity?date='.now()->addDay()->toDateString())->assertJsonValidationErrors('date');
        $this->travelBack();

        // Réservé au dispatch
        Sanctum::actingAs($this->userWithRole(Role::Cashier));
        $this->getJson('/api/v1/couriers/activity')->assertForbidden();
    }

    public function test_end_of_day_is_estimated_from_the_pace_and_flagged_after_the_depot_closes(): void
    {
        $this->company->update(['depot_closes_at' => now()->addMinutes(30)->format('H:i')]);
        $orders = collect(range(1, 3))->map(fn () => $this->createOrder(['items_amount' => 5000]));
        Sanctum::actingAs($this->dispatcher);
        foreach ($orders as $order) {
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
            Sanctum::actingAs($this->courierA->user);
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
            Sanctum::actingAs($this->dispatcher);
        }
        // Deux livraisons à 40 minutes d'écart, une reste
        Sanctum::actingAs($this->courierB->user);
        foreach ($orders->take(2) as $i => $order) {
            $this->travel($i === 0 ? 0 : 40)->minutes();
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        }

        Sanctum::actingAs($this->dispatcher);
        $response = $this->getJson('/api/v1/couriers/activity')->assertOk();
        $b = collect($response->json('data'))->firstWhere('id', $this->courierB->id);
        $this->assertSame(40, $b['pace']);
        $this->assertSame(now()->addMinutes(40)->format('H:i'), Carbon::parse($b['estimated_end'])->format('H:i'));
        $this->assertTrue($b['late_end']);
        $this->assertContains('Finira après la fermeture du dépôt', $b['flags']);
        $this->assertSame(1, $response->json('totals.late_end'));
    }
}
