<?php

namespace Tests\Feature\Api;

use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Retours marchands sans bon de retour : l'agence confie les colis d'un marchand à un livreur, la remise se fait
 * ensuite par le QR code (ReturnHandoverTest). Les anciennes adresses des bons n'existent plus.
 */
class MerchantReturnsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function move(Order $order, User $actor, string $status, array $extra = [])
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    /** Échec de livraison chez B, retour décidé ; colis rendu au dépôt ou encore chez B */
    private function toReturn(bool $receivedAtHub = true): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();
        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertOk();
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->move($order, $this->courierB->user, 'delivery_failed', ['incident_reason_id' => $reason->id])->assertOk();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'return'])->assertOk();
        if ($receivedAtHub) {
            $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", ['order_ids' => [$order->id]])->assertOk();
        }

        return $order->fresh();
    }

    public function test_returns_are_given_to_a_courier_without_any_slip(): void
    {
        $first = $this->toReturn();
        $second = $this->toReturn();
        $stillWithB = $this->toReturn(receivedAtHub: false);

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/return-slips')->assertOk()->assertJsonPath('candidates.0.total', 3)
            ->assertJsonPath('candidates.0.held', 1)->assertJsonMissingPath('data');
        $this->getJson('/api/v1/return-slips/count')->assertOk()->assertJsonPath('data.to_return', 3)
            ->assertJsonPath('data.merchants', 1)->assertJsonPath('data.held', 1)->assertJsonPath('data.assigned', 0);

        // Confiés à A, même celui encore chez B (il passera par un relais)
        $this->postJson('/api/v1/return-slips/assign', ['merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$first->id, $second->id, $stillWithB->id]])
            ->assertOk()->assertJsonPath('data.assigned', 3);
        foreach ([$first, $second, $stillWithB] as $order) {
            $this->assertSame('return_assigned', $order->fresh()->status->value);
            $this->assertSame($this->courierA->id, $order->fresh()->return_courier_id);
            $this->assertNull($order->fresh()->return_slip_id);
        }
        $this->getJson('/api/v1/return-slips/count')->assertJsonPath('data.assigned', 3);

        // Réassigner à B
        $this->postJson('/api/v1/return-slips/assign', ['merchant_id' => $this->merchant->id, 'courier_id' => $this->courierB->id, 'order_ids' => [$first->id]])->assertOk();
        $this->assertSame($this->courierB->id, $first->fresh()->return_courier_id);

        // Plus de bon de retour
        $this->postJson('/api/v1/return-slips', ['merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$second->id]])->assertStatus(405);
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/return-slips')->assertNotFound();
    }

    public function test_assignment_checks_the_parcels(): void
    {
        $order = $this->toReturn();
        $delivered = $this->createOrder();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/return-slips/assign', ['merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => []])->assertJsonValidationErrors('order_ids');
        $this->postJson('/api/v1/return-slips/assign', ['merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$order->id, 999999]])->assertUnprocessable();
        $this->postJson('/api/v1/return-slips/assign', ['merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$delivered->id]])->assertUnprocessable();

        // Réservé au dispatch
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/return-slips/assign', ['merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$order->id]])->assertForbidden();
    }
}
