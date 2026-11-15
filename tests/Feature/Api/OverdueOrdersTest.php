<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Date de livraison dépassée : la course est à reporter ou annuler, jamais « sans livreur ». Hier et avant-hier,
 * elle est un retard récent (file « À régler » du poste de pilotage) ; au-delà, une course non traitée.
 */
class OverdueOrdersTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->buildWorld();
        $this->company->update(['pickup_assign_alert_minutes' => 30, 'delivery_assign_alert_minutes' => 60]);
    }

    /** Course dans un statut donné, prévue il y a $daysAgo jours (0 : aujourd'hui) */
    private function orderDue(OrderStatus $status, int $daysAgo): Order
    {
        $order = $this->createOrder();
        $order->forceFill([
            'status' => $status,
            'delivery_scheduled_date' => today()->subDays($daysAgo)->toDateString(),
            'status_changed_at' => now()->subDays($daysAgo)->subHours(2),
        ])->save();

        return $order->fresh();
    }

    public function test_a_picked_up_parcel_past_its_date_is_to_reschedule_not_awaiting_a_courier(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $today = $this->orderDue(OrderStatus::PickedUp, 0);
        $old = $this->orderDue(OrderStatus::PickedUp, 35);

        $this->assertSame('delivery', $this->getJson("/api/v1/orders/{$today->id}")->json('data.awaiting_courier.stage'));
        $this->assertNull($this->getJson("/api/v1/orders/{$old->id}")->json('data.awaiting_courier'));

        // Une heure plus tard : seule la course du jour est en attente trop longue
        $this->travel(61)->minutes();
        $this->getJson('/api/v1/orders/counts?day='.today()->toDateString())->assertOk()
            ->assertJsonPath('data.unassigned', 1)
            ->assertJsonPath('data.late.total', 1)
            ->assertJsonPath('data.day.unassigned', 1);
        $this->assertSame([$today->id], collect($this->getJson('/api/v1/orders?queue=unassigned')->json('data'))->pluck('id')->all());
        // Elle reste dans les courses en retard, à reporter ou annuler
        $this->assertContains($old->id, collect($this->getJson('/api/v1/orders?queue=untreated')->json('data'))->pluck('id')->all());
    }

    public function test_overdue_orders_are_counted_by_age(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $yesterday = $this->orderDue(OrderStatus::Confirmed, 1);
        $dayBefore = $this->orderDue(OrderStatus::PickedUp, 2);
        $threeDays = $this->orderDue(OrderStatus::Confirmed, 3);
        $month = $this->orderDue(OrderStatus::PickedUp, 30);
        $this->orderDue(OrderStatus::Delivered, 1);

        $this->getJson('/api/v1/orders/counts')->assertOk()
            ->assertJsonPath('data.overdue.recent', ['untreated' => 2, 'untreated_picked' => 1, 'to_reschedule' => 1])
            ->assertJsonPath('data.overdue.old', ['untreated' => 2, 'untreated_picked' => 1, 'to_reschedule' => 1]);

        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$yesterday->id, $dayBefore->id])->sort()->values()->all(), $ids('/api/v1/orders?queue=untreated&age=recent'));
        $this->assertSame(collect([$threeDays->id, $month->id])->sort()->values()->all(), $ids('/api/v1/orders?queue=untreated&age=old'));
        $this->assertSame([$month->id], $ids('/api/v1/orders?queue=untreated_picked&age=old'));

        $this->getJson('/api/v1/orders/untreated-groups?age=recent')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/v1/orders/untreated-groups?age=old&stage=picked')->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame(1, collect($this->getJson('/api/v1/orders/to-reschedule?age=recent')->assertOk()->json('data.groups'))->sum(fn ($g) => count($g['orders'])));
        $this->getJson('/api/v1/orders?queue=untreated&age=autre')->assertUnprocessable();
    }
}
