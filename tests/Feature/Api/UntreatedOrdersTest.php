<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page Courses : chaque onglet ne montre que les courses du jour choisi ; les courses dont le jour de livraison est
 * dépassé et qui ne sont pas terminées sont dans « Courses non traitées », regroupées par date, livreur ou e-commerçant.
 */
class UntreatedOrdersTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
    }

    private function orderOn(string $day, OrderStatus $status, array $attributes = []): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['delivery_scheduled_date' => $day, 'status' => $status, ...$attributes])->save();

        return $order;
    }

    private function codes(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_every_tab_shows_only_the_chosen_day(): void
    {
        $today = today()->toDateString();
        $yesterday = today()->subDay()->toDateString();
        $pendingToday = $this->orderOn($today, OrderStatus::Pending);
        $this->orderOn($yesterday, OrderStatus::Pending);
        $failedToday = $this->orderOn($today, OrderStatus::DeliveryFailed);
        $this->orderOn($yesterday, OrderStatus::DeliveryFailed);
        $returnToday = $this->orderOn($today, OrderStatus::PickedUp, ['return_requested' => true]);
        $this->orderOn($yesterday, OrderStatus::PickedUp, ['return_requested' => true]);

        // À ramasser : livreur de ramassage assigné, colis pas encore récupéré (sans livreur : « Sans livreur »)
        $assigned = $this->orderOn($today, OrderStatus::PickupAssigned);
        $this->orderOn($today, OrderStatus::Confirmed);

        Sanctum::actingAs($this->dispatcher);
        $this->assertSame([$assigned->id], $this->codes("/api/v1/orders?queue=to_pickup&day={$today}"));
        $this->assertSame([$pendingToday->id], $this->codes("/api/v1/orders?queue=to_confirm&day={$today}"));
        $this->assertSame([$failedToday->id], $this->codes("/api/v1/orders?queue=failed&day={$today}"));
        $this->assertSame([$returnToday->id], $this->codes("/api/v1/orders?queue=to_return&day={$today}"));

        $day = $this->getJson("/api/v1/orders/counts?day={$today}")->assertOk()->json('data.day');
        $this->assertSame([1, 1, 1], [$day['to_confirm'], $day['failed'], $day['to_return']]);
        // Toutes les dates : les deux jours
        $this->assertCount(2, $this->codes('/api/v1/orders?queue=to_confirm'));
    }

    public function test_untreated_orders_are_all_past_unfinished_ones_grouped_by_date_courier_or_merchant(): void
    {
        $twoDaysAgo = today()->subDays(2)->toDateString();
        $yesterday = today()->subDay()->toDateString();
        $withCourier = $this->orderOn($twoDaysAgo, OrderStatus::DeliveryAssigned, ['delivery_courier_id' => $this->courierB->id, 'pickup_courier_id' => $this->courierA->id]);
        $alone = $this->orderOn($yesterday, OrderStatus::Confirmed);
        $returning = $this->orderOn($yesterday, OrderStatus::PickedUp, ['return_requested' => true]);
        $this->orderOn($yesterday, OrderStatus::Delivered);
        $this->orderOn(today()->toDateString(), OrderStatus::Confirmed);

        Sanctum::actingAs($this->dispatcher);
        $this->assertSame(collect([$withCourier->id, $alone->id, $returning->id])->sort()->values()->all(), $this->codes('/api/v1/orders?queue=untreated'));
        $this->assertSame(3, $this->getJson('/api/v1/orders/counts')->json('data.exceptions.untreated'));

        $byDate = $this->getJson('/api/v1/orders/untreated-groups?by=date')->assertOk()->json('data');
        $this->assertSame(3, $byDate['total']);
        $this->assertSame([[$twoDaysAgo, 1], [$yesterday, 2]], array_map(fn ($g) => [$g['key'], $g['count']], $byDate['groups']));
        $this->assertSame([$alone->id, $returning->id], $this->codes("/api/v1/orders?queue=untreated&from={$yesterday}&to={$yesterday}&date_basis=delivery"));

        $byCourier = collect($this->getJson('/api/v1/orders/untreated-groups?by=courier')->json('data.groups'))->pluck('count', 'key');
        $this->assertSame(1, $byCourier[(string) $this->courierB->id]);
        $this->assertSame([$withCourier->id], $this->codes("/api/v1/orders?queue=untreated&lead_courier_id={$this->courierB->id}"));
        $this->assertSame(2, $byCourier['none']);
        $this->assertCount(2, $this->codes('/api/v1/orders?queue=untreated&without_courier=1'));

        $byMerchant = $this->getJson('/api/v1/orders/untreated-groups?by=merchant')->json('data.groups');
        $this->assertSame([(string) $this->merchant->id, 3], [$byMerchant[0]['key'], $byMerchant[0]['count']]);

        // Réservé au dispatch
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/orders/untreated-groups')->assertForbidden();
    }
}
