<?php

namespace Tests\Feature\Api;

use App\Models\OrderAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Tournée du livreur : il range ses livraisons du jour (de proche en proche ou à la main), l'ordre est gardé et les
 * nouvelles livraisons passent après.
 */
class CourierRouteTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
    }

    /** Colis ramassé par A, livraison du jour confiée à B */
    private function deliveryFor(): OrderAssignment
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $order->forceFill(['delivery_scheduled_date' => now()->toDateString()])->save();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();

        return OrderAssignment::query()->where('order_id', $order->id)->where('type', 'delivery')->sole();
    }

    /** @return list<int> */
    private function deliveryOrder(): array
    {
        return collect($this->getJson('/api/v1/courier/missions')->assertOk()->json('data'))
            ->where('type', 'delivery')->pluck('id')->values()->all();
    }

    public function test_the_courier_orders_his_deliveries_and_new_ones_come_last(): void
    {
        [$a, $b, $c] = [$this->deliveryFor(), $this->deliveryFor(), $this->deliveryFor()];

        Sanctum::actingAs($this->courierB->user);
        $this->assertSame([$a->id, $b->id, $c->id], $this->deliveryOrder());

        $this->putJson('/api/v1/courier/route', ['assignment_ids' => [$c->id, $a->id, $b->id]])->assertOk();
        $this->assertSame([$c->id, $a->id, $b->id], $this->deliveryOrder());
        $this->assertSame([1, 2, 3], [$c->fresh()->route_rank, $a->fresh()->route_rank, $b->fresh()->route_rank]);

        // Nouvelle livraison confiée ensuite : à la fin, jusqu'au prochain rangement
        $d = $this->deliveryFor();
        Sanctum::actingAs($this->courierB->user);
        $this->assertSame([$c->id, $a->id, $b->id, $d->id], $this->deliveryOrder());

        // Liste partielle : les livraisons absentes passent après
        $this->putJson('/api/v1/courier/route', ['assignment_ids' => [$d->id, $b->id]])->assertOk();
        $this->assertSame([$d->id, $b->id], array_slice($this->deliveryOrder(), 0, 2));
        $this->assertNull($a->fresh()->route_rank);
    }

    public function test_only_his_own_current_deliveries(): void
    {
        $mine = $this->deliveryFor();
        $pickup = OrderAssignment::query()->where('order_id', $mine->order_id)->where('type', 'pickup')->sole();

        // Livraison d'un autre livreur, ou ramassage : refusé, rien ne change
        Sanctum::actingAs($this->courierA->user);
        $this->putJson('/api/v1/courier/route', ['assignment_ids' => [$mine->id]])->assertStatus(422);
        Sanctum::actingAs($this->courierB->user);
        $this->putJson('/api/v1/courier/route', ['assignment_ids' => [$pickup->id, $mine->id]])->assertStatus(422);
        $this->assertNull($mine->fresh()->route_rank);

        Sanctum::actingAs($this->dispatcher);
        $this->putJson('/api/v1/courier/route', ['assignment_ids' => [$mine->id]])->assertForbidden();
    }
}
