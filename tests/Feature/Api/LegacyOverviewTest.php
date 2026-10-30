<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Accueil de la reprise : étapes faites ou non, et ce qui reste à vérifier.
 */
class LegacyOverviewTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_nothing_started(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/legacy-overview')->assertOk()
            ->assertJsonPath('data.connect', ['accounts' => false, 'orders' => false, 'switch' => false])
            ->assertJsonPath('data.orders.imported_at', null)
            ->assertJsonPath('data.todo.on_the_way', 0)
            ->assertJsonPath('data.todo.balances', false)
            ->assertJsonPath('data.todo.cash', false);
    }

    public function test_steps_done_and_what_is_left_to_check(): void
    {
        $this->company->forceFill([
            'legacy_sync_url' => 'https://ancienne.example.com', 'legacy_sync_key' => 'jbr_x', 'legacy_switch_key_hash' => hash('sha256', 'k'),
            'legacy_orders_imported_at' => now()->subDays(40), 'legacy_sync_enabled' => true, 'legacy_switch_at' => now()->addDays(3),
        ])->save();
        $late = $this->createOrder();
        $late->forceFill(['legacy_id' => '9001', 'status' => OrderStatus::OutForDelivery, 'delivery_courier_id' => $this->courierA->id, 'delivery_scheduled_date' => now()->subDays(45)->toDateString()])->save();
        $deleted = $this->createOrder();
        $deleted->forceFill(['legacy_id' => '9002', 'status' => OrderStatus::Delivered, 'delivered_at' => now()->subDays(2), 'legacy_deleted_at' => now()])->save();

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/company/legacy-overview')->assertOk()->json('data');
        $this->assertSame(['accounts' => false, 'orders' => true, 'switch' => true], $data['connect']);
        $this->assertSame(2, $data['orders']['count']);
        $this->assertFalse($data['switch']['started']);
        $this->assertSame(1, $data['todo']['on_the_way']);
        $this->assertTrue($data['todo']['balances']);
        $this->assertSame(1, $data['todo']['deleted']);

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/company/legacy-overview')->assertForbidden();
    }
}
