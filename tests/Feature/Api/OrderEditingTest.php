<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Models\OrderChangeRequest;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Modification des courses : aperçu du prix, validation par l'équipe des modifications de l'e-commerçant qui
 * changent le prix (réglage), livreur prévenu et « J'ai vu ».
 */
class OrderEditingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Notification::fake();
    }

    public function test_merchant_change_that_moves_the_price_waits_for_the_team(): void
    {
        $order = $this->createOrder();
        $this->assertSame(1500, $order->totalFees());
        Sanctum::actingAs($this->merchantUser);

        // Même zone : appliquée tout de suite
        $this->patchJson("/api/v1/orders/{$order->id}", ['delivery_address' => 'Siporex, pharmacie'])->assertOk()
            ->assertJsonPath('data.delivery.address', 'Siporex, pharmacie')
            ->assertJsonPath('data.edit_scope', 'all')
            ->assertJsonPath('data.pending_change', null);

        // Aperçu : nouveau tarif et validation nécessaire
        $this->postJson("/api/v1/orders/{$order->id}/edit-preview", ['delivery_zone_id' => $this->cocody->id])->assertOk()
            ->assertJsonPath('data.fees', ['from' => 1500, 'to' => 1000])
            ->assertJsonPath('data.needs_approval', true)
            ->assertJsonPath('data.changes.0.label', 'Zone de livraison');
        $this->assertSame($this->yopougon->id, $order->fresh()->delivery_zone_id);

        // Nouvelle zone : la course reste comme avant, la demande attend l'équipe
        $this->patchJson("/api/v1/orders/{$order->id}", ['delivery_zone_id' => $this->cocody->id])->assertOk()
            ->assertJsonPath('data.delivery.zone_id', $this->yopougon->id)
            ->assertJsonPath('data.pending_change.fees', ['from' => 1500, 'to' => 1000]);
        $this->assertSame(1, OrderChangeRequest::where('status', 'pending')->count());

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/orders/counts')->assertJsonPath('data.change_requests', 1);
        $this->getJson('/api/v1/orders?queue=change_requests')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/orders/{$order->id}/change-request", ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.delivery.zone_id', $this->cocody->id)
            ->assertJsonPath('data.amounts.delivery_fee', 1000)
            ->assertJsonPath('data.pending_change', null);
        $this->postJson("/api/v1/orders/{$order->id}/change-request", ['decision' => 'approve'])->assertStatus(422);
        $this->assertSame('approved', OrderChangeRequest::sole()->status);
    }

    public function test_refused_change_keeps_the_order_and_tells_why(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->merchantUser);
        $this->patchJson("/api/v1/orders/{$order->id}", ['items_amount' => 20000])->assertOk()
            ->assertJsonPath('data.amounts.items_amount', 10000);
        // Une nouvelle demande remplace la précédente
        $this->patchJson("/api/v1/orders/{$order->id}", ['items_amount' => 25000])->assertOk();
        $this->assertSame(['pending', 'replaced'], OrderChangeRequest::orderByDesc('id')->pluck('status')->all());
        // Le marchand ne décide pas lui-même
        $this->postJson("/api/v1/orders/{$order->id}/change-request", ['decision' => 'approve'])->assertForbidden();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/change-request", ['decision' => 'refuse', 'reason' => 'Appelez-nous'])->assertOk()
            ->assertJsonPath('data.amounts.items_amount', 10000);
        $this->assertTrue($order->events()->where('note', 'like', '%refusée par l\'agence : Appelez-nous%')->exists());
    }

    public function test_approval_follows_the_company_setting_and_never_applies_to_the_team(): void
    {
        $order = $this->createOrder();
        // Toujours appliquées : même une nouvelle zone
        $this->company->update(['merchant_edit_approval' => 'never']);
        Sanctum::actingAs($this->merchantUser->fresh());
        $this->patchJson("/api/v1/orders/{$order->id}", ['delivery_zone_id' => $this->cocody->id])->assertOk()
            ->assertJsonPath('data.delivery.zone_id', $this->cocody->id);

        // Toujours à valider : même une adresse
        $this->company->update(['merchant_edit_approval' => 'always']);
        Sanctum::actingAs($this->merchantUser->fresh());
        $this->patchJson("/api/v1/orders/{$order->id}", ['delivery_address' => 'Nouvelle adresse'])->assertOk()
            ->assertJsonPath('data.pending_change.changes.0.label', 'Adresse de livraison');

        // L'équipe modifie sans validation ; l'API publique (Jibiat Marchand, sites) aussi
        Sanctum::actingAs($this->dispatcher);
        $this->patchJson("/api/v1/orders/{$order->id}", ['delivery_zone_id' => $this->yopougon->id])->assertOk()
            ->assertJsonPath('data.delivery.zone_id', $this->yopougon->id);
        app(OrderService::class)->update($this->merchantUser, $order, ['recipient_name' => 'Awa']);
        $this->assertSame('Awa', $order->fresh()->recipient_name);
    }

    public function test_courier_on_mission_is_told_and_marks_it_seen(): void
    {
        $order = $this->createOrder();
        app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Pickup, $this->courierA);

        Sanctum::actingAs($this->dispatcher);
        // Rien qui le concerne (référence) : pas prévenu
        $this->patchJson("/api/v1/orders/{$order->id}", ['merchant_reference' => 'CMD-9'])->assertOk();
        Notification::assertNotSentTo($this->courierA->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'order_edited');
        // Case « prévenir le livreur » décochée
        $this->patchJson("/api/v1/orders/{$order->id}", ['recipient_phone2' => '0505050505', 'notify_courier' => false])->assertOk();
        Notification::assertNotSentTo($this->courierA->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'order_edited');

        $this->patchJson("/api/v1/orders/{$order->id}", ['delivery_address' => 'Carrefour Lubafrique'])->assertOk();
        Notification::assertSentTo($this->courierA->user, OrderAlert::class,
            fn (OrderAlert $n) => $n->kind === 'order_edited' && str_contains($n->body, 'Adresse de livraison'));

        Sanctum::actingAs($this->courierA->user);
        $mission = $this->getJson('/api/v1/courier/missions')->assertOk()->json('data.0');
        $this->assertFalse($mission['last_edit']['seen']);
        $this->assertSame('Carrefour Lubafrique', collect($mission['last_edit']['changes'])->firstWhere('field', 'delivery_address')['to']);
        $this->postJson("/api/v1/courier/assignments/{$mission['id']}/edit-seen")->assertOk();
        $this->assertTrue($this->getJson('/api/v1/courier/missions')->json('data.0.last_edit.seen'));
        // Pas la mission d'un autre
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/courier/assignments/{$mission['id']}/edit-seen")->assertForbidden();
    }
}
