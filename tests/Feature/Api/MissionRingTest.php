<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Jobs\RingCourier;
use App\Models\CourierDevice;
use App\Models\OrderAssignment;
use App\Services\Couriers\MissionRing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Sonnerie des missions (application Android) : le téléphone du livreur sonne comme un appel pour un nouveau
 * ramassage ou une nouvelle livraison, une seule fois par lot, de nouveau deux minutes après sans réponse.
 */
class MissionRingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        // Compte de service Firebase de test (clé générée pour le test)
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->credentials = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->credentials, json_encode(['project_id' => 'jibiat-test', 'client_email' => 'push@jibiat-test.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['services.fcm.credentials' => $this->credentials]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/jibiat-test/messages/1']),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentials);
        parent::tearDown();
    }

    private function registerPhone(): void
    {
        Sanctum::actingAs($this->courierA->user);
        $this->postJson('/api/v1/courier/devices', ['token' => 'tok-A', 'platform' => 'android'])->assertOk();
    }

    /** @return list<array<string, string>> données des messages envoyés à Firebase */
    private function rings(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))
            ->map(fn ($pair) => $pair[0]->data()['message']['data'])->values()->all();
    }

    private function assignPickup(array $orders): void
    {
        Sanctum::actingAs($this->dispatcher);
        foreach ($orders as $order) {
            $order->forceFill(['status' => OrderStatus::Confirmed])->save();
        }
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/pickup-mission", ['order_ids' => collect($orders)->pluck('id')->all(), 'custody' => 'depot'])->assertOk();
    }

    public function test_a_pickup_mission_rings_once_then_again_after_two_minutes(): void
    {
        Queue::fake();
        $this->registerPhone();
        $this->assignPickup([$this->createOrder(), $this->createOrder(), $this->createOrder()]);

        // Une seule sonnerie pour le lot, quelques secondes après
        Queue::assertPushed(RingCourier::class, 1);
        Queue::assertPushed(RingCourier::class, fn (RingCourier $job) => $job->courierId === $this->courierA->id && $job->attempt === 1);
        (new RingCourier($this->courierA->id, 1))->handle(app(MissionRing::class));

        $rings = $this->rings();
        $this->assertCount(1, $rings);
        $this->assertSame(['ring', '1', 'Nouveau ramassage'], [$rings[0]['type'], $rings[0]['attempt'], $rings[0]['title']]);
        $this->assertStringContainsString('3 colis', $rings[0]['line2']);
        $this->assertSame(3, count(explode(',', $rings[0]['ids'])));
        $this->assertStringStartsWith('/livreur/appel?ids=', $rings[0]['path']);
        Queue::assertPushed(RingCourier::class, fn (RingCourier $job) => $job->attempt === 2);

        // Pas de réponse : deuxième sonnerie
        $this->travel(2)->minutes();
        (new RingCourier($this->courierA->id, 2))->handle(app(MissionRing::class));
        $this->assertSame('2', $this->rings()[1]['attempt']);

        // Puis plus rien : l'alerte « injoignable » prend le relais
        (new RingCourier($this->courierA->id, 2))->handle(app(MissionRing::class));
        $this->assertCount(2, $this->rings());
    }

    public function test_answering_stops_the_ring_and_the_reminder(): void
    {
        Queue::fake();
        $this->registerPhone();
        $this->assignPickup([$this->createOrder()]);
        (new RingCourier($this->courierA->id, 1))->handle(app(MissionRing::class));
        $ids = $this->rings()[0]['ids'];

        // Écran « appel entrant »
        Sanctum::actingAs($this->courierA->user);
        $this->getJson("/api/v1/courier/ring?ids={$ids}")->assertOk()
            ->assertJsonPath('data.title', 'Nouveau ramassage')
            ->assertJsonPath('data.missions.0.type', 'pickup');

        // « J'ai vu » : arrêt de la sonnerie sur ses téléphones, pas de rappel
        $this->postJson('/api/v1/courier/ring/answer', ['ids' => $ids])->assertOk()->assertJsonPath('data.answered', 1);
        $this->assertSame('ring_stop', $this->rings()[1]['type']);
        $this->travel(2)->minutes();
        (new RingCourier($this->courierA->id, 2))->handle(app(MissionRing::class));
        $this->assertCount(2, $this->rings());

        // Les missions d'un autre livreur ne se lisent pas
        Sanctum::actingAs($this->courierB->user);
        $this->getJson("/api/v1/courier/ring?ids={$ids}")->assertOk()->assertJsonPath('data', null);
    }

    public function test_accepting_a_delivery_answers_the_ring(): void
    {
        Queue::fake();
        $this->company->update(['mission_acceptance' => 'choose']);
        $this->registerPhone();
        $order = $this->createOrder(['recipient_name' => 'Awa Koné', 'cod_amount' => 13500]);
        $order->forceFill(['status' => OrderStatus::AtHub])->save();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id])->assertOk();
        (new RingCourier($this->courierA->id, 1))->handle(app(MissionRing::class));
        $this->assertSame('Nouvelle livraison', $this->rings()[0]['title']);
        $this->assertStringContainsString('Awa Koné', $this->rings()[0]['line1']);

        $assignment = OrderAssignment::where('order_id', $order->id)->firstOrFail();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/courier/assignments/{$assignment->id}/accept")->assertOk();
        $this->assertNotNull($assignment->fresh()->ring_answered_at);
    }

    public function test_no_phone_or_no_firebase_means_no_ring_and_dead_tokens_are_forgotten(): void
    {
        Queue::fake();
        // Pas de téléphone enregistré : rien
        $this->assignPickup([$this->createOrder()]);
        Queue::assertNotPushed(RingCourier::class);

        // Jeton périmé : oublié
        $this->registerPhone();
        Http::swap(new Factory);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']), 'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)]);
        $this->assignPickup([$this->createOrder()]);
        (new RingCourier($this->courierA->id, 1))->handle(app(MissionRing::class));
        $this->assertSame(0, CourierDevice::count());

        // Sans Firebase configuré : rien
        config(['services.fcm.credentials' => null]);
        $this->registerPhone();
        Queue::fake();
        $this->assignPickup([$this->createOrder()]);
        Queue::assertNotPushed(RingCourier::class);
    }
}
