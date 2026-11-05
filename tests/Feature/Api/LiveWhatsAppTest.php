<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessLiveWhatsApp;
use App\Jobs\SendLiveWhatsApp;
use App\Models\LiveChat;
use App\Models\LiveOrder;
use App\Models\Order;
use App\Services\Live\LiveWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Commandes du live par WhatsApp, sans intervention : code → nom et quartier → course créée et réponse automatique.
 */
class LiveWhatsAppTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private int $live;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['messaging.whatsapp.bridge.token' => 'secret-bridge', 'messaging.whatsapp.bridge.url' => 'http://bridge.test']);
        Queue::fake();
        Sanctum::actingAs($this->merchantUser);
        $this->live = $this->postJson('/api/v1/live/prepare')->assertOk()->json('data.live.id');
        $this->postJson("/api/v1/live/{$this->live}/products", ['name' => 'Robe wax', 'price' => 9000, 'quantity' => 3])->assertCreated();
        $this->postJson("/api/v1/live/{$this->live}/start")->assertOk();
    }

    private function say(string $text, string $from = '+2250707000001', ?string $name = 'Awa'): void
    {
        // Comme dans la file : personne n'est connecté
        app('auth')->forgetGuards();
        app(LiveWhatsApp::class)->onMessage($this->merchant->id, $from, $name, $text);
        Sanctum::actingAs($this->merchantUser);
    }

    /** @return list<string> */
    private function replies(): array
    {
        $texts = [];
        Queue::assertPushed(SendLiveWhatsApp::class, function (SendLiveWhatsApp $job) use (&$texts) {
            $texts[] = $job->text;

            return true;
        });

        return $texts;
    }

    public function test_code_then_name_and_neighbourhood_creates_the_order_without_the_merchant(): void
    {
        // Message ordinaire : Jibiat ne répond pas (conversation personnelle)
        $this->say('Bonjour ma chérie, ça va ?');
        Queue::assertNotPushed(SendLiveWhatsApp::class);

        $this->say('a1');
        $this->assertStringContainsString('Pour la livraison', $this->replies()[0]);
        $this->assertSame(LiveChat::DETAILS, LiveChat::first()->step);

        // Quartier inconnu : on redemande
        $this->say('Awa Koné, quelque part');
        $this->assertStringContainsString('pas reconnu le quartier', $this->replies()[1]);

        $this->say('Awa Koné, Yopougon');
        $order = Order::query()->latest('id')->first();
        $this->assertSame('Awa Koné', $order->recipient_name);
        $this->assertSame($this->yopougon->id, $order->delivery_zone_id);
        $this->assertSame('whatsapp', LiveOrder::first()->source);
        $this->assertStringContainsString("C'est noté, Awa", $this->replies()[2]);
        $this->assertSame(0, LiveChat::count());
        Queue::assertPushed(SendLiveWhatsApp::class, fn ($job) => $job->session === "merchant-{$this->merchant->id}" && $job->to === '+2250707000001');
    }

    public function test_known_customer_confirms_with_yes_and_sold_out_goes_to_waiting_list(): void
    {
        $this->say('A1');
        $this->say('Awa, Yopougon');

        // Deuxième commande : nom et quartier proposés, « oui » suffit
        $this->say('Je veux A1 x2 svp');
        $this->assertStringContainsString('Je livre à *Awa*, *Yopougon*', $this->replies()[2]);
        $this->say('Oui');
        $this->assertSame(2, Order::count());
        $this->assertSame(3, LiveOrder::query()->sum('quantity'));

        // Plus de stock : liste d'attente
        $this->say('A1', '+2250707000002', 'Fatou');
        $this->assertStringContainsString('épuisé', $this->replies()[4]);
        $this->say('Fatou, Yopougon', '+2250707000002');
        $this->assertSame(LiveOrder::WAITING, LiveOrder::query()->latest('id')->first()->status);
        $this->assertStringContainsString("liste d'attente", $this->replies()[5]);
        $this->assertSame(2, Order::count());

        // « JE PRENDS » : un seul article dans le live
        $this->say('Je prends', '+2250707000003');
        $this->assertSame(1, LiveChat::where('phone', '+2250707000003')->count());
    }

    public function test_nothing_happens_without_a_live_in_progress(): void
    {
        $this->postJson("/api/v1/live/{$this->live}/end")->assertOk();
        $this->say('A1');
        Queue::assertNotPushed(SendLiveWhatsApp::class);
        $this->assertSame(0, LiveChat::count());
    }

    public function test_bridge_events_and_connection(): void
    {
        $sign = fn (array $body) => ['X-Bridge-Signature' => hash_hmac('sha256', json_encode($body), 'secret-bridge')];
        $session = "merchant-{$this->merchant->id}";

        $status = ['type' => 'status', 'session' => $session, 'status' => 'connected', 'phone' => '+2250102030405'];
        $this->call('POST', '/api/webhooks/whatsapp-bridge', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BRIDGE_SIGNATURE' => $sign($status)['X-Bridge-Signature']], json_encode($status))->assertOk();
        $this->assertSame('connected', $this->merchant->refresh()->live_wa_status);
        // La carte affiche le numéro relié
        $this->assertSame('+2250102030405', $this->postJson('/api/v1/live/prepare')->json('data.offer.phone'));

        $message = ['type' => 'message', 'session' => $session, 'id' => 'm1', 'from' => '+2250707000001', 'name' => 'Awa', 'kind' => 'text', 'text' => 'A1'];
        foreach ([1, 2] as $_) {
            $this->call('POST', '/api/webhooks/whatsapp-bridge', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BRIDGE_SIGNATURE' => $sign($message)['X-Bridge-Signature']], json_encode($message))->assertOk();
        }
        // Une seule fois, même si le service renvoie l'événement
        Queue::assertPushed(ProcessLiveWhatsApp::class, 1);

        // Relier par code d'association
        Http::fake(['bridge.test/sessions/*/start' => Http::response(['status' => 'qr', 'qr' => 'data:image/png;base64,AAA', 'pairing_code' => 'ABCD-1234'])]);
        $this->postJson('/api/v1/live/whatsapp/connect', ['phone' => '0102030405'])->assertOk()->assertJsonPath('data.pairing_code', 'ABCD-1234');
        Http::assertSent(fn ($request) => $request['phone'] === '2250102030405');
    }
}
