<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class AwaitingCourierTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->company->update(['pickup_assign_alert_minutes' => 30, 'delivery_assign_alert_minutes' => 60]);
    }

    private function confirmedOrder(array $data = []): Order
    {
        return app(OrderWorkflow::class)->transition($this->dispatcher, $this->createOrder($data), OrderStatus::Confirmed);
    }

    private function awaiting(Order $order): ?array
    {
        return $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.awaiting_courier');
    }

    public function test_validated_order_without_pickup_courier_becomes_late(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $order = $this->confirmedOrder();

        $this->assertSame('pickup', $this->awaiting($order)['stage']);
        $this->assertFalse($this->awaiting($order)['late']);
        $this->getJson('/api/v1/orders/counts')->assertOk()->assertJsonPath('data.to_pickup', 1)->assertJsonPath('data.late.total', 0);

        $this->travel(31)->minutes();
        $this->assertTrue($this->awaiting($order)['late']);
        $this->assertSame(31, $this->awaiting($order)['minutes']);
        $this->getJson('/api/v1/orders/counts')->assertJsonPath('data.late.pickup', 1)->assertJsonPath('data.late.delivery', 0);
        $this->assertTrue(collect($this->getJson('/api/v1/orders?queue=to_pickup')->json('data'))->first()['awaiting_courier']['late']);

        // Assignée : plus en attente
        app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Pickup, $this->courierA);
        $this->assertNull($this->awaiting($order->fresh()));
        $this->getJson('/api/v1/orders/counts')->assertJsonPath('data.late.total', 0);
    }

    public function test_picked_up_parcel_waits_for_a_delivery_courier(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $order = $this->confirmedOrder();
        $this->travel(2)->hours();

        // Le décompte repart à l'arrivée du colis
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();
        $state = $this->awaiting($order);
        $this->assertSame('delivery', $state['stage']);
        $this->assertSame(0, $state['minutes']);
        $this->assertFalse($state['late']);

        $this->travel(61)->minutes();
        $this->assertTrue($this->awaiting($order)['late']);
        $this->getJson('/api/v1/orders/counts')->assertJsonPath('data.late.delivery', 1);

        // Retour demandé : ce n'est plus une livraison à assigner
        $order->forceFill(['return_requested' => true])->save();
        $this->assertNull($this->awaiting($order));
    }

    public function test_orders_planned_for_another_day_and_warehouse_orders_do_not_wait(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $later = $this->confirmedOrder(['delivery_scheduled_date' => today()->addDays(2)->toDateString()]);
        $this->travel(3)->hours();
        $this->assertNull($this->awaiting($later));

        // Le jour prévu, l'attente part du début de la journée
        $this->travelTo(today()->addDays(2)->setTime(1, 0));
        $this->assertSame('pickup', $this->awaiting($later)['stage']);
        $this->assertSame(60, $this->awaiting($later)['minutes']);
    }

    public function test_unassigned_queue_lists_pickups_and_deliveries_oldest_first(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $pickup = $this->confirmedOrder();
        $this->travel(30)->minutes();
        $delivery = $this->confirmedOrder();
        $delivery->forceFill(['status' => OrderStatus::PickedUp])->save();
        $this->confirmedOrder(['delivery_scheduled_date' => today()->addDays(3)->toDateString()]);

        // Report qui a déjà son livreur de livraison : pas « sans livreur »
        $planned = $this->confirmedOrder();
        $planned->forceFill(['status' => OrderStatus::AtHub])->save();
        app(OrderDispatcher::class)->assign($this->dispatcher, $planned, AssignmentType::Delivery, $this->courierB);
        $planned->refresh()->forceFill(['status' => OrderStatus::Rescheduled, 'delivery_scheduled_date' => today()->toDateString()])->save();
        $this->assertNull($this->awaiting($planned));
        $this->travel(5)->minutes();

        $codes = fn (string $query) => collect($this->getJson('/api/v1/orders?queue=unassigned'.$query)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$pickup->id, $delivery->id], $codes(''));
        $this->assertSame([$pickup->id], $codes('&awaiting=pickup'));
        $this->assertSame([$delivery->id], $codes('&awaiting=delivery'));
        $this->getJson('/api/v1/orders?queue=unassigned&awaiting=autre')->assertJsonValidationErrors('awaiting');
        $this->getJson('/api/v1/orders/counts')->assertJsonPath('data.unassigned', 2);
    }

    public function test_dispatch_is_alerted_once_then_admins(): void
    {
        $order = $this->confirmedOrder();
        $other = $this->confirmedOrder();
        // Seules les alertes « sans livreur » comptent (pas les notifications de création)
        Notification::fake();

        $this->travel(20)->minutes();
        $this->artisan('orders:unassigned')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travel(15)->minutes();
        $this->artisan('orders:unassigned')->assertSuccessful();
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'unassigned_pickup'
            && str_contains($n->title, '2 courses toujours sans livreur de ramassage') && $n->extra['href'] === '/admin/courses?queue=to_pickup');
        Notification::assertSentTo($this->admin, OrderAlert::class);
        Notification::assertNotSentTo($this->merchantUser, OrderAlert::class);
        // Alertes « sans livreur » reçues par le dispatcher et l'administrateur
        $alerts = fn () => collect([$this->dispatcher, $this->admin])
            ->sum(fn ($user) => Notification::sent($user, OrderAlert::class, fn (OrderAlert $n) => str_starts_with($n->kind, 'unassigned_'))->count());
        $this->assertSame(2, $alerts());

        // Pas de nouvelle alerte tant que rien ne change
        $this->artisan('orders:unassigned')->assertSuccessful();
        $this->assertSame(2, $alerts());

        // Une course assignée sort du décompte ; l'autre remonte aux administrateurs après trois fois le délai
        app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Pickup, $this->courierA);
        $this->travel(60)->minutes();
        $this->artisan('orders:unassigned')->assertSuccessful();
        $this->assertSame(3, $alerts());
        Notification::assertSentTo($this->admin, OrderAlert::class, fn (OrderAlert $n) => str_starts_with($n->title, '🚨') && str_contains($n->body, $other->tracking_code));
    }

    public function test_alerts_can_be_turned_off_and_counts_are_reserved_to_dispatch(): void
    {
        $this->company->update(['pickup_assign_alert_minutes' => 0]);
        $this->confirmedOrder();
        Notification::fake();
        $this->travel(5)->hours();

        $this->artisan('orders:unassigned')->assertSuccessful();
        Notification::assertNothingSent();

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/orders/counts')->assertJsonPath('data.late.total', 0)->assertJsonPath('data.to_pickup', 1);

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/orders/counts')->assertForbidden();
        $this->assertNull(collect($this->getJson('/api/v1/orders')->json('data'))->first()['awaiting_courier'] ?? null);
    }
}
