<?php

namespace Tests\Feature\Api;

use App\Enums\MessageStatus;
use App\Enums\WhatsAppTemplate;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Connexion WhatsApp par QR code (service whatsapp-bridge), en attendant la validation de Meta : QR code à scanner,
 * numéro relié, envois réels par le service, messages reçus, boutons en liste numérotée, échecs.
 */
class WhatsAppQrTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const QR = 'data:image/png;base64,AAAA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['messaging.whatsapp.driver' => 'auto', 'messaging.whatsapp.bridge.url' => 'http://bridge.test', 'messaging.whatsapp.bridge.token' => 'secret-bridge']);
        Http::fake([
            'bridge.test/sessions/*/start' => Http::response(['status' => 'qr', 'qr' => self::QR]),
            'bridge.test/sessions/*/send' => Http::sequence()->push(['id' => 'qr-1'], 202)->push(['id' => 'qr-2'], 202)->push(['id' => 'qr-3'], 202),
            'bridge.test/sessions/*/logout' => Http::response(['status' => 'logged_out']),
            'bridge.test/sessions/*' => Http::response(['status' => 'qr', 'qr' => self::QR]),
        ]);
    }

    /** Événement du service, signé comme lui */
    private function event(array $event, ?string $token = 'secret-bridge')
    {
        $body = json_encode(['session' => 'company-'.$this->company->id, ...$event]);

        return $this->call('POST', '/api/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $body, (string) $token),
        ], $body);
    }

    private function connect(): WhatsAppAccount
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/whatsapp/qr')->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.qr', self::QR);
        $this->event(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001', 'name' => 'Rapide Express'])->assertOk();

        return WhatsAppAccount::forCompanyId($this->company->id);
    }

    public function test_scanning_the_qr_code_makes_messages_real(): void
    {
        $account = $this->connect();
        $this->assertTrue($account->isQr());
        $this->assertTrue($account->isLive());
        $this->assertSame('+2250700000001', $account->display_phone);

        $this->getJson('/api/v1/whatsapp/qr')->assertOk()->assertJsonPath('data.status', 'connected')->assertJsonPath('data.qr', null);
        $this->getJson('/api/v1/whatsapp/settings')->assertOk()->assertJsonPath('data.mode', 'live');

        // Le texte du modèle part tel quel, par le service
        $message = app(Messenger::class)->whatsapp($this->company->id, '0700000099', 'merchant', WhatsAppTemplate::OrderDelivered, ['Boutique Awa', 'LV-1', '5 000 F']);
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertSame('qr-1', $message->fresh()->provider_message_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sessions/company-'.$this->company->id.'/send')
            && $r['to'] === '+2250700000099' && str_contains($r['text'], 'Boutique Awa')
            && $r->hasHeader('Authorization', 'Bearer secret-bridge'));

        // Accusés de réception, puis échec réel d'un autre message
        $this->event(['type' => 'read', 'id' => 'qr-1']);
        $this->assertSame(MessageStatus::Read, $message->fresh()->status);
        $other = app(Messenger::class)->whatsapp($this->company->id, '0700000098', 'recipient', WhatsAppTemplate::OrderDelivered, ['A', 'B', 'C']);
        $this->event(['type' => 'failed', 'id' => 'qr-2', 'error' => 'Ce numéro n\'a pas de compte WhatsApp.']);
        $this->assertSame(MessageStatus::Failed, $other->fresh()->status);
        $this->assertStringContainsString('pas de compte WhatsApp', $other->fresh()->error);
    }

    public function test_numbered_choices_come_back_as_buttons(): void
    {
        $account = $this->connect();
        app(Messenger::class)->reply($this->company->id, '+2250700000099', 'Confirmer la course ?', ['confirm' => 'Confirmer', 'cancel' => 'Annuler']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send') && str_contains($r['text'], "1. Confirmer\n2. Annuler"));

        Queue::fake();
        $this->event(['type' => 'message', 'id' => 'wa-1', 'from' => '+2250700000099', 'kind' => 'text', 'text' => '2'])->assertOk();
        Queue::assertPushed(ProcessInboundWhatsApp::class, fn ($job) => $job->type === 'button' && $job->buttonId === 'cancel'
            && $job->companyId === $this->company->id && $job->meta['whatsapp_account_id'] === $account->id);

        // Texte libre : transmis tel quel
        $this->event(['type' => 'message', 'id' => 'wa-2', 'from' => '+2250700000099', 'kind' => 'text', 'text' => 'Bonjour, une course pour Cocody']);
        Queue::assertPushed(ProcessInboundWhatsApp::class, fn ($job) => $job->type === 'text' && $job->buttonId === null && $job->text === 'Bonjour, une course pour Cocody');
    }

    public function test_phone_disconnection_and_logout(): void
    {
        $account = $this->connect();

        // Appareil retiré depuis le téléphone : retour en simulation
        $this->event(['type' => 'status', 'status' => 'logged_out', 'error' => 'Le téléphone a déconnecté Jibiat.']);
        $this->assertFalse($account->fresh()->isLive());
        $this->getJson('/api/v1/whatsapp/qr')->assertJsonPath('data.status', 'disconnected');

        $this->postJson('/api/v1/whatsapp/qr')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->deleteJson('/api/v1/whatsapp/qr')->assertOk()->assertJsonPath('data.status', 'disconnected');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/logout'));
    }

    public function test_signature_meta_number_and_permissions(): void
    {
        $this->event(['type' => 'status', 'status' => 'connected'], 'mauvais')->assertForbidden();

        // Numéro déjà connecté par Meta : pas de QR code
        WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'phone_number_id' => '123', 'access_token' => 'x', 'status' => 'connected']);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/whatsapp/qr')->assertUnprocessable()->assertJsonValidationErrors('qr');

        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/whatsapp/qr')->assertForbidden();
    }

    public function test_without_the_service_the_screen_says_so(): void
    {
        config(['messaging.whatsapp.bridge.token' => null]);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/whatsapp/qr')->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/whatsapp/qr')->assertUnprocessable()->assertJsonValidationErrors('qr');
        $this->assertSame(0, OutboundMessage::count());
    }
}
