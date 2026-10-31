<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Application du livreur : seulement les livraisons prévues aujourd'hui ; les retards et les jours à venir restent à
 * l'agence (nouvelle date ou annulation), et le livreur ne peut pas les faire.
 */
class CourierDayMissionsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
    }

    /** Colis ramassé par le livreur A, livraison confiée au livreur B, prévu le jour donné */
    private function pickedUpFor(?string $day): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $order->forceFill(['delivery_scheduled_date' => $day])->save();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();

        return $order->fresh();
    }

    public function test_the_courier_only_sees_and_delivers_todays_parcels(): void
    {
        $today = $this->pickedUpFor(now()->toDateString());
        $late = $this->pickedUpFor(now()->subDays(2)->toDateString());
        $later = $this->pickedUpFor(now()->addDay()->toDateString());

        Sanctum::actingAs($this->courierB->user);
        $ids = collect($this->getJson('/api/v1/courier/missions')->assertOk()->json('data'))
            ->where('type', 'delivery')->pluck('order.id')->all();
        $this->assertSame([$today->id], $ids);

        // Ni le retard ni le colis de demain ne peuvent partir en livraison
        foreach ([$late, $later] as $order) {
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])
                ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'livraisons du jour'));
        }
        $this->postJson("/api/v1/orders/{$today->id}/status", ['status' => 'out_for_delivery'])->assertOk();

        // L'agence remet le retard à aujourd'hui : il apparaît et peut partir
        $late->forceFill(['delivery_scheduled_date' => now()->toDateString()])->save();
        $this->assertContains($late->id, collect($this->getJson('/api/v1/courier/missions')->json('data'))->pluck('order.id'));
        $this->postJson("/api/v1/orders/{$late->id}/status", ['status' => 'out_for_delivery'])->assertOk();
    }
}
