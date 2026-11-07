<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessLiveWhatsApp;
use App\Jobs\SendLiveWhatsApp;
use App\Models\LiveChat;
use App\Models\LiveItem;
use App\Models\LiveOrder;
use App\Models\Order;
use App\Models\WhatsAppAccount;
use App\Services\Live\LiveStudio;
use App\Services\Live\LiveWhatsApp;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use App\Services\Messaging\WhatsAppBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Commandes du live par WhatsApp, sans intervention : code → nom et quartier → récapitulatif → « 1 » : course créée.
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
        // Option de la console : WhatsApp relié par code d'association / QR code, à côté de la connexion officielle
        app(LiveStudio::class)->updateSettings(['qr_whatsapp' => true]);
        $this->merchant->forceFill(['live_wa_status' => 'connected', 'live_wa_phone' => '+2250102030405'])->save();
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

    public function test_code_address_and_recap_then_the_order_leaves_only_once_confirmed(): void
    {
        // Message ordinaire : Jibiat ne répond pas (conversation personnelle)
        $this->say('Bonjour ma chérie, ça va ?');
        Queue::assertNotPushed(SendLiveWhatsApp::class);

        $this->say('a1');
        $this->assertStringContainsString('VOTRE ADRESSE', $this->replies()[0]);
        $this->assertSame(LiveChat::DETAILS, LiveChat::first()->step);

        // Quartier inconnu : on redemande
        $this->say('Awa Koné, quelque part');
        $this->assertStringContainsString('pas reconnu le quartier', $this->replies()[1]);

        // Quartier reconnu : récapitulatif à confirmer, rien n'est encore envoyé à l'agence
        $this->say('Awa Koné, Yopougon');
        $recap = $this->replies()[2];
        $this->assertStringContainsString('VOTRE COMMANDE', $recap);
        $this->assertStringContainsString('1 × *A1* Robe wax', $recap);
        $this->assertStringContainsString('*Yopougon* · Awa Koné', $recap);
        $this->assertStringContainsString('*1* ✅ Je confirme', $recap);
        $this->assertSame(0, Order::count());

        // Une réponse qui n'est pas un chiffre : on rappelle les choix
        $this->say('ok merci beaucoup ma soeur');
        $this->assertStringContainsString('RÉPONDEZ AVEC UN CHIFFRE', $this->replies()[3]);
        $this->assertSame(0, Order::count());

        $this->say('1');
        $order = Order::query()->latest('id')->first();
        $this->assertSame('Awa Koné', $order->recipient_name);
        $this->assertSame($this->yopougon->id, $order->delivery_zone_id);
        $this->assertSame('whatsapp', LiveOrder::first()->source);
        $this->assertStringContainsString('COMMANDE CONFIRMÉE', $this->replies()[4]);
        $this->assertStringContainsString($order->tracking_code, $this->replies()[4]);
        $this->assertSame(0, LiveChat::count());
        Queue::assertPushed(SendLiveWhatsApp::class, fn ($job) => $job->session === "merchant-{$this->merchant->id}" && $job->to === '+2250707000001');
    }

    public function test_misspelled_neighbourhood_is_understood_or_offered_as_a_choice(): void
    {
        $siporex = $this->zone('Siporex', $this->yopougon);
        $koumassi = $this->zone('Koumassi');
        $sicogiYop = $this->zone('Sicogi', $this->yopougon);
        $sicogiKoum = $this->zone('Sicogi', $koumassi);
        $this->zone('Riviera 2', $this->cocody);

        // Commune et quartier mal écrits : compris, le nom du client reste propre
        $this->say('a1');
        $this->say('Awa yopougn sipores');
        $this->assertStringContainsString('*Siporex* · Awa', $this->replies()[1]);
        $this->assertSame($siporex->id, LiveChat::first()->zone_id);

        // Chiffre différent : ce n'est pas le même quartier
        $this->say('3');
        $this->say('Awa riviera 4');
        $this->assertStringContainsString('pas reconnu le quartier', $this->replies()[3]);

        // Le même quartier dans deux communes : le client choisit avec un chiffre
        $this->say('Awa sicgi');
        $choice = $this->replies()[4];
        $this->assertStringContainsString('QUEL EST VOTRE QUARTIER', $choice);
        $this->assertStringContainsString('Yopougon › Sicogi', $choice);
        $this->assertStringContainsString('Koumassi › Sicogi', $choice);
        $this->assertSame(LiveChat::ZONE, LiveChat::first()->step);
        $pick = str_contains(explode("\n", explode('*2* ', $choice)[1])[0], 'Koumassi') ? 2 : 1;
        $this->say((string) $pick);
        $this->assertSame($sicogiKoum->id, LiveChat::first()->zone_id);
        $this->assertStringContainsString('*Sicogi* · Awa', $this->replies()[5]);

        // Avec la commune, plus besoin de choisir
        $this->say('3');
        $this->say('Awa Yopougon sicogie');
        $this->assertSame($sicogiYop->id, LiveChat::first()->zone_id);
        $this->assertSame(LiveChat::CONFIRM, LiveChat::first()->step);

        $this->say('1');
        $this->assertSame($sicogiYop->id, Order::query()->latest('id')->first()->delivery_zone_id);
    }

    public function test_adding_an_article_makes_one_course_and_cancelling_needs_two_answers(): void
    {
        $this->postJson("/api/v1/live/{$this->live}/products", ['name' => 'Sac cuir', 'price' => 15000, 'quantity' => 2])->assertCreated();
        $this->say('A1');
        $this->say('Awa, Yopougon');

        // 2 : ajouter un article, puis le code
        $this->say('2');
        $this->assertStringContainsString("code de l'article", $this->replies()[2]);
        $this->say('A2');
        $updated = $this->replies()[3];
        $this->assertStringContainsString('COMMANDE MISE À JOUR', $updated);
        $this->assertStringContainsString('*A1*', $updated);
        $this->assertStringContainsString('*A2* Sac cuir', $updated);

        // Confirmée : une seule course pour les deux articles
        $this->say('Je confirme');
        $this->assertSame(1, Order::count());
        $this->assertSame(2, LiveOrder::count());
        $this->assertSame(1, LiveOrder::query()->distinct()->count('order_id'));
        $this->assertSame(24000, (int) Order::first()->items_amount);

        // Autre client : 4 puis 4 annule, rien n'est envoyé à l'agence
        $this->say('A2', '+2250707000002', 'Fatou');
        $this->say('Fatou, Yopougon', '+2250707000002');
        $this->say('4', '+2250707000002');
        $this->assertStringContainsString('VOUS VOULEZ ANNULER', $this->replies()[7]);
        $this->assertSame(1, LiveChat::count());
        $this->say('ANNULER', '+2250707000002');
        $this->assertStringContainsString('COMMANDE ANNULÉE', $this->replies()[8]);
        $this->assertSame(0, LiveChat::count());
        $this->assertSame(1, Order::count());

        // 3 : changer l'adresse avant de confirmer
        $this->say('A2', '+2250707000003', 'Aya');
        $this->say('Aya, Yopougon', '+2250707000003');
        $this->say('3', '+2250707000003');
        $this->assertStringContainsString('NOUVELLE ADRESSE', $this->replies()[11]);
        $this->assertSame(LiveChat::DETAILS, LiveChat::first()->step);

        // Sans réponse pendant 30 minutes : la commande est oubliée, l'article reste disponible
        $this->travel(LiveWhatsApp::CHAT_MINUTES + 1)->minutes();
        $this->say('1', '+2250707000003');
        $this->assertSame(1, Order::count());
        $this->assertSame(1, LiveOrder::query()->where('live_item_id', LiveOrder::first()->live_item_id)->count());
    }

    public function test_the_merchant_sees_ongoing_chats_and_picks_the_neighbourhood(): void
    {
        $this->say('A1');
        $this->say('Awa Koné, quelque part', name: 'Awa');

        // Onglet « Messages » : la conversation en cours, quartier pas reconnu
        $chats = $this->getJson("/api/v1/live/{$this->live}")->assertOk()->json('data.chats');
        $this->assertCount(1, $chats);
        $this->assertTrue($chats[0]['stuck']);
        $this->assertSame('Awa Koné, quelque part', $chats[0]['last_text']);
        $this->assertSame('A1', $chats[0]['code']);

        // La vendeuse choisit le quartier : la commande part et le client reçoit la confirmation
        $data = $this->postJson("/api/v1/live/{$this->live}/chats/{$chats[0]['id']}/complete", [
            'delivery_zone_id' => $this->yopougon->id, 'recipient_name' => 'Awa Koné',
        ])->assertOk()->json('data');
        $this->assertSame([], $data['chats']);
        $this->assertSame(1, $data['totals']['orders']);
        $order = Order::query()->latest('id')->first();
        $this->assertSame(['Awa Koné', $this->yopougon->id], [$order->recipient_name, $order->delivery_zone_id]);
        $this->assertStringContainsString('COMMANDE CONFIRMÉE', collect($this->replies())->last());
    }

    public function test_known_customer_gets_the_recap_at_once_and_sold_out_goes_to_waiting_list(): void
    {
        $this->say('A1');
        $this->say('Awa, Yopougon');
        $this->say('1');

        // Deuxième commande : nom et quartier repris, récapitulatif tout de suite
        $this->say('Je veux A1 x2 svp');
        $this->assertStringContainsString('*Yopougon* · Awa', $this->replies()[3]);
        $this->assertStringContainsString('2 × *A1*', $this->replies()[3]);
        $this->say('Oui');
        $this->assertSame(2, Order::count());
        $this->assertSame(3, LiveOrder::query()->sum('quantity'));

        // Plus de stock : liste d'attente
        $this->say('A1', '+2250707000002', 'Fatou');
        $this->assertStringContainsString('épuisé', $this->replies()[5]);
        $this->say('Fatou, Yopougon', '+2250707000002');
        $this->say('1', '+2250707000002');
        $this->assertSame(LiveOrder::WAITING, LiveOrder::query()->latest('id')->first()->status);
        $this->assertStringContainsString("liste d'attente", collect($this->replies())->last());
        $this->assertSame(2, Order::count());

        // « JE PRENDS » : un seul article dans le live
        $this->say('Je prends', '+2250707000003');
        $this->assertSame(1, LiveChat::where('phone', '+2250707000003')->count());
    }

    public function test_official_number_sends_the_photos(): void
    {
        $account = WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => WhatsAppAccount::MERCHANT, 'owner_id' => $this->merchant->id, 'phone_number_id' => '9001', 'status' => 'connected']);
        LiveItem::query()->first()->product->forceFill(['photo_path' => 'produits/robe.jpg'])->save();
        $official = function (string $text) use ($account) {
            app('auth')->forgetGuards();
            app(LiveWhatsApp::class)->onOfficial($account, '+2250707000001', 'Awa', $text);
        };

        $official('A1');
        $official('Awa, Yopougon');
        // Récapitulatif : la photo et le texte en légende
        Queue::assertPushed(SendLiveWhatsApp::class, fn ($job) => str_contains($job->text, 'VOTRE COMMANDE') && str_contains((string) $job->image, '/produits/') && $job->accountId === $account->id);
        $official('1');
        Queue::assertPushed(SendLiveWhatsApp::class, fn ($job) => str_contains($job->text, 'Voici les photos de votre commande') && $job->image !== null);
    }

    public function test_official_number_sends_buttons_and_a_list(): void
    {
        $account = WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => WhatsAppAccount::MERCHANT, 'owner_id' => $this->merchant->id, 'phone_number_id' => '9001', 'status' => 'connected', 'access_token' => 'jeton']);
        LiveItem::query()->first()->product->forceFill(['photo_path' => 'produits/robe.jpg'])->save();
        $koumassi = $this->zone('Koumassi');
        $this->zone('Sicogi', $this->yopougon);
        $this->zone('Sicogi', $koumassi);
        $official = function (string $text) use ($account) {
            app('auth')->forgetGuards();
            app(LiveWhatsApp::class)->onOfficial($account, '+2250707000001', 'Awa', $text);
        };
        $sent = [];
        Http::fake(['graph.facebook.com/*' => function ($request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.x']]]);
        }]);
        $send = function (string $needle) use (&$sent) {
            $job = collect(Queue::pushed(SendLiveWhatsApp::class))->last(fn ($j) => str_contains($j->text, $needle));
            $sent = [];
            $job->handle(app(WhatsAppBridge::class), app(MetaCloudGateway::class));

            return $sent;
        };

        // Quartier qui ressemble à deux quartiers : des boutons
        $official('A1');
        $official('Awa sicgi');
        $zone = $send('QUEL EST VOTRE QUARTIER');
        $this->assertCount(1, $zone);
        $this->assertSame('button', $zone[0]['interactive']['type']);
        $this->assertEqualsCanonicalizing(['Sicogi (Yopougon)', 'Sicogi (Koumassi)'], array_column(array_column($zone[0]['interactive']['action']['buttons'], 'reply'), 'title'));

        // Récapitulatif : la photo et sa légende, puis la liste à choisir
        $official('2');
        $recap = $send('VOTRE COMMANDE');
        $this->assertSame(['image', 'interactive'], array_column($recap, 'type'));
        $this->assertStringContainsString('Touchez « Choisir »', $recap[0]['image']['caption']);
        $this->assertSame('list', $recap[1]['interactive']['type']);
        $this->assertSame(['1', '2', '3', '4'], array_column($recap[1]['interactive']['action']['sections'][0]['rows'], 'id'));

        // Annuler : un bouton pour confirmer
        $official('4');
        $cancel = $send('VOUS VOULEZ ANNULER');
        $this->assertSame('4', $cancel[0]['interactive']['action']['buttons'][0]['reply']['id']);
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
