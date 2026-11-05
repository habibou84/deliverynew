<?php

namespace Tests\Feature\Api;

use App\Enums\EarningType;
use App\Models\CourierEarning;
use App\Models\IncidentReason;
use App\Models\Live;
use App\Models\LiveItem;
use App\Models\LiveOrder;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Statistiques : « À retenir », filtres, tendances, rentabilité, échecs et annulations, live.
 */
class StatisticsInsightsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTimezone('Africa/Abidjan')->setDate(2026, 10, 14)->setTime(10, 0));
        $this->buildWorld();
    }

    private function move(Order $order, string $status, array $extra = []): void
    {
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra])->assertOk();
    }

    private function pickedUpOrder(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->move($order, 'picked_up');

        return $order;
    }

    private function sendOut(Order $order): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->move($order, 'out_for_delivery');
    }

    /**
     * Une course livrée du premier coup, une livrée après un échec, une en attente.
     */
    private function activity(): void
    {
        $first = $this->pickedUpOrder();
        $this->travel(30)->minutes();
        $this->sendOut($first);
        $this->move($first, 'delivered', ['collected_amount' => 10000]);

        $second = $this->pickedUpOrder();
        $this->sendOut($second);
        $this->move($second, 'delivery_failed', ['incident_reason_id' => IncidentReason::where('code', 'unreachable')->value('id')]);
        $this->travel(2)->hours();
        $this->sendOut($second);
        $this->move($second, 'delivered', ['collected_amount' => 10000]);

        $this->createOrder();
    }

    public function test_failures_tab_reasons_zones_customers_and_highlights(): void
    {
        $this->activity();
        // Une cliente qui a déjà renvoyé 3 colis
        foreach (range(1, 3) as $i) {
            $this->createOrder(['recipient_phone' => '0707000001'])->forceFill(['status' => 'returned', 'returned_at' => now()])->save();
        }

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/failures')->assertOk()->json('data');
        $this->assertSame(1, $data['kpis']['failed_attempts']);
        $this->assertEquals(33.3, $data['kpis']['failure_rate']);
        $this->assertSame(3, $data['kpis']['returned']);
        $this->assertSame(0, $data['previous_kpis']['failed_attempts']);
        $this->assertSame(IncidentReason::where('code', 'unreachable')->value('label'), $data['reasons'][0]['label']);
        $this->assertSame(3, $data['customers'][0]['refusals']);
        $this->assertStringContainsString('**1 échec** de livraison', $data['highlights'][0]['text']);

        // Réservé au dispatch
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/stats/failures')->assertForbidden();
    }

    public function test_filters_trends_margins_and_workload(): void
    {
        $this->activity();
        $order = Order::where('status', 'delivered')->first();
        $fee = $order->delivery_fee + $order->surcharges_total;
        CourierEarning::create(['company_id' => $this->company->id, 'courier_id' => $this->courierB->id, 'type' => EarningType::Delivery, 'amount' => 400, 'order_id' => $order->id, 'description' => 'Livraison']);
        // Paie sans course (salaire) : répartie sur les courses livrées
        CourierEarning::create(['company_id' => $this->company->id, 'courier_id' => $this->courierB->id, 'type' => EarningType::Delivery, 'amount' => 200, 'description' => 'Salaire']);

        Sanctum::actingAs($this->admin);
        $merchants = $this->getJson('/api/v1/stats/merchants')->assertOk()->json('data');
        $row = $merchants['merchants'][0];
        $this->assertSame('new', $row['trend']);
        $this->assertSame(2 * $fee - 400 - 200, $row['margin']);
        $this->assertSame((int) round((2 * $fee - 600) / 2), $row['margin_per_order']);

        $zones = $this->getJson('/api/v1/stats/zones')->assertOk()->json('data.zones');
        $this->assertSame(2 * $fee - 600, collect($zones)->firstWhere('name', 'Yopougon')['margin']);

        $couriers = $this->getJson('/api/v1/stats/couriers')->assertOk()->json('data');
        $this->assertSame(2, array_sum($couriers['workload'][2]));
        $this->assertArrayHasKey('previous_totals', $couriers);
        $this->assertNotEmpty($couriers['highlights']);

        // Filtres : une autre zone, un autre e-commerçant → rien
        $this->assertSame(0, $this->getJson("/api/v1/stats/overview?zone_id={$this->plateau->id}")->json('data.kpis.created'));
        $this->assertSame(3, $this->getJson("/api/v1/stats/overview?zone_id={$this->yopougon->id}")->json('data.kpis.created'));
        $this->assertSame(2, $this->getJson("/api/v1/stats/overview?courier_id={$this->courierA->id}")->json('data.kpis.created'));
        $this->assertSame(0, $this->getJson('/api/v1/stats/overview?merchant_id=999999')->json('data.kpis.created'));
    }

    public function test_live_tab(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['source' => 'live'])->save();
        $this->createOrder();
        $live = Live::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'name' => 'Vendredi', 'status' => Live::ENDED, 'started_at' => now()->subMinutes(30), 'ended_at' => now()]);
        $item = LiveItem::create(['live_id' => $live->id, 'code' => 'A1', 'name' => 'Robe', 'price' => 9000, 'quantity' => 5]);
        (new LiveOrder)->forceFill(['live_id' => $live->id, 'live_item_id' => $item->id, 'order_id' => $order->id, 'customer_name' => 'Awa', 'customer_phone' => '0707', 'status' => LiveOrder::ORDERED, 'created_at' => now()->subMinutes(18)])->save();

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/live')->assertOk()->json('data');
        $this->assertSame(1, $data['kpis']['lives']);
        $this->assertSame(1, $data['kpis']['orders']);
        $this->assertEquals(50, $data['kpis']['share']);
        $this->assertSame(1, $data['minutes'][2]);
        $this->assertSame($this->merchant->business_name, $data['merchants'][0]['name']);
        $this->assertStringContainsString('**1 live**', $data['highlights'][0]['text']);
    }
}
