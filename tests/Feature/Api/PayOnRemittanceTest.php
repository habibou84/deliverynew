<?php

namespace Tests\Feature\Api;

use App\Enums\EarningType;
use App\Enums\Role;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Plan « paie déduite à chaque versement » : au point de caisse, le livreur garde ses gains non payés sur l'argent
 * encaissé (jamais plus que ce qu'il remet), une fiche « réglée sur le versement » en garde la trace ; le salaire
 * fixe reste sur la fiche de fin de période.
 */
class PayOnRemittanceTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function deliverWithCash(int $items): Order
    {
        $order = $this->createOrder(['items_amount' => $items]);
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id]);
        $this->as($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        return $order;
    }

    private function earn(int $amount, EarningType $type = EarningType::Adjustment): CourierEarning
    {
        return CourierEarning::create([
            'company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'type' => $type, 'amount' => $amount, 'description' => 'Test',
        ]);
    }

    public function test_pay_is_kept_on_the_cash_at_each_remittance(): void
    {
        $this->payPlan(['delivery' => 1000], $this->courierA, ['settle_on_remittance' => true]);
        $this->deliverWithCash(10000);
        $this->deliverWithCash(5000);
        // Salaire fixe : jamais déduit au versement
        $salary = $this->earn(40000, EarningType::Salary);

        // Le livreur voit ce qu'il gardera et la caisse aussi
        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.cash_in_hand', 15000)
            ->assertJsonPath('data.pay_deduction', 2000)
            ->assertJsonPath('data.settles_on_remittance', true);
        $this->as($this->cashier)->getJson('/api/v1/finance/cash')
            ->assertJsonPath('data.0.pay_deduction', 2000);

        // 15 000 encaissés − 2 000 de paie = 13 000 attendus
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 13000])
            ->assertCreated()
            ->assertJsonPath('data.amount_expected', 13000)
            ->assertJsonPath('data.difference', 0);

        $payout = CourierPayout::query()->sole();
        $this->assertSame([2000, true, 'paid'], [$payout->amount, $payout->compensated, $payout->status->value]);
        $this->assertNotNull($payout->remittance_id);
        $this->assertNull($salary->fresh()->payout_id);

        $this->getJson('/api/v1/finance/courier-payouts')->assertJsonPath('data.0.method_label', 'réglée sur le versement');
        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.cash_in_hand', 0)
            ->assertJsonPath('data.pay_deduction', 0)
            ->assertJsonPath('data.recent_payouts.0.settled_on_remittance', true);
    }

    public function test_never_more_than_the_cash_handed_over_the_rest_waits(): void
    {
        $this->payPlan(['delivery' => 1000], $this->courierA, ['settle_on_remittance' => true]);
        $this->deliverWithCash(3000);
        $this->earn(5000);

        // 6 000 de paie pour 3 000 encaissés : il garde 3 000, les 3 000 restants attendent
        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')->assertJsonPath('data.pay_deduction', 3000);
        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 0])
            ->assertCreated()->assertJsonPath('data.amount_expected', 0)->assertJsonPath('data.difference', 0);

        $this->assertSame(3000, CourierPayout::query()->sole()->amount);
        $this->assertSame(3000, (int) CourierEarning::query()->whereNull('payout_id')->sum('amount'));
    }

    public function test_plan_on_payslip_keeps_the_usual_remittance(): void
    {
        $this->payPlan(['delivery' => 1000], $this->courierA);
        $this->deliverWithCash(10000);

        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.pay_deduction', 0)
            ->assertJsonPath('data.settles_on_remittance', false);
        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 10000])
            ->assertCreated()->assertJsonPath('data.difference', 0);
        $this->assertSame(0, CourierPayout::query()->count());
    }

    public function test_cancelled_remittance_leaves_the_pay_with_the_courier(): void
    {
        $this->payPlan(['delivery' => 1000], $this->courierA, ['settle_on_remittance' => true]);
        $this->deliverWithCash(10000);

        $this->as($this->cashier);
        $id = $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 9000])->json('data.id');
        $this->postJson("/api/v1/finance/remittances/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        // L'argent revient chez lui, sa paie de 1 000 reste gardée : il doit 9 000
        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.cash_in_hand', 9000)
            ->assertJsonPath('data.pay_deduction', 0);
        $this->assertNull(CourierPayout::query()->sole()->remittance_id);
    }

    public function test_the_setting_is_saved_on_the_pay_plan(): void
    {
        $plan = $this->payPlan([]);
        $this->as($this->admin)->patchJson("/api/v1/pay-plans/{$plan->id}", ['settle_on_remittance' => true])
            ->assertOk()->assertJsonPath('data.settle_on_remittance', true);
    }
}
