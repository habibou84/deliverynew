<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\CourierAbsence;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Assignment\AutoAssigner;
use App\Services\Couriers\ParcelRelays;
use App\Services\Couriers\PickupCustody;
use App\Services\Orders\PickupGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Cohérence de l'assignation (manuelle et automatique) : ramassage repris puis refusé, retour après une livraison
 * prévue, dépôt après ramassage, badge « sans livreur », relais avant un retour, colis gardés, colis ajouté à une
 * mission ramassage, avertissements du dispatch, échec de ramassage.
 */
class AssignmentConsistencyTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->company->update(['mission_acceptance' => 'choose']);
    }

    private function move(Order $order, User $actor, string $status)
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status]);
    }

    private function assign(Order $order, string $type, $courier)
    {
        Sanctum::actingAs($this->dispatcher);

        return $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => $type, 'courier_id' => $courier->id])->assertOk();
    }

    private function accept(Order $order, AssignmentType $type, User $courier): void
    {
        Sanctum::actingAs($courier);
        $this->postJson('/api/v1/courier/assignments/'.$order->fresh()->activeAssignment($type)->id.'/accept')->assertOk();
    }

    public function test_pickup_taken_from_a_courier_on_the_way_then_refused_goes_back_to_confirmed(): void
    {
        $other = $this->userWithRole(Role::Courier, ['name' => 'Yao'])->courier;
        $order = $this->createOrder();
        $this->assign($order, 'pickup', $this->courierA);
        $this->accept($order, AssignmentType::Pickup, $this->courierA->user);
        $this->move($order, $this->courierA->user, 'pickup_in_progress')->assertOk();

        $this->assign($order, 'pickup', $other);
        Sanctum::actingAs($other->user);
        $this->postJson('/api/v1/courier/assignments/'.$order->fresh()->activeAssignment(AssignmentType::Pickup)->id.'/refuse', ['reason' => 'Trop loin'])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status, 'Plus personne ne va chercher le colis : à assigner de nouveau');
        $this->assertNull($order->pickup_courier_id);
    }

    public function test_a_return_replaces_the_planned_delivery_and_its_courier(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::PickedUp, 'held_by_courier_id' => $this->courierA->id])->save();
        $this->assign($order, 'delivery', $this->courierB);
        $this->assign($order, 'return', $this->courierA);

        $order->refresh();
        $this->assertNull($order->delivery_courier_id);
        $this->assertSame(0, ParcelRelays::awaitingQuery()->whereKey($order->id)->count(), 'Pas de faux relais vers l\'ancien livreur de livraison');
    }

    public function test_the_depot_after_pickup_keeps_the_planned_delivery_and_the_badge_is_right(): void
    {
        $order = $this->createOrder();
        $this->assign($order, 'pickup', $this->courierA);
        $this->assign($order, 'delivery', $this->courierB);
        $order->assignments()->update(['status' => 'accepted']);
        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();

        // Livraison déjà confiée : pas « sans livreur »
        Sanctum::actingAs($this->dispatcher);
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.awaiting_courier', null);

        // Le ramasseur dépose le colis au dépôt : la livraison prévue reste prévue
        $this->move($order, $this->courierA->user, 'at_hub')->assertOk();
        $order->refresh();
        $this->assertSame($this->courierB->id, $order->activeAssignment(AssignmentType::Delivery)?->courier_id);
        $this->assertSame($this->courierB->id, $order->delivery_courier_id);
    }

    public function test_the_delivery_courier_bringing_the_parcel_back_gives_up_the_mission_and_is_told(): void
    {
        Notification::fake();
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::PickedUp, 'held_by_courier_id' => $this->courierB->id])->save();
        $this->assign($order, 'delivery', $this->courierB);

        // Rapporté au dépôt par le dispatch (point de caisse) : la livraison est rendue, le livreur prévenu
        $this->move($order, $this->dispatcher, 'at_hub')->assertOk();
        $order->refresh();
        $this->assertNull($order->activeAssignment(AssignmentType::Delivery));
        $this->assertNull($order->delivery_courier_id);
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'mission_removed');
    }

    public function test_a_return_also_waits_for_the_relay(): void
    {
        $this->company->update(['relay_required' => true]);
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::DeliveryFailed, 'held_by_courier_id' => $this->courierA->id, 'return_requested' => true])->save();
        $this->assign($order, 'return', $this->courierB);
        $order->assignments()->update(['status' => 'accepted']);

        $this->move($order, $this->courierB->user, 'returning')->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Colis encore chez Koffi Ramasseur : faites d\'abord le relais.');
        $this->getJson('/api/v1/courier/missions')->assertJsonPath('data.0.relay_from.name', 'Koffi Ramasseur');
        $this->getJson('/api/v1/courier/relays')->assertJsonPath('data.receive.0.parcels.0.is_return', true);

        // Relais fait : il peut rapporter le colis
        Sanctum::actingAs($this->courierA->user);
        $code = $this->postJson('/api/v1/courier/relays', ['to_courier_id' => $this->courierB->id, 'order_ids' => [$order->id]])->assertCreated()->json('data');
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/courier/relays/{$code['id']}/confirm", ['code' => $code['code'], 'order_ids' => [$order->id]])->assertOk();
        $this->move($order, $this->courierB->user, 'returning')->assertOk();
    }

    public function test_kept_parcels_are_not_given_to_an_absent_courier(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::PickedUp, 'held_by_courier_id' => $this->courierA->id, 'custody_plan' => 'keep', 'delivery_scheduled_date' => today()])->save();
        CourierAbsence::create(['company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'starts_on' => today(), 'ends_on' => today(), 'reason' => 'Congé']);

        $this->assertSame(0, app(PickupCustody::class)->assignKeptDeliveries());
        $this->assertNull($order->fresh()->activeAssignment(AssignmentType::Delivery));
    }

    public function test_a_parcel_added_to_a_pickup_mission_takes_the_same_custody(): void
    {
        $first = $this->createOrder();
        $first->forceFill(['status' => OrderStatus::Confirmed])->save();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/pickup-mission", ['order_ids' => [$first->id], 'custody' => 'keep'])->assertOk();

        $second = $this->createOrder();
        $second->forceFill(['status' => OrderStatus::Confirmed])->save();
        app(PickupGroups::class)->attachToRunningPickup($second);

        $this->assertSame('keep', $second->fresh()->custody_plan);
    }

    public function test_dispatch_is_warned_when_the_courier_is_absent_or_the_parcel_is_elsewhere(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::PickedUp, 'held_by_courier_id' => $this->courierA->id])->save();
        CourierAbsence::create(['company_id' => $this->company->id, 'courier_id' => $this->courierB->id, 'starts_on' => today(), 'ends_on' => today(), 'reason' => 'Malade']);

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk()
            ->assertJsonPath('meta.warnings', ["Awa Livreuse est absent aujourd'hui.", "{$order->tracking_code} : colis chez Koffi Ramasseur, relais à faire avant de partir."]);
    }

    public function test_a_failed_pickup_is_not_given_back_to_the_same_courier_the_same_day(): void
    {
        $order = $this->createOrder();
        $this->assign($order, 'pickup', $this->courierA);
        $this->accept($order, AssignmentType::Pickup, $this->courierA->user);
        $reason = IncidentReason::where('applies_to', '!=', 'delivery')->first();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed', 'incident_reason_id' => $reason->id])->assertOk();

        $this->assertContains($this->courierA->id, AutoAssigner::excluded($order->fresh(), AssignmentType::Pickup));
        $this->travel(1)->days();
        $this->assertNotContains($this->courierA->id, AutoAssigner::excluded($order->fresh(), AssignmentType::Pickup));
    }
}
