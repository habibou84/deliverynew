<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // Réservé au dispatch
        Sanctum::actingAs($this->userWithRole(Role::Cashier));
        $this->getJson('/api/v1/couriers/activity')->assertForbidden();
    }
}
