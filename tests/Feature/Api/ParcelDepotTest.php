<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\ParcelDrop;
use App\Models\ParcelGap;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Couriers\ParcelDrops;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Colis rendus au dépôt : motif obligatoire pour un colis non réceptionné, livreur prévenu qui confirme ou conteste,
 * administrateur qui tranche ; dépôt en libre-service (borne, scan, photo), contrôle du bac, confirmation à 24 h.
 */
class ParcelDepotTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
        Storage::fake('local');
    }

    /** Course en échec, colis chez le livreur B */
    private function failedWithB(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $reason->id])->assertOk();

        return $order->fresh();
    }

    public function test_unchecked_parcel_needs_a_reason_and_the_courier_confirms_or_disputes(): void
    {
        Notification::fake();
        $given = $this->failedWithB();
        $hidden = $this->failedWithB();

        Sanctum::actingAs($this->cashier);
        // Colis non coché sans motif : refusé
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", ['order_ids' => [$given->id]])
            ->assertJsonValidationErrors('missing');
        $this->assertSame($this->courierB->id, $given->fresh()->held_by_courier_id);

        $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", [
            'order_ids' => [$given->id],
            'missing' => [['order_id' => $hidden->id, 'reason' => 'not_presented']],
        ])->assertOk();
        $this->assertNull($given->fresh()->held_by_courier_id);
        $this->assertSame($this->courierB->id, $hidden->fresh()->held_by_courier_id);

        // Le livreur est prévenu : colis rendus, colis non réceptionné et motif
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn ($n) => $n->kind === 'parcel_receipt'
            && str_contains($n->body, '1 colis rendu') && str_contains($n->body, $hidden->tracking_code) && str_contains($n->body, 'Pas présenté'));

        Sanctum::actingAs($this->courierB->user);
        $gapId = $this->getJson('/api/v1/courier/wallet')->assertOk()->assertJsonPath('data.parcel_gaps.0.tracking_code', $hidden->tracking_code)
            ->json('data.parcel_gaps.0.id');

        // « Je l'ai remis » : contestation, les administrateurs sont prévenus
        $this->postJson("/api/v1/courier/parcel-gaps/{$gapId}/answer", ['answer' => 'handed'])->assertOk();
        $this->postJson("/api/v1/courier/parcel-gaps/{$gapId}/answer", ['answer' => 'kept'])->assertJsonValidationErrors('answer');
        Notification::assertSentTo($this->admin, OrderAlert::class, fn ($n) => $n->kind === 'parcel_gap' && str_contains($n->title, $hidden->tracking_code));
        $this->getJson('/api/v1/courier/wallet')->assertJsonCount(0, 'data.parcel_gaps');

        // Le caissier ne tranche pas ; l'administrateur tranche : le colis était au dépôt
        Sanctum::actingAs($this->cashier);
        $this->getJson('/api/v1/parcel-gaps')->assertOk()->assertJsonPath('data.0.courier_answer', 'handed')->assertJsonPath('can_resolve', false);
        $this->postJson("/api/v1/parcel-gaps/{$gapId}/resolve", ['decision' => 'at_hub'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/parcel-gaps/{$gapId}/resolve", ['decision' => 'at_hub'])->assertOk()->assertJsonPath('data.resolution', 'at_hub');
        $this->assertNull($hidden->fresh()->held_by_courier_id);
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn ($n) => $n->kind === 'parcel_gap_decision');
        $this->getJson('/api/v1/parcel-gaps')->assertJsonPath('data.0.reporter.at_hub_30d', 1);
    }

    public function test_courier_confirms_he_still_has_the_parcel(): void
    {
        Notification::fake();
        $order = $this->failedWithB();

        Sanctum::actingAs($this->cashier);
        // « Autre » sans précision : refusé
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", [
            'order_ids' => [], 'missing' => [['order_id' => $order->id, 'reason' => 'other']],
        ])->assertJsonValidationErrors('missing');
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", [
            'order_ids' => [], 'missing' => [['order_id' => $order->id, 'reason' => 'other', 'note' => 'Oublié dans la sacoche']],
        ])->assertOk();

        $gap = ParcelGap::firstOrFail();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/courier/parcel-gaps/{$gap->id}/answer", ['answer' => 'kept'])->assertOk();
        $this->assertSame(ParcelGap::COURIER, $gap->fresh()->resolution);
        Notification::assertNotSentTo($this->admin, OrderAlert::class, fn ($n) => $n->kind === 'parcel_gap');

        // Rendu plus tard : plus rien d'ouvert
        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", ['order_ids' => [$order->id], 'missing' => []])->assertOk();
        $this->assertNull($order->fresh()->held_by_courier_id);
    }

    public function test_self_service_drop_then_control_of_the_bin(): void
    {
        Notification::fake();
        $found = $this->failedWithB();
        $lost = $this->failedWithB();

        Sanctum::actingAs($this->cashier);
        $code = $this->getJson('/api/v1/parcel-drops/kiosk')->assertOk()->json('data.code');

        Sanctum::actingAs($this->courierB->user);
        $photo = UploadedFile::fake()->image('bac.jpg');
        // QR code de la borne faux ou périmé : refusé
        $this->postJson('/api/v1/courier/parcel-drops/start', ['kiosk_code' => 'JIBIAT-DEPOT:'.$this->company->id.':1:abc'])->assertJsonValidationErrors('kiosk_code');
        $this->postJson('/api/v1/courier/parcel-drops/start', ['kiosk_code' => ParcelDrops::kioskCode($this->company->id, intdiv(time(), 30) - 5)])->assertJsonValidationErrors('kiosk_code');
        // Jeton de dépôt inventé : refusé
        $this->post('/api/v1/courier/parcel-drops', ['session' => $this->courierB->id.'.'.time().'.abc', 'order_ids' => [$found->id], 'photo' => $photo], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('session');

        $session = $this->postJson('/api/v1/courier/parcel-drops/start', ['kiosk_code' => $code])->assertOk()->json('data.session');
        // Le jeton d'un livreur ne sert pas à un autre
        Sanctum::actingAs($this->courierA->user);
        $this->post('/api/v1/courier/parcel-drops', ['session' => $session, 'order_ids' => [$found->id], 'photo' => $photo], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('session');
        Sanctum::actingAs($this->courierB->user);

        $this->post('/api/v1/courier/parcel-drops', [
            'session' => $session, 'order_ids' => [$found->id, $lost->id], 'typed_codes' => [$lost->tracking_code], 'photo' => $photo,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.count', 2);

        $drop = ParcelDrop::firstOrFail();
        $this->assertSame($drop->id, $found->fresh()->parcel_drop_id);
        // Déposés : plus à rendre, plus en alerte
        $parcels = collect($this->getJson('/api/v1/courier/wallet')->json('data.parcels'))->keyBy('id');
        $this->assertTrue($parcels[$found->id]['dropped']);
        $this->assertFalse($parcels[$found->id]['due']);

        Sanctum::actingAs($this->cashier);
        $this->getJson('/api/v1/parcel-drops')->assertOk()->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.typed_codes', [$lost->tracking_code])->assertJsonPath('data.0.has_photo', true);
        $this->get("/api/v1/parcel-drops/{$drop->id}/photo")->assertOk();

        // Contrôle : un colis introuvable sans motif est refusé
        $this->postJson("/api/v1/parcel-drops/{$drop->id}/control", ['found_ids' => [$found->id]])->assertJsonValidationErrors('missing');
        $this->postJson("/api/v1/parcel-drops/{$drop->id}/control", [
            'found_ids' => [$found->id],
            'missing' => [['order_id' => $lost->id, 'reason' => 'not_found']],
        ])->assertOk()->assertJsonPath('data.status', 'controlled');

        $this->assertNull($found->fresh()->held_by_courier_id);
        $this->assertNull($found->fresh()->parcel_drop_id);
        $this->assertSame($this->courierB->id, $lost->fresh()->held_by_courier_id);
        $this->assertSame(ParcelGap::DROP_CONTROL, ParcelGap::firstOrFail()->source);
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn ($n) => $n->kind === 'parcel_receipt'
            && str_contains($n->body, 'Introuvable dans le bac'));
    }

    public function test_uncontrolled_drop_is_confirmed_after_24_hours(): void
    {
        Notification::fake();
        $order = $this->failedWithB();

        Sanctum::actingAs($this->courierB->user);
        $session = $this->postJson('/api/v1/courier/parcel-drops/start', ['kiosk_code' => ParcelDrops::kioskCode($this->company->id)])->json('data.session');
        $this->post('/api/v1/courier/parcel-drops', [
            'session' => $session, 'order_ids' => [$order->id], 'photo' => UploadedFile::fake()->image('bac.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->artisan('parcels:depot')->assertSuccessful();
        $this->assertSame(ParcelDrop::PENDING, ParcelDrop::firstOrFail()->status);

        $this->travel(25)->hours();
        $this->artisan('parcels:depot')->assertSuccessful();
        $this->assertSame(ParcelDrop::AUTO, ParcelDrop::firstOrFail()->status);
        $this->assertNull($order->fresh()->held_by_courier_id);
        Notification::assertSentTo($this->admin, OrderAlert::class, fn ($n) => $n->kind === 'parcel_drops_auto');
    }

    public function test_courier_is_reminded_when_he_goes_unavailable(): void
    {
        Notification::fake();
        $this->failedWithB();

        Sanctum::actingAs($this->courierB->user);
        $this->patchJson('/api/v1/courier/status', ['is_available' => true])->assertOk();
        $this->patchJson('/api/v1/courier/status', ['is_available' => false])->assertOk();
        $this->patchJson('/api/v1/courier/status', ['is_available' => true])->assertOk();
        $this->patchJson('/api/v1/courier/status', ['is_available' => false])->assertOk();

        $this->assertCount(1, Notification::sent($this->courierB->user, OrderAlert::class)->filter(fn ($n) => $n->kind === 'parcel_reminder'));
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn ($n) => $n->kind === 'parcel_reminder' && str_contains($n->body, '1 colis'));
    }
}
