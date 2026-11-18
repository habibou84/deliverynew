<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Jobs\RunLegacyPurge;
use App\Jobs\SendOutboundMessage;
use App\Jobs\SendWebhook;
use App\Models\CashCollection;
use App\Models\LegacyPurge;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OutboundMessage;
use App\Services\Legacy\LegacyOrdersImporter;
use App\Services\Legacy\LegacyOrdersPurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Nettoyage des anciennes courses reprises : jamais ramassées annulées, non livrées retournées (à rendre si un livreur
 * les a), en chemin à vérifier, livrées non soldées laissées à la caisse ; sans message ; annulable 7 jours.
 */
class LegacyPurgeTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function legacy(OrderStatus $status, string $day, array $extra = []): Order
    {
        static $n = 0;
        $order = $this->createOrder();
        $order->forceFill(['legacy_id' => (string) (9000 + ++$n), 'status' => $status, 'delivery_scheduled_date' => $day, ...$extra])->save();

        return $order->fresh();
    }

    private function purge(array $include = ['never_picked', 'not_delivered']): void
    {
        Queue::fake();
        $this->postJson('/api/v1/company/legacy-purge?days=30', ['include' => $include, 'confirm' => 'CLÔTURER'])->assertAccepted();
        $purge = LegacyPurge::query()->latest('id')->firstOrFail();
        while ($purge->fresh()->status === 'running') {
            (new RunLegacyPurge($purge->id))->handle(app(LegacyOrdersPurge::class));
        }
    }

    public function test_old_returns_left_with_a_courier_are_closed_as_returned_and_can_be_undone(): void
    {
        Sanctum::actingAs($this->admin);
        $old = today()->subDays(60)->toDateString();
        // Laissés « à rendre » chez un livreur par le nettoyage, ou au dépôt ; une course d'aujourd'hui n'est pas concernée
        $heldA = $this->legacy(OrderStatus::DeliveryFailed, $old, ['return_requested' => true, 'held_by_courier_id' => $this->courierA->id]);
        $heldA2 = $this->legacy(OrderStatus::DeliveryFailed, $old, ['return_requested' => true, 'held_by_courier_id' => $this->courierA->id]);
        $atHub = $this->legacy(OrderStatus::AtHub, $old, ['return_requested' => true]);
        $fresh = $this->createOrder();
        $fresh->forceFill(['status' => OrderStatus::DeliveryFailed, 'return_requested' => true])->save();

        $this->assertSame(3, $this->getJson('/api/v1/orders/counts')->json('data.exceptions.untreated'));
        $this->getJson('/api/v1/company/legacy-purge/returns')->assertOk()->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.couriers.0.courier_id', $this->courierA->id)->assertJsonPath('data.couriers.0.count', 2);

        // Confirmation obligatoire, puis ceux du livreur A seulement
        $this->postJson('/api/v1/company/legacy-purge/returns', ['courier_id' => $this->courierA->id, 'confirm' => 'non'])->assertJsonValidationErrors('confirm');
        $id = $this->postJson('/api/v1/company/legacy-purge/returns', ['courier_id' => $this->courierA->id, 'confirm' => 'CLÔTURER'])
            ->assertCreated()->assertJsonPath('data.done', 2)->json('data.id');

        $this->assertSame(OrderStatus::Returned, $heldA->fresh()->status);
        $this->assertNull($heldA->fresh()->held_by_courier_id);
        $this->assertSame(OrderStatus::AtHub, $atHub->fresh()->status);
        $this->assertSame(OrderStatus::DeliveryFailed, $fresh->fresh()->status);
        $this->assertSame(1, $this->getJson('/api/v1/orders/counts')->json('data.exceptions.untreated'));
        $this->assertSame(0, OutboundMessage::count());

        // Annulable : les colis redeviennent à rendre chez le livreur
        $this->postJson("/api/v1/company/legacy-purge/{$id}/undo")->assertOk()->assertJsonPath('data.restored', 2);
        $this->assertSame(OrderStatus::DeliveryFailed, $heldA2->fresh()->status);
        $this->assertSame($this->courierA->id, $heldA2->fresh()->held_by_courier_id);

        // Tous d'un coup
        $this->postJson('/api/v1/company/legacy-purge/returns', ['confirm' => 'CLÔTURER'])->assertCreated()->assertJsonPath('data.done', 3);
        $this->getJson('/api/v1/company/legacy-purge/returns')->assertJsonPath('data.total', 0);
    }

    public function test_old_courses_are_closed_without_any_message_and_can_be_undone(): void
    {
        $old = now()->subDays(45)->toDateString();
        $never = $this->legacy(OrderStatus::Confirmed, $old);
        $atHub = $this->legacy(OrderStatus::AtHub, $old);
        $held = $this->legacy(OrderStatus::PickedUp, $old, ['held_by_courier_id' => $this->courierA->id, 'held_since' => now()->subDays(40)]);
        $assignment = OrderAssignment::create(['order_id' => $held->id, 'courier_id' => $this->courierA->id, 'type' => AssignmentType::Delivery, 'status' => AssignmentStatus::Accepted, 'assigned_at' => now()->subDays(40)]);
        $onTheWay = $this->legacy(OrderStatus::OutForDelivery, $old, ['delivery_courier_id' => $this->courierA->id]);
        $recent = $this->legacy(OrderStatus::Confirmed, now()->subDays(5)->toDateString());
        $ours = $this->createOrder();
        $ours->forceFill(['status' => OrderStatus::Confirmed, 'delivery_scheduled_date' => $old])->save();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/legacy-purge?days=30')->assertOk()
            ->assertJsonPath('data.simulation', ['never_picked' => 1, 'not_delivered' => 2, 'held' => 1, 'on_the_way' => 1, 'unsettled' => 0, 'total' => 3]);
        $this->postJson('/api/v1/company/legacy-purge?days=30', ['include' => ['never_picked'], 'confirm' => 'oui'])->assertJsonValidationErrors('confirm');
        Notification::fake();
        Bus::fake([SendOutboundMessage::class, SendWebhook::class]);
        $this->purge();

        $this->assertSame(OrderStatus::Cancelled, $never->fresh()->status);
        $this->assertSame(OrderStatus::Returned, $atHub->fresh()->status);
        // Colis chez un livreur : retour décidé, à rendre ; sa mission de livraison est arrêtée
        $this->assertSame([OrderStatus::DeliveryFailed, true, $this->courierA->id], [$held->fresh()->status, $held->fresh()->return_requested, $held->fresh()->held_by_courier_id]);
        $this->assertSame(AssignmentStatus::Cancelled, $assignment->fresh()->status);
        // Pas touchées : en chemin, récente, pas venue de l'ancienne application
        $this->assertSame(OrderStatus::OutForDelivery, $onTheWay->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $recent->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $ours->fresh()->status);
        $this->assertStringContainsString('nettoyage', $never->events()->latest('id')->first()->note);
        Notification::assertNothingSent();
        Bus::assertNotDispatched(SendOutboundMessage::class);
        Bus::assertNotDispatched(SendWebhook::class);
        $this->getJson('/api/v1/company/legacy-purge')->assertJsonPath('data.last.status', 'done')
            ->assertJsonPath('data.last.done', 3)->assertJsonPath('data.last.can_undo', true);

        // L'ancienne application ne la rouvre pas
        $result = app(LegacyOrdersImporter::class)->for($this->company)->import(['id' => $never->legacy_id, 'etat' => 'en cours',
            'client_id' => 7, 'client' => ['id' => 7, 'nom' => 'Boutique', 'phone' => $this->merchant->phone], 'commune' => 'Yopougon']);
        $this->assertSame('unchanged', $result['action']);

        // Annulation : tout revient comme avant
        $purge = LegacyPurge::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/company/legacy-purge/{$purge->id}/undo")->assertOk()->assertJsonPath('data.restored', 3);
        $this->assertSame(OrderStatus::Confirmed, $never->fresh()->status);
        $this->assertSame([OrderStatus::PickedUp, false], [$held->fresh()->status, $held->fresh()->return_requested]);
        $this->assertSame(AssignmentStatus::Accepted, $assignment->fresh()->status);
        $this->assertNull($never->fresh()->legacy_purged_at);
        $this->postJson("/api/v1/company/legacy-purge/{$purge->id}/undo")->assertStatus(422);

        // Plus de 7 jours : plus annulable
        $this->purge();
        $this->travel(8)->days();
        $this->getJson('/api/v1/company/legacy-purge')->assertJsonPath('data.last.can_undo', false);
    }

    public function test_everything_left_from_the_old_application_can_be_cleaned_whatever_its_age(): void
    {
        Sanctum::actingAs($this->admin);
        $yesterday = today()->subDay()->toDateString();
        $never = $this->legacy(OrderStatus::Confirmed, $yesterday);
        $held = $this->legacy(OrderStatus::PickedUp, today()->subDays(300)->toDateString(), ['held_by_courier_id' => $this->courierA->id]);
        $onTheWay = $this->legacy(OrderStatus::OutForDelivery, today()->subDays(300)->toDateString(), ['delivery_courier_id' => $this->courierA->id]);
        $today = $this->legacy(OrderStatus::Confirmed, today()->toDateString());
        $this->assertSame(3, $this->getJson('/api/v1/orders/counts')->json('data.exceptions.untreated'));

        Queue::fake();
        Notification::fake();
        Bus::fake([SendOutboundMessage::class, SendWebhook::class]);
        // Sans âge précisé : toutes les dates avant aujourd'hui, en chemin et colis chez un livreur compris
        $this->postJson('/api/v1/company/legacy-purge', ['include' => ['never_picked', 'not_delivered', 'held_returned', 'on_the_way'], 'confirm' => 'CLÔTURER'])->assertAccepted();
        $purge = LegacyPurge::query()->latest('id')->firstOrFail();
        while ($purge->fresh()->status === 'running') {
            (new RunLegacyPurge($purge->id))->handle(app(LegacyOrdersPurge::class));
        }

        $this->assertSame(OrderStatus::Cancelled, $never->fresh()->status);
        $this->assertSame(OrderStatus::Returned, $held->fresh()->status);
        $this->assertSame(OrderStatus::Returned, $onTheWay->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $today->fresh()->status);
        $this->assertSame(0, $this->getJson('/api/v1/orders/counts')->json('data.exceptions.untreated'));
        Bus::assertNotDispatched(SendOutboundMessage::class);

        // Annulable comme les autres
        $this->postJson("/api/v1/company/legacy-purge/{$purge->id}/undo")->assertOk()->assertJsonPath('data.restored', 3);
        $this->assertSame([OrderStatus::OutForDelivery, OrderStatus::PickedUp], [$onTheWay->fresh()->status, $held->fresh()->status]);
    }

    public function test_courses_on_the_way_are_checked_courier_by_courier(): void
    {
        $old = now()->subDays(40)->toDateString();
        $delivered = $this->legacy(OrderStatus::OutForDelivery, $old, ['delivery_courier_id' => $this->courierA->id, 'cod_amount' => 11500, 'delivery_fee' => 1500]);
        $returned = $this->legacy(OrderStatus::OutForDelivery, $old, ['delivery_courier_id' => $this->courierA->id, 'held_by_courier_id' => $this->courierA->id]);
        $this->legacy(OrderStatus::OutForDelivery, $old, ['delivery_courier_id' => $this->courierB->id]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/legacy-purge/on-the-way?days=30')->assertOk()
            ->assertJsonPath('data.couriers.0.courier_id', $this->courierA->id)->assertJsonPath('data.couriers.0.count', 2)
            ->assertJsonPath('data.orders', null);
        $this->getJson("/api/v1/company/legacy-purge/on-the-way?days=30&courier_id={$this->courierA->id}")
            ->assertJsonCount(2, 'data.orders')->assertJsonPath('data.total', 2);

        // Livrée : argent chez le livreur, dû à l'e-commerçant
        $this->postJson('/api/v1/company/legacy-purge/on-the-way?days=30', ['ids' => [$delivered->id], 'outcome' => 'delivered'])->assertOk()->assertJsonPath('data.done', 1);
        $this->assertSame(OrderStatus::Delivered, $delivered->fresh()->status);
        $this->assertTrue(CashCollection::where('order_id', $delivered->id)->inCourierHands()->where('courier_id', $this->courierA->id)->where('amount_collected', 11500)->exists());
        $this->assertSame(10000, (int) MerchantLedgerEntry::where('order_id', $delivered->id)->sum('amount'));

        // Retournée : le livreur a encore le colis, à rendre
        $this->postJson('/api/v1/company/legacy-purge/on-the-way?days=30', ['ids' => [$returned->id], 'outcome' => 'returned'])->assertOk();
        $this->assertSame([OrderStatus::DeliveryFailed, true], [$returned->fresh()->status, $returned->fresh()->return_requested]);
        $this->assertNotNull($returned->fresh()->legacy_purged_at);
        $this->getJson('/api/v1/company/legacy-purge/on-the-way?days=30')->assertJsonCount(1, 'data.couriers');

        // Livrée, argent encore chez le livreur : comptée à part, jamais clôturée
        $unsettled = $this->legacy(OrderStatus::Delivered, $old);
        CashCollection::create(['company_id' => $this->company->id, 'order_id' => $unsettled->id, 'courier_id' => $this->courierB->id,
            'amount_expected' => 5000, 'amount_collected' => 5000, 'method' => 'cash', 'received_by_company' => false, 'collected_at' => now()->subDays(40)]);
        $this->getJson('/api/v1/company/legacy-purge?days=30')->assertJsonPath('data.simulation.unsettled', 1)->assertJsonPath('data.simulation.total', 0);

        // Réservé aux administrateurs
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/company/legacy-purge')->assertForbidden();
    }
}
