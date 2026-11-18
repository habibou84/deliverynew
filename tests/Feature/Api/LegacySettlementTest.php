<?php

namespace Tests\Feature\Api;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Models\CashCollection;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\Order;
use App\Services\Finance\MerchantPayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Solder l'ancien : les sommes dues aux e-commerçants pour les courses reprises sont réglées hors Jibiat, l'argent
 * noté chez les livreurs est compté remis ; les courses Jibiat ne bougent pas ; annulable 7 jours.
 */
class LegacySettlementTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Sanctum::actingAs($this->admin);
    }

    private function delivered(?string $legacyId, int $daysAgo, int $cod = 10000, int $fee = 1500, bool $cashWithCourier = true): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['legacy_id' => $legacyId, 'status' => OrderStatus::Delivered, 'delivered_at' => now()->subDays($daysAgo)])->save();
        foreach ([[LedgerEntryType::CodCredit, $cod], [LedgerEntryType::DeliveryFee, -$fee]] as [$type, $amount]) {
            MerchantLedgerEntry::withoutGlobalScopes()->forceCreate(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'order_id' => $order->id, 'type' => $type, 'amount' => $amount, 'description' => 'test']);
        }
        CashCollection::withoutGlobalScopes()->forceCreate(['company_id' => $this->company->id, 'order_id' => $order->id, 'courier_id' => $this->courierA->id,
            'amount_expected' => $cod, 'amount_collected' => $cod, 'method' => 'cash', 'received_by_company' => ! $cashWithCourier,
            'company_confirmed_at' => $cashWithCourier ? null : now(), 'collected_at' => now()->subDays($daysAgo)]);

        return $order;
    }

    public function test_old_amounts_are_settled_outside_jibiat_and_can_be_undone(): void
    {
        $old = $this->delivered('L1', 200);
        $old2 = $this->delivered('L2', 30, cashWithCourier: false);
        $ours = $this->delivered(null, 30);
        $payouts = app(MerchantPayouts::class);
        $this->assertSame(['available' => 8500, 'pending_cash' => 17000, 'unpaid_total' => 25500], array_intersect_key($payouts->summary($this->merchant), array_flip(['available', 'pending_cash', 'unpaid_total'])));

        $this->getJson('/api/v1/company/legacy-settlement')->assertOk()
            ->assertJsonPath('data.simulation.totals.merchants', 1)->assertJsonPath('data.simulation.totals.orders', 2)
            ->assertJsonPath('data.simulation.totals.amount', 17000)->assertJsonPath('data.simulation.totals.cash_count', 1)
            ->assertJsonPath('data.simulation.merchants.0.amount', 17000);
        $this->getJson('/api/v1/company/legacy-settlement?before='.now()->subDays(100)->toDateString())->assertJsonPath('data.simulation.totals.amount', 8500);

        $this->postJson('/api/v1/company/legacy-settlement', ['before' => today()->toDateString(), 'confirm' => 'oui'])->assertJsonValidationErrors('confirm');
        Notification::fake();
        $id = $this->postJson('/api/v1/company/legacy-settlement', ['before' => today()->toDateString(), 'clear_cash' => true, 'confirm' => 'SOLDER'])
            ->assertCreated()->assertJsonPath('data.merchants', 1)->assertJsonPath('data.amount', 17000)->assertJsonPath('data.cash_count', 1)->json('data.id');
        Notification::assertNothingSent();

        // Reste seulement la course Jibiat ; l'argent de l'ancienne course n'est plus chez le livreur
        $this->assertSame(8500, $payouts->summary($this->merchant->fresh())['unpaid_total']);
        $this->assertSame(1, MerchantPayout::where('legacy_settlement_id', $id)->count());
        $this->assertTrue(CashCollection::where('order_id', $old->id)->first()->received_by_company);
        $this->assertFalse(CashCollection::where('order_id', $ours->id)->first()->received_by_company);
        $this->getJson('/api/v1/company/legacy-settlement')->assertJsonPath('data.simulation.totals.merchants', 0)->assertJsonPath('data.last.can_undo', true);

        // Annulation : tout redevient dû, l'argent revient chez le livreur
        $this->postJson("/api/v1/company/legacy-settlement/{$id}/undo")->assertOk()->assertJsonPath('data.status', 'undone');
        $this->assertSame(25500, $payouts->summary($this->merchant->fresh())['unpaid_total']);
        $this->assertFalse(CashCollection::where('order_id', $old->id)->first()->received_by_company);
        $this->assertTrue(CashCollection::where('order_id', $old2->id)->first()->received_by_company);
        $this->assertSame(0, MerchantPayout::where('legacy_settlement_id', $id)->count());
        $this->postJson("/api/v1/company/legacy-settlement/{$id}/undo")->assertStatus(422);

        // Réservé aux administrateurs
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/company/legacy-settlement')->assertForbidden();
    }
}
