<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\CashCollection;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Point du jour de la page Courses : prévues, livrées, recette de livraison, encaissé (caisse ou livreurs), à reverser.
 */
class DayReportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
    }

    private function deliver(int $amount): Order
    {
        $order = $this->createOrder(['items_amount' => $amount]);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        return $order->fresh();
    }

    public function test_the_day_shows_deliveries_takings_and_money_with_couriers(): void
    {
        $first = $this->deliver(10000);
        $second = $this->deliver(20000);
        $this->createOrder(['items_amount' => 5000]);
        $collected = (int) CashCollection::query()->sum('amount_collected');
        $this->assertSame(30000, $collected);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/reports/day')->assertOk()->json('data');
        $this->assertSame(['planned' => 3, 'delivered' => 2], ['planned' => $data['counts']['planned'], 'delivered' => $data['counts']['delivered']]);
        $this->assertSame(1, $data['counts']['in_progress']);
        $money = $data['money'];
        $this->assertSame($first->delivery_fee + $second->delivery_fee, $money['fees']);
        $this->assertSame([$collected, 0, $collected, 1], [$money['collected'], $money['at_desk'], $money['with_couriers'], $money['couriers_holding']]);
        $this->assertSame($collected - $money['fees'], $money['net_to_merchants']);

        // Le livreur verse l'argent : il passe à la caisse
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => $collected])->assertCreated();
        Sanctum::actingAs($this->admin);
        $money = $this->getJson('/api/v1/reports/day')->json('data.money');
        $this->assertSame([$collected, 0, 0], [$money['at_desk'], $money['with_couriers'], $money['couriers_holding']]);

        // Un autre jour : rien
        $this->getJson('/api/v1/reports/day?day=2020-01-01')->assertOk()->assertJsonPath('data.counts.delivered', 0);
    }

    public function test_money_is_hidden_without_the_cash_right_and_merchants_are_refused(): void
    {
        $this->deliver(10000);
        Sanctum::actingAs($this->dispatcher);
        $data = $this->getJson('/api/v1/reports/day')->assertOk()->json('data');
        $this->assertSame(1, $data['counts']['delivered']);
        if (! $this->dispatcher->can('finance.view')) {
            $this->assertNull($data['money']);
        }

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/reports/day')->assertForbidden();
    }

    /** Bandeau de la page Courses : ce qui manque pour que les courses soient assignées automatiquement */
    public function test_missing_settings_for_automatic_assignment_are_listed_until_done(): void
    {
        $this->company->forceFill(['auto_pickup' => 'off', 'auto_delivery' => 'trial', 'validation_mode' => 'manual', 'daily_cutoff_time' => null])->save();
        Sanctum::actingAs($this->admin);
        $keys = array_column($this->getJson('/api/v1/reports/auto-assign-checks')->assertOk()->json('data'), 'key');
        $this->assertSame(['planning', 'auto_pickup', 'auto_delivery', 'validation', 'cutoff'], $keys);

        $this->company->forceFill(['auto_pickup' => 'on', 'auto_delivery' => 'on', 'validation_mode' => 'auto', 'daily_cutoff_time' => '16:00'])->save();
        $keys = array_column($this->getJson('/api/v1/reports/auto-assign-checks')->json('data'), 'key');
        $this->assertSame(['planning'], $keys);

        // Réservé à qui peut changer les réglages
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/reports/auto-assign-checks')->assertForbidden();
    }
}
