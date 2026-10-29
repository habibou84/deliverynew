<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Legacy\LegacyOrdersImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Point de départ de l'argent chez les livreurs (courses reprises de l'ancienne application) : avant la date,
 * l'argent est compté comme remis ; un montant compté garde les encaissements les plus récents ; rien ne revient
 * avec la copie de l'ancienne application ; annulable 7 jours.
 */
class LegacyCashBaselineTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function held(Courier $courier, int $amount, string $at, bool $legacy = true): CashCollection
    {
        static $n = 0;
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::Delivered, 'delivered_at' => $at, 'legacy_id' => $legacy ? (string) (7000 + ++$n) : null])->save();

        return CashCollection::create(['company_id' => $this->company->id, 'order_id' => $order->id, 'courier_id' => $courier->id,
            'amount_expected' => $amount, 'amount_collected' => $amount, 'method' => 'cash', 'received_by_company' => false, 'collected_at' => $at]);
    }

    private function inHands(Courier $courier): int
    {
        return (int) CashCollection::withoutGlobalScopes()->inCourierHands()->where('courier_id', $courier->id)->sum('amount_collected');
    }

    public function test_starting_point_courier_by_courier_then_undo(): void
    {
        $cutoff = now()->subDays(2)->toDateString();
        $oldA = $this->held($this->courierA, 100000, now()->subMonths(3)->toDateTimeString());
        $this->held($this->courierA, 20000, now()->subMonths(2)->toDateTimeString());
        $this->held($this->courierA, 5000, now()->subDay()->toDateTimeString());
        $this->held($this->courierB, 40000, now()->subMonths(4)->toDateTimeString());
        $this->held($this->courierB, 15000, now()->subMonth()->toDateTimeString());
        $this->held($this->courierB, 10000, now()->subWeeks(2)->toDateTimeString());
        // Course de Jibiat : jamais touchée
        $this->held($this->courierB, 7000, now()->subMonths(5)->toDateTimeString(), legacy: false);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/company/legacy-cash?cutoff={$cutoff}")->assertOk()
            ->assertJsonPath('data.couriers.0.courier_id', $this->courierA->id)
            ->assertJsonPath('data.couriers.0.total', 125000)->assertJsonPath('data.couriers.0.since_total', 5000)
            ->assertJsonPath('data.couriers.1.total', 65000)->assertJsonPath('data.totals.total', 190000);

        $this->postJson('/api/v1/company/legacy-cash', ['cutoff' => $cutoff, 'confirm' => 'oui'])->assertJsonValidationErrors('confirm');

        // A : tout remis avant la date ; B : il a vraiment 25 000 F (les deux plus récents)
        $this->postJson('/api/v1/company/legacy-cash', ['cutoff' => $cutoff, 'amounts' => [$this->courierB->id => 25000], 'confirm' => 'VALIDER'])
            ->assertCreated()->assertJsonPath('data.settled_count', 3)->assertJsonPath('data.settled_amount', 160000)->assertJsonPath('data.can_undo', true);
        $this->assertSame(5000, $this->inHands($this->courierA));
        $this->assertSame(25000 + 7000, $this->inHands($this->courierB));
        $this->assertStringContainsString('point de départ', $oldA->order->events()->latest('id')->first()->note);

        // La copie de l'ancienne application ne remet pas l'argent chez le livreur
        $old = Order::withoutGlobalScopes()->find($oldA->order_id);
        $this->courierA->forceFill(['legacy_id' => '1'])->save();
        app(LegacyOrdersImporter::class)->for($this->company)->import(['id' => $old->legacy_id, 'etat' => 'termine', 'montant' => 99000, 'livraison' => 1000,
            'livreur_id' => 1, 'client_id' => 7, 'client' => ['id' => 7, 'nom' => 'Boutique', 'phone' => $this->merchant->phone], 'commune' => 'Yopougon']);
        $this->assertTrue($oldA->fresh()->received_by_company);

        // Annulation : tout revient comme avant
        $baseline = $this->getJson('/api/v1/company/legacy-cash')->json('data.last.id');
        $this->postJson("/api/v1/company/legacy-cash/{$baseline}/undo")->assertOk()->assertJsonPath('data.restored', 3)->assertJsonPath('data.status', 'undone');
        $this->assertSame(125000, $this->inHands($this->courierA));
        $this->assertSame(0, $oldA->order->events()->where('meta->legacy_cash_baseline', $baseline)->count());
        $this->postJson("/api/v1/company/legacy-cash/{$baseline}/undo")->assertStatus(422);

        // Réservé aux administrateurs
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/company/legacy-cash')->assertForbidden();
    }
}
