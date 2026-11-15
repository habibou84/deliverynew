<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\OrderDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Onglets de la liste des courses : une étape ramassage (sans ramasseur, ramassage en cours) et une étape livraison
 * (sans livreur, livraison assignée, en chemin, livrées) ; chaque course du jour est dans un seul de ces onglets.
 */
class OrderStepQueuesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->buildWorld();
    }

    private function orderIn(OrderStatus $status): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => $status, 'delivery_scheduled_date' => today()->toDateString()])->save();

        return $order->fresh();
    }

    public function test_each_order_of_the_day_is_in_one_step(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $noPickup = $this->orderIn(OrderStatus::Confirmed);
        $pickingUp = $this->orderIn(OrderStatus::PickupAssigned);
        $noCourier = $this->orderIn(OrderStatus::PickedUp);
        $assigned = $this->orderIn(OrderStatus::AtHub);
        app(OrderDispatcher::class)->assign($this->dispatcher, $assigned, AssignmentType::Delivery, $this->courierA);
        $onTheWay = $this->orderIn(OrderStatus::OutForDelivery);
        $delivered = $this->orderIn(OrderStatus::Delivered);

        $ids = fn (string $queue) => collect($this->getJson('/api/v1/orders?day='.today()->toDateString().'&queue='.$queue)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$noPickup->id], $ids('pickup_unassigned'));
        $this->assertSame([$pickingUp->id], $ids('to_pickup'));
        $this->assertSame([$noCourier->id], $ids('delivery_unassigned'));
        $this->assertSame([$assigned->id], $ids('delivery_assigned'));
        $this->assertSame([$onTheWay->id], $ids('on_the_way'));
        $this->assertSame([$delivered->id], $ids('delivered'));

        $this->getJson('/api/v1/orders/counts?day='.today()->toDateString())->assertOk()
            ->assertJsonPath('data.day.pickup_unassigned', 1)
            ->assertJsonPath('data.day.delivery_unassigned', 1)
            ->assertJsonPath('data.day.delivery_assigned', 1)
            ->assertJsonPath('data.day.on_the_way', 1)
            ->assertJsonPath('data.day.delivered', 1);
    }
}
