<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\ParcelRelay;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Relais entre livreurs : le colis ramassé par A et livré par B passe de l'un à l'autre avec un code à 4 chiffres.
 * Sans relais, B ne peut pas partir livrer (réglage « Relais obligatoire »).
 */
class ParcelRelayTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->company->update(['relay_required' => true]);
    }

    private function move(Order $order, User $actor, string $status)
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status]);
    }

    /** Ramassée par A, livraison confiée à B : le colis est chez A */
    private function heldByA(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();

        return $order->fresh();
    }

    public function test_the_delivery_waits_for_the_relay_then_the_code_moves_the_parcel(): void
    {
        Notification::fake();
        $order = $this->heldByA();

        // B ne peut pas partir : le colis est chez A
        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Colis encore chez Koffi Ramasseur : faites d\'abord le relais.');

        // Sa mission le dit, avec le livreur à rejoindre
        $this->getJson('/api/v1/courier/missions')->assertOk()
            ->assertJsonPath('data.0.relay_from.name', 'Koffi Ramasseur')
            ->assertJsonPath('meta.relays', 1);
        $this->getJson('/api/v1/courier/relays')->assertOk()
            ->assertJsonPath('data.receive.0.name', 'Koffi Ramasseur')
            ->assertJsonPath('data.receive.0.parcels.0.id', $order->id)
            ->assertJsonPath('data.receive.0.relay', null);

        // A prépare le relais : il obtient le code, B est prévenu
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/relays')->assertJsonPath('data.give.0.name', 'Awa Livreuse');
        $code = $this->postJson('/api/v1/courier/relays', ['to_courier_id' => $this->courierB->id, 'order_ids' => [$order->id]])
            ->assertCreated()->json('data.code');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);
        $relay = ParcelRelay::sole();
        $this->assertNotSame($code, $relay->getRawOriginal('code'), 'Code chiffré en base');
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'relay');
        // A retrouve son code s'il rouvre l'écran ; B ne le voit pas
        $this->getJson('/api/v1/courier/relays')->assertJsonPath('data.give.0.relay.code', $code);
        Sanctum::actingAs($this->courierB->user);
        $this->getJson('/api/v1/courier/relays')->assertJsonPath('data.receive.0.relay.id', $relay->id)
            ->assertJsonMissingPath('data.receive.0.relay.code');

        // Mauvais code : refusé, essai compté
        $wrong = $code === '0000' ? '1111' : '0000';
        $this->postJson("/api/v1/courier/relays/{$relay->id}/confirm", ['code' => $wrong, 'order_ids' => [$order->id]])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Code incorrect. Encore 4 essai(s).');

        // Bon code : le colis passe chez B, noté dans le journal (pas chez le marchand)
        $this->postJson("/api/v1/courier/relays/{$relay->id}/confirm", ['code' => $code, 'order_ids' => [$order->id]])
            ->assertOk()->assertJsonPath('data.received', 1)->assertJsonPath('data.missing', 0);
        $order->refresh();
        $this->assertSame($this->courierB->id, $order->held_by_courier_id);
        $event = $order->events()->where('type', 'relay')->sole();
        $this->assertSame('🤝 Relais : Koffi Ramasseur → Awa Livreuse', $event->note);
        $this->assertFalse($event->visible_to_merchant);
        $this->assertSame(ParcelRelay::CONFIRMED, $relay->fresh()->status);

        // B peut partir livrer
        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertOk();
        $this->getJson('/api/v1/courier/relays')->assertJsonPath('data.receive', []);
    }

    public function test_unchecked_parcels_stay_with_the_first_courier_and_dispatch_is_warned(): void
    {
        Notification::fake();
        $first = $this->heldByA();
        $second = $this->heldByA();

        Sanctum::actingAs($this->courierA->user);
        $code = $this->postJson('/api/v1/courier/relays', ['to_courier_id' => $this->courierB->id, 'order_ids' => [$first->id, $second->id]])->json('data.code');

        Sanctum::actingAs($this->courierB->user);
        $relay = ParcelRelay::sole();
        $this->postJson("/api/v1/courier/relays/{$relay->id}/confirm", ['code' => $code, 'order_ids' => [$first->id]])
            ->assertOk()->assertJsonPath('data.missing', 1);

        $this->assertSame($this->courierB->id, $first->fresh()->held_by_courier_id);
        $this->assertSame($this->courierA->id, $second->fresh()->held_by_courier_id);
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'relay_missing');
        Notification::assertSentTo($this->courierA->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'relay_missing');
    }

    public function test_the_code_expires_and_is_blocked_after_too_many_attempts(): void
    {
        $order = $this->heldByA();
        Sanctum::actingAs($this->courierA->user);
        $code = $this->postJson('/api/v1/courier/relays', ['to_courier_id' => $this->courierB->id, 'order_ids' => [$order->id]])->json('data.code');
        $relay = ParcelRelay::sole();
        $wrong = $code === '0000' ? '1111' : '0000';

        Sanctum::actingAs($this->courierB->user);
        foreach (range(1, ParcelRelay::MAX_ATTEMPTS) as $i) {
            $this->postJson("/api/v1/courier/relays/{$relay->id}/confirm", ['code' => $wrong, 'order_ids' => [$order->id]])->assertStatus(422);
        }
        $this->postJson("/api/v1/courier/relays/{$relay->id}/confirm", ['code' => $code, 'order_ids' => [$order->id]])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Code expiré : demandez à l\'autre livreur d\'afficher un nouveau code.');

        // Nouveau code : l'ancien est annulé ; expiré au bout de 15 minutes
        Sanctum::actingAs($this->courierA->user);
        $code = $this->postJson('/api/v1/courier/relays', ['to_courier_id' => $this->courierB->id, 'order_ids' => [$order->id]])->json('data.code');
        $this->assertSame(ParcelRelay::CANCELLED, $relay->fresh()->status);
        $relay = ParcelRelay::where('status', ParcelRelay::PENDING)->sole();
        $this->travel(ParcelRelay::TTL_MINUTES + 1)->minutes();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/courier/relays/{$relay->id}/confirm", ['code' => $code, 'order_ids' => [$order->id]])->assertStatus(422);
        $this->assertSame($this->courierA->id, $order->fresh()->held_by_courier_id);
    }

    public function test_only_the_holder_can_offer_and_only_the_receiver_can_confirm(): void
    {
        $order = $this->heldByA();

        // B ne peut pas « remettre » un colis qu'il n'a pas
        Sanctum::actingAs($this->courierB->user);
        $this->postJson('/api/v1/courier/relays', ['to_courier_id' => $this->courierA->id, 'order_ids' => [$order->id]])->assertStatus(422);

        Sanctum::actingAs($this->courierA->user);
        $code = $this->postJson('/api/v1/courier/relays', ['to_courier_id' => $this->courierB->id, 'order_ids' => [$order->id]])->json('data.code');
        $relay = ParcelRelay::sole();

        // A ne peut pas valider lui-même ; le dispatch passe par sa propre route
        $this->postJson("/api/v1/courier/relays/{$relay->id}/confirm", ['code' => $code, 'order_ids' => [$order->id]])->assertStatus(422);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/courier/relays/{$relay->id}/confirm", ['code' => $code, 'order_ids' => [$order->id]])->assertForbidden();
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/relays')->assertForbidden();
        $this->assertSame($this->courierA->id, $order->fresh()->held_by_courier_id);
    }

    public function test_dispatch_sees_relays_of_the_day_and_validates_for_the_couriers(): void
    {
        Notification::fake();
        $order = $this->heldByA();

        Sanctum::actingAs($this->dispatcher);
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.relay_needed', true);
        $this->getJson('/api/v1/relays')->assertOk()
            ->assertJsonPath('meta.awaiting', 1)
            ->assertJsonPath('data.awaiting.0.from.name', 'Koffi Ramasseur')
            ->assertJsonPath('data.awaiting.0.to.name', 'Awa Livreuse');

        // Motif obligatoire
        $body = ['from_courier_id' => $this->courierA->id, 'to_courier_id' => $this->courierB->id, 'order_ids' => [$order->id]];
        $this->postJson('/api/v1/relays/validate', $body)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/relays/validate', [...$body, 'reason' => 'Téléphone de Koffi déchargé'])->assertOk();

        $this->assertSame($this->courierB->id, $order->fresh()->held_by_courier_id);
        $this->assertStringContainsString('validé par le dispatch : Téléphone de Koffi déchargé', $order->events()->where('type', 'relay')->sole()->note);
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'relay');
        $this->getJson('/api/v1/relays')->assertJsonPath('meta.awaiting', 0)
            ->assertJsonPath('data.done.0.validated_by', $this->dispatcher->name)
            ->assertJsonPath('data.done.0.received', 1);
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.relay_needed', false);
    }

    public function test_without_the_setting_the_delivery_starts_without_relay(): void
    {
        $this->company->update(['relay_required' => false]);
        $order = $this->heldByA();

        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertOk();
        $this->assertSame($this->courierB->id, $order->fresh()->held_by_courier_id);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['relay_required' => true])->assertOk()->assertJsonPath('data.relay_required', true);
    }
}
