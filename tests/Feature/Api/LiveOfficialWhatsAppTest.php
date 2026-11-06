<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Jobs\ProcessInboundWhatsApp;
use App\Jobs\ProcessLiveWhatsApp;
use App\Jobs\SendLiveWhatsApp;
use App\Models\LiveOrder;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Live\LiveStudio;
use App\Services\Live\LiveWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Commandes du live sur le numéro WhatsApp Business de l'e-commerçante, relié par la connexion officielle de Meta
 * (par défaut). Le code d'association / QR code n'apparaît que si la console le propose.
 */
class LiveOfficialWhatsAppTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private int $live;

    /** @var list<int> Messages reçus déjà traités */
    private array $handled = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config([
            'messaging.whatsapp.driver' => 'auto',
            'messaging.whatsapp.app_id' => '1111',
            'messaging.whatsapp.signup_config_id' => '2222',
            'messaging.whatsapp.app_secret' => 'app-secret',
            'messaging.whatsapp.bridge.token' => 'secret-bridge',
            'messaging.whatsapp.bridge.url' => 'http://bridge.test',
        ]);
        Queue::fake();
        Sanctum::actingAs($this->merchantUser);
        $this->live = $this->postJson('/api/v1/live/prepare')->assertOk()->json('data.live.id');
        $this->postJson("/api/v1/live/{$this->live}/products", ['name' => 'Robe wax', 'price' => 9000, 'quantity' => 3])->assertCreated();
    }

    /** Fenêtre de Meta : l'e-commerçante relie son numéro WhatsApp Business */
    private function connectOfficially(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'jeton-boutique', 'token_type' => 'bearer']),
            '*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => now()->addDays(60)->timestamp]]),
            '*/888/subscribed_apps' => Http::response(['success' => true]),
            '*/666/register' => Http::response(['success' => true]),
            '*/666*' => Http::response(['display_phone_number' => '+225 07 07 08 09 10', 'verified_name' => 'Boutique Test', 'quality_rating' => 'GREEN']),
        ]);
        $url = $this->postJson('/api/v1/live/whatsapp/official')->assertOk()->json('data.url');
        $ticket = explode('#', $url, 2)[1];

        app('auth')->forgetGuards();
        $this->postJson('/api/v1/whatsapp-signup/session', ['ticket' => $ticket])->assertOk()
            ->assertJsonPath('data.company', 'Boutique Test')
            ->assertJsonPath('data.merchant', true)
            ->assertJsonPath('data.return', '/marchand/live/preparer');
        $this->postJson('/api/v1/whatsapp-signup/complete', ['ticket' => $ticket, 'code' => 'code-meta', 'phone_number_id' => '666', 'waba_id' => '888'])
            ->assertOk()->assertJsonPath('data.display_phone', '+2250707080910');
        Sanctum::actingAs($this->merchantUser);
    }

    /** Message reçu par Meta sur le numéro de la boutique */
    private function meta(string $id, string $text): void
    {
        app('auth')->forgetGuards();
        // Signé avec le secret de l'application Meta, comme les vrais envois de Meta
        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '666'],
            'contacts' => [['profile' => ['name' => 'Awa']]],
            'messages' => [['id' => $id, 'from' => '2250707000001', 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body)->assertOk();
        Sanctum::actingAs($this->merchantUser);
    }

    /**
     * Traite les messages reçus (file), puis renvoie les réponses envoyées par le numéro relié.
     *
     * @return list<string>
     */
    private function process(): array
    {
        foreach (Queue::pushed(ProcessLiveWhatsApp::class) as $job) {
            if (! in_array(spl_object_id($job), $this->handled, true)) {
                $this->handled[] = spl_object_id($job);
                app('auth')->forgetGuards();
                $job->handle(app(LiveWhatsApp::class));
            }
        }
        Sanctum::actingAs($this->merchantUser);

        $accountId = WhatsAppAccount::forMerchant($this->merchant->id)?->id;

        return collect(Queue::pushed(SendLiveWhatsApp::class))
            ->each(fn (SendLiveWhatsApp $job) => $this->assertSame($accountId, $job->accountId))
            ->map(fn (SendLiveWhatsApp $job) => $job->text)->values()->all();
    }

    public function test_merchant_connects_her_own_number_officially_and_orders_arrive_on_it(): void
    {
        // Avant : connexion officielle proposée, pas de code d'association
        $this->getJson('/api/v1/live/whatsapp')->assertOk()
            ->assertJsonPath('data.mode', 'none')
            ->assertJsonPath('data.official.available', true)
            ->assertJsonPath('data.qr_option', false)
            ->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/live/whatsapp/connect', ['phone' => '0707080910'])->assertStatus(422);

        $this->connectOfficially();
        $account = WhatsAppAccount::forMerchant($this->merchant->id);
        $this->assertSame([$this->company->id, 'connected', 'meta_cloud'], [$account->company_id, $account->status, $account->provider]);
        $this->assertNull(WhatsAppAccount::forCompanyId($this->company->id));
        // Numéro spécial live activé (code PIN), sans modèles de messages
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/666/register') && strlen((string) $r['pin']) === 6);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'message_templates'));

        $this->getJson('/api/v1/live/whatsapp')->assertJsonPath('data.mode', 'official')->assertJsonPath('data.official.phone', '+2250707080910');
        $this->postJson('/api/v1/live/prepare')->assertJsonPath('data.offer.phone', '+2250707080910');

        // Sans live en cours : pas de réponse (et jamais la création de courses de l'agence)
        $this->meta('wamid.0', 'A1');
        $this->assertSame([], $this->process());
        Queue::assertNotPushed(ProcessInboundWhatsApp::class);

        $this->postJson("/api/v1/live/{$this->live}/start")->assertOk();
        $this->getJson("/api/v1/live/{$this->live}")->assertJsonPath('data.whatsapp.auto', true);

        $this->meta('wamid.1', 'A1');
        $this->meta('wamid.1', 'A1'); // même webhook renvoyé par Meta : ignoré
        $replies = $this->process();
        $this->assertCount(1, $replies);
        $this->assertStringContainsString('Pour la livraison', $replies[0]);

        $this->meta('wamid.2', 'Awa Koné, Yopougon');
        $replies = $this->process();
        $order = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(['Awa Koné', $this->yopougon->id], [$order->recipient_name, $order->delivery_zone_id]);
        $this->assertSame('whatsapp', LiveOrder::first()->source);
        $this->assertStringContainsString("C'est noté, Awa", $replies[1]);
        Queue::assertNotPushed(ProcessInboundWhatsApp::class);

        // Délier
        $this->deleteJson('/api/v1/live/whatsapp/official')->assertOk()->assertJsonPath('data.mode', 'none');
        $this->assertNull(WhatsAppAccount::forMerchant($this->merchant->id));
    }

    public function test_console_option_shows_the_qr_code_next_to_the_official_connection(): void
    {
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099']);
        Sanctum::actingAs($root);
        $this->putJson('/api/v1/console/live', ['qr_whatsapp' => true])->assertOk()->assertJsonPath('data.settings.qr_whatsapp', true);

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/live/whatsapp')->assertOk()
            ->assertJsonPath('data.qr_option', true)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.official.available', true);

        // Relié par code d'association : il reçoit les commandes…
        $this->merchant->forceFill(['live_wa_status' => 'connected', 'live_wa_phone' => '+2250102030405'])->save();
        $this->getJson('/api/v1/live/whatsapp')->assertJsonPath('data.mode', 'qr');
        $this->postJson('/api/v1/live/prepare')->assertJsonPath('data.offer.phone', '+2250102030405');

        // … jusqu'à la connexion officielle, qui passe avant (pas de réponse en double)
        $this->connectOfficially();
        $this->getJson('/api/v1/live/whatsapp')->assertJsonPath('data.mode', 'official');
        $this->postJson("/api/v1/live/{$this->live}/start")->assertOk();
        app('auth')->forgetGuards();
        app(LiveWhatsApp::class)->onMessage($this->merchant->id, '+2250707000001', 'Awa', 'A1');
        Queue::assertNotPushed(SendLiveWhatsApp::class);
        Sanctum::actingAs($this->merchantUser);

        // Option retirée : plus de code d'association
        app(LiveStudio::class)->updateSettings(['qr_whatsapp' => false]);
        $this->deleteJson('/api/v1/live/whatsapp/official')->assertOk()->assertJsonPath('data.mode', 'none')->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/live/prepare')->assertJsonPath('data.offer.phone', $this->merchant->refresh()->messagingPhone());
    }
}
