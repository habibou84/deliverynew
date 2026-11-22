<?php

namespace Tests\Feature\Api;

use App\Enums\LedgerEntryType;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderReassignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Colis en retour réaffecté à une autre course du même e-commerçant (même article) : courses proposées, réaffectation
 * par l'e-commerçant ou l'agence, nouvelle étiquette confirmée.
 */
class OrderReassignmentTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** Robe refusée par le client : le colis est chez le livreur B, en retour */
    private function refused(string $content = '1 × Robe wax M'): Order
    {
        $order = $this->createOrder(['description' => $content, 'recipient_name' => 'Mme Konan']);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $this->as($this->courierA->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => IncidentReason::where('code', 'refused')->value('id')])->assertOk();

        return $order->fresh();
    }

    public function test_merchant_sends_a_refused_parcel_to_another_customer_of_the_same_article(): void
    {
        $refused = $this->refused();
        $this->assertTrue($refused->return_requested);
        $waiting = $this->createOrder(['description' => '1 × robe wax m', 'recipient_name' => 'Mme Bamba', 'delivery_zone_id' => $this->angre->id]);
        $this->rule($this->grid, $this->cocody, $this->angre, 1000);
        $this->createOrder(['description' => '2 × Sac']);

        // Proposé des deux côtés : la course qui attend la robe, le colis en retour qui la contient
        $this->as($this->merchantUser)->getJson("/api/v1/orders/{$refused->id}/reassign-candidates")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $waiting->id)->assertJsonPath('data.0.recipient_name', 'Mme Bamba')->assertJsonPath('data.0.zone', 'Cocody › Angré');
        $this->getJson('/api/v1/returning-parcels?description='.urlencode('Robe wax — M'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $refused->id);
        $this->getJson('/api/v1/returning-parcels?items='.urlencode(json_encode([['label' => 'Robe wax M']])))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $refused->id);
        $this->getJson('/api/v1/returning-parcels?description=Sac')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/orders/{$refused->id}/reassign", ['order_id' => $waiting->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'picked_up')
            ->assertJsonPath('data.reassigned_from.tracking_code', $refused->tracking_code)
            ->assertJsonPath('data.label_to_change', true);

        $refused->refresh();
        $waiting->refresh();
        $this->assertSame(['reassigned', $waiting->id, null], [$refused->status->value, $refused->reassigned_to_order_id, $refused->held_by_courier_id]);
        // Le colis reste chez le livreur qui l'avait : il part livrer la nouvelle course, sans ramassage
        $this->assertSame($this->courierB->id, $waiting->held_by_courier_id);
        $this->assertSame(0, $refused->assignments()->active()->count());
        // Pas de frais de retour
        $this->assertFalse(MerchantLedgerEntry::where('order_id', $refused->id)->where('type', LedgerEntryType::ReturnFee->value)->exists());
        $this->getJson("/api/v1/orders/{$refused->id}")->assertJsonPath('data.reassigned_to.tracking_code', $waiting->tracking_code)->assertJsonPath('data.status_label', 'Réaffecté');

        // Plus rien à proposer : la course a son colis, l'ancienne est terminée
        $this->getJson("/api/v1/orders/{$refused->id}/reassign-candidates")->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/orders/{$refused->id}/reassign", ['order_id' => $waiting->id])->assertUnprocessable();
    }

    public function test_agency_reassigns_and_the_merchant_is_told(): void
    {
        Notification::fake();
        $refused = $this->refused();
        $waiting = $this->createOrder(['description' => 'Robe wax M']);

        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$refused->id}/reassign", ['order_id' => $waiting->id])->assertOk();
        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'note' && $n->order->is($refused));
        $this->assertStringContainsString($waiting->tracking_code, $refused->events()->where('type', 'note')->latest('id')->value('note'));
    }

    public function test_new_label_is_confirmed_by_scan_or_its_last_four_characters(): void
    {
        $refused = $this->refused();
        $waiting = $this->createOrder(['description' => 'Robe wax M']);
        $this->as($this->merchantUser)->postJson("/api/v1/orders/{$refused->id}/reassign", ['order_id' => $waiting->id])->assertOk();

        // L'ancien code ne vaut pas confirmation
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$waiting->id}/label", ['code' => $refused->tracking_code])->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$waiting->id}/label", ['code' => strtolower(substr($waiting->tracking_code, -4))])
            ->assertOk()->assertJsonPath('data.label_to_change', false);
        $this->assertNotNull($waiting->fresh()->label_confirmed_at);

        // Un autre livreur ne confirme pas
        $this->as($this->courierA->user)->postJson("/api/v1/orders/{$waiting->id}/label", ['code' => $waiting->tracking_code])->assertForbidden();
    }

    public function test_a_new_order_can_leave_with_a_returning_parcel(): void
    {
        $refused = $this->refused();

        $this->as($this->merchantUser)->postJson('/api/v1/orders', [
            'recipient_name' => 'Mme Bamba', 'recipient_phone' => '0707070708', 'delivery_zone_id' => $this->yopougon->id,
            'delivery_address' => 'Siporex', 'items_amount' => 15000, 'fee_payer' => 'recipient', 'description' => 'Robe wax M',
            'reuse_order_id' => $refused->id,
        ])->assertCreated()->assertJsonPath('data.status', 'picked_up')->assertJsonPath('data.reassigned_from.id', $refused->id);
        $this->assertSame('reassigned', $refused->fresh()->status->value);
    }

    public function test_impossible_reassignments_are_refused(): void
    {
        $refused = $this->refused();
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]);
        $foreign = $this->createOrder(['description' => 'Robe wax M'], null, $other);
        $pickedUp = $this->refused('Robe wax M');
        $notReturning = $this->createOrder(['description' => 'Robe wax M']);

        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$refused->id}/reassign", ['order_id' => $foreign->id])->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$refused->id}/reassign", ['order_id' => $pickedUp->id])->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$notReturning->id}/reassign", ['order_id' => $refused->id])->assertUnprocessable();
        // Un autre e-commerçant ne touche pas aux courses de celui-ci
        $this->as(User::factory()->create(['company_id' => $this->company->id, 'merchant_id' => $other->id]))
            ->postJson("/api/v1/orders/{$refused->id}/reassign", ['order_id' => $notReturning->id])->assertForbidden();
    }

    public function test_labels_are_compared_without_quantities_accents_or_punctuation(): void
    {
        $this->assertSame('robe wax m', OrderReassignments::normalize('2 × Robe  wax — M'));
        $this->assertSame('cafe creme', OrderReassignments::normalize('Café crème.'));
    }
}
