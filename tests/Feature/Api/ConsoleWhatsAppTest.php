<?php

namespace Tests\Feature\Api;

use App\Enums\PlatformTemplate;
use App\Enums\Role;
use App\Models\InboundMessage;
use App\Models\PhoneVerification;
use App\Models\PlatformConversation;
use App\Models\PlatformMessage;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Platform\PlatformWhatsApp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Console › WhatsApp : numéro de Jibiat relié par QR code (session « platform »), conversations reçues et réponses
 * depuis la console.
 */
class ConsoleWhatsAppTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'name' => 'Awa (Jibiat)']);
        config(['messaging.whatsapp.bridge.url' => 'http://bridge.test', 'messaging.whatsapp.bridge.token' => 'secret-bridge']);
        Http::fake([
            'bridge.test/sessions/platform/start' => Http::response(['status' => 'qr', 'qr' => 'data:image/png;base64,QR']),
            'bridge.test/sessions/platform/send' => Http::response(['id' => 'qr-p1'], 202),
            'bridge.test/sessions/platform/logout' => Http::response(['status' => 'logged_out']),
            'bridge.test/sessions/platform' => Http::response(['status' => 'qr', 'qr' => 'data:image/png;base64,QR']),
        ]);
    }

    private function event(array $event)
    {
        $body = json_encode(['session' => 'platform', ...$event]);

        return $this->call('POST', '/api/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $body, 'secret-bridge'),
        ], $body);
    }

    public function test_jibiat_number_is_linked_and_the_console_answers(): void
    {
        Sanctum::actingAs($this->root);
        $this->postJson('/api/v1/console/whatsapp/connect')->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.qr', 'data:image/png;base64,QR');

        // Avant le scan : pas d'envoi possible
        $conversation = PlatformConversation::create(['phone' => '+2250700000077']);
        $this->postJson("/api/v1/console/whatsapp/conversations/{$conversation->id}/messages", ['body' => 'Bonjour'])->assertJsonValidationErrors('body');

        $this->event(['type' => 'status', 'status' => 'connected', 'phone' => '+2250100000000', 'name' => 'Jibiat'])->assertOk();
        $this->getJson('/api/v1/console/whatsapp')->assertOk()->assertJsonPath('data.status', 'connected')->assertJsonPath('data.qr', null);

        // Message de l'administrateur d'une entreprise : rattaché à son entreprise
        $this->event(['type' => 'message', 'id' => 'wa-1', 'from' => $this->admin->phone, 'name' => 'Koffi', 'kind' => 'text', 'text' => 'Bonjour, comment ajouter un livreur ?'])->assertOk();
        $this->event(['type' => 'message', 'id' => 'wa-1', 'from' => $this->admin->phone, 'kind' => 'text', 'text' => 'Bonjour, comment ajouter un livreur ?']);
        $this->event(['type' => 'message', 'id' => 'wa-2', 'from' => $this->admin->phone, 'kind' => 'image', 'text' => null]);

        $list = $this->getJson('/api/v1/console/whatsapp/conversations')->assertOk()->json('data');
        $koffi = collect($list)->firstWhere('phone', $this->admin->phone);
        $this->assertSame([$this->company->id, 2, 'Koffi'], [$koffi['company']['id'], $koffi['unread'], $koffi['name']]);
        $this->getJson('/api/v1/console/whatsapp')->assertJsonPath('data.unread', 2);

        // Lecture du fil : marqué lu
        $this->getJson("/api/v1/console/whatsapp/conversations/{$koffi['id']}")->assertOk()
            ->assertJsonPath('data.messages.0.body', 'Bonjour, comment ajouter un livreur ?')
            ->assertJsonPath('data.messages.1.body', '📷 Photo (à voir sur le téléphone)');
        $this->getJson('/api/v1/console/whatsapp')->assertJsonPath('data.unread', 0);

        // Réponse depuis la console, par le numéro de Jibiat, puis accusé de lecture
        $this->postJson("/api/v1/console/whatsapp/conversations/{$koffi['id']}/messages", ['body' => 'Dans Utilisateurs, « Ajouter ».'])
            ->assertCreated()->assertJsonPath('data.status', 'sent');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sessions/platform/send') && $r['to'] === $this->admin->phone);
        $this->event(['type' => 'read', 'id' => 'qr-p1']);
        $reply = PlatformMessage::query()->where('direction', 'out')->sole();
        $this->assertSame(['read', $this->root->id], [$reply->status, $reply->user_id]);
        $this->getJson("/api/v1/console/whatsapp/conversations/{$koffi['id']}")->assertJsonPath('data.messages.2.by', 'Awa (Jibiat)');

        // Écrire à un nouveau numéro, et recherche
        $this->postJson('/api/v1/console/whatsapp/conversations', ['phone' => '07 08 09 10 11'])->assertCreated()->assertJsonPath('data.phone', '+2250708091011');
        $this->assertCount(1, $this->getJson('/api/v1/console/whatsapp/conversations?q=koffi')->json('data'));

        // Déconnexion
        $this->deleteJson('/api/v1/console/whatsapp/connect')->assertOk()->assertJsonPath('data.status', 'disconnected');
    }

    public function test_official_connection_is_entered_by_hand_and_used_first(): void
    {
        config(['messaging.whatsapp.driver' => 'auto']);
        Http::fake([
            '*/555?*' => Http::response(['display_phone_number' => '+225 01 00 00 00 99', 'verified_name' => 'Jibiat', 'quality_rating' => 'GREEN']),
            '*/777/subscribed_apps' => Http::response(['success' => true]),
            '*/777/message_templates' => Http::response(['id' => 't1', 'status' => 'PENDING']),
            '*/555/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.P1']]])
                ->push(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400),
        ]);
        Sanctum::actingAs($this->root);

        // Jeton obligatoire la première fois
        $this->putJson('/api/v1/console/whatsapp/official', ['phone_number_id' => '555', 'waba_id' => '777'])->assertJsonValidationErrors('access_token');

        $this->putJson('/api/v1/console/whatsapp/official', ['phone_number_id' => '555', 'waba_id' => '777', 'access_token' => 'permanent'])->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.channel', 'official')
            ->assertJsonPath('data.phone', '+2250100000099')
            ->assertJsonPath('data.official.live', true)
            ->assertJsonPath('data.official.verified_name', 'Jibiat')
            ->assertJsonPath('data.official.webhook_subscribed', true)
            ->assertJsonPath('data.official.has_token', true)
            ->assertJsonMissingPath('data.official.access_token')
            ->assertJsonPath('data.qr_state.status', 'none');
        $account = WhatsAppAccount::platform();
        $this->assertNull($account->company_id);
        $this->assertSame('permanent', $account->access_token);
        $this->assertNull(WhatsAppAccount::forCompanyId($this->company->id));

        // Codes de vérification : le numéro officiel de Jibiat
        $this->assertSame('+2250100000099', app(PhoneVerifier::class)->whatsappNumber($this->company));

        // Message reçu par Meta sur le numéro officiel : conversation de la console
        $this->postJson('/api/webhooks/whatsapp', ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'contacts' => [['profile' => ['name' => 'Koffi']]],
            'messages' => [['id' => 'wamid.IN1', 'from' => ltrim($this->admin->phone, '+'), 'type' => 'text', 'text' => ['body' => 'Bonjour Jibiat']]],
        ]]]]]])->assertOk();
        $conversation = PlatformConversation::where('phone', $this->admin->phone)->sole();
        $this->assertSame(['Koffi', $this->company->id, 'Bonjour Jibiat'], [$conversation->name, $conversation->company_id, $conversation->last_message]);
        $this->assertSame(0, InboundMessage::withoutGlobalScopes()->count());

        // Réponse par Meta, accusé de remise
        $this->postJson("/api/v1/console/whatsapp/conversations/{$conversation->id}/messages", ['body' => 'Bonjour Koffi'])->assertCreated()->assertJsonPath('data.status', 'sent');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/555/messages') && $r['text']['body'] === 'Bonjour Koffi' && $r->hasHeader('Authorization', 'Bearer permanent'));
        $this->postJson('/api/webhooks/whatsapp', ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'statuses' => [['id' => 'wamid.P1', 'status' => 'delivered']],
        ]]]]]])->assertOk();
        $this->assertSame('delivered', PlatformMessage::where('provider_message_id', 'wamid.P1')->value('status'));

        // Hors de la fenêtre de 24 heures : explication claire
        $this->postJson("/api/v1/console/whatsapp/conversations/{$conversation->id}/messages", ['body' => 'Relance'])->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Plus de 24 heures depuis son dernier message : WhatsApp n\'accepte plus de message libre. Attendez qu\'il vous écrive (ou faites approuver les modèles du numéro de Jibiat, console › WhatsApp).');

        // Jeton conservé si le champ reste vide
        $this->putJson('/api/v1/console/whatsapp/official', ['phone_number_id' => '555', 'waba_id' => '777', 'access_token' => null])->assertOk();
        $this->assertSame('permanent', WhatsAppAccount::platform()->access_token);

        // Retirée : retour au QR code (non relié)
        $this->deleteJson('/api/v1/console/whatsapp/official')->assertOk()
            ->assertJsonPath('data.official', null)
            ->assertJsonPath('data.channel', 'qr')
            ->assertJsonPath('data.status', 'none');
    }

    public function test_official_number_templates_are_submitted_then_used_for_messages_jibiat_sends_first(): void
    {
        config(['messaging.whatsapp.driver' => 'auto']);
        Http::fake([
            '*/555?*' => Http::response(['display_phone_number' => '+225 01 00 00 00 99', 'verified_name' => 'Jibiat', 'quality_rating' => 'GREEN']),
            '*/777/subscribed_apps' => Http::response(['success' => true]),
            '*/777/message_templates?*' => Http::response(['data' => [
                ['name' => 'jibiat_code_connexion', 'language' => 'fr', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION'],
                ['name' => 'jibiat_connexion_console', 'language' => 'fr', 'status' => 'APPROVED', 'category' => 'UTILITY'],
                ['name' => 'jibiat_paiement_wave', 'language' => 'fr', 'status' => 'REJECTED', 'category' => 'UTILITY', 'rejected_reason' => 'INVALID_FORMAT'],
                ['name' => 'course_confirmee', 'language' => 'fr', 'status' => 'APPROVED', 'category' => 'UTILITY'],
            ]]),
            '*/777/message_templates' => Http::response(['id' => 't1', 'status' => 'PENDING']),
            '*/555/messages' => Http::response(['messages' => [['id' => 'wamid.T1']]]),
        ]);
        Sanctum::actingAs($this->root);

        // Numéro vérifié : les quatre modèles de Jibiat partent chez Meta (le code en authentification, avec Copier)
        $this->putJson('/api/v1/console/whatsapp/official', ['phone_number_id' => '555', 'waba_id' => '777', 'access_token' => 'permanent'])->assertOk()
            ->assertJsonCount(4, 'data.templates')
            ->assertJsonPath('data.templates.0.status', 'pending');
        $submitted = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/777/message_templates'));
        $this->assertSame(['jibiat_code_connexion', 'jibiat_connexion_console', 'jibiat_paiement_wave', 'jibiat_acces_assistance'], $submitted->map(fn ($r) => $r['name'])->values()->all());
        $code = $submitted->first();
        $this->assertSame(['AUTHENTICATION', 'COPY_CODE'], [$code['category'], $code['components'][2]['buttons'][0]['otp_type']]);
        $this->assertSame('UTILITY', $submitted->last()['category']);

        // Déjà en validation : rien n'est renvoyé
        $this->postJson('/api/v1/console/whatsapp/templates/submit')->assertOk()->assertJsonPath('results', []);

        // État lu chez Meta (seuls les modèles de Jibiat sont gardés)
        $this->postJson('/api/v1/console/whatsapp/templates/sync')->assertOk()
            ->assertJsonPath('data.templates.0.status', 'approved')
            ->assertJsonPath('data.templates.2.status', 'rejected')
            ->assertJsonPath('data.templates.2.rejected_reason', 'INVALID_FORMAT')
            ->assertJsonPath('data.templates.3.status', 'pending');
        $this->assertFalse(WhatsAppAccount::platform()->templates()->where('name', 'course_confirmee')->exists());

        $whatsapp = app(PlatformWhatsApp::class);
        // Code de connexion : modèle d'authentification, code dans le texte et le bouton
        $whatsapp->notify('+2250700000011', 'Code 482913', PlatformTemplate::ConsoleCode, ['482913']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/555/messages') && ($r['template']['name'] ?? null) === 'jibiat_code_connexion'
            && $r['template']['components'][1]['parameters'][0]['text'] === '482913');
        // Alerte approuvée : modèle, paramètres sur une ligne
        $whatsapp->notify('+2250700000011', 'Nouvelle connexion', PlatformTemplate::ConsoleLogin, ["mardi\n27/10", 'Chrome', '1.2.3.4', 'https://x.test']);
        Http::assertSent(fn (Request $r) => ($r['template']['name'] ?? null) === 'jibiat_connexion_console' && $r['template']['components'][0]['parameters'][0]['text'] === 'mardi 27/10');
        // Modèle refusé : texte libre (dans les 24 heures)
        $whatsapp->notify('+2250700000011', 'Paiement Wave en cours', PlatformTemplate::WavePayment, ['a', 'b', 'c', 'd', 'e', 'f']);
        Http::assertSent(fn (Request $r) => ($r['text']['body'] ?? null) === 'Paiement Wave en cours');

        // Réservé au Propriétaire
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/console/whatsapp/templates/submit')->assertForbidden();
    }

    public function test_official_connection_error_is_explained(): void
    {
        config(['messaging.whatsapp.driver' => 'auto']);
        Http::fake(['*/555?*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 401)]);
        Sanctum::actingAs($this->root);

        $this->putJson('/api/v1/console/whatsapp/official', ['phone_number_id' => '555', 'waba_id' => '777', 'access_token' => 'expired'])->assertOk()
            ->assertJsonPath('data.official.status', 'error')
            ->assertJsonPath('data.official.live', false)
            ->assertJsonPath('data.channel', 'qr')
            ->assertJsonPath('data.official.error', 'Jeton d\'accès invalide ou expiré. Créez un jeton permanent (utilisateur système) dans Meta Business.');

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/console/whatsapp/official', ['phone_number_id' => '1', 'waba_id' => '2', 'access_token' => 'x'])->assertForbidden();
    }

    public function test_reserved_to_the_console(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/console/whatsapp')->assertForbidden();
        $this->getJson('/api/v1/console/whatsapp/conversations')->assertForbidden();
    }

    public function test_sms_of_the_month_are_counted_and_the_fallback_switch_is_kept(): void
    {
        Sanctum::actingAs($this->root);
        config(['messaging.sms.driver' => 'twilio']);
        // Deux SMS de vérification ce mois, un le mois dernier
        $make = fn (Carbon $at, int $count) => PhoneVerification::create([
            'company_id' => $this->company->id, 'purpose' => 'signup', 'phone' => '+2250700000'.$count.$at->format('d'),
            'token_hash' => hash('sha256', (string) $at->timestamp.$count), 'whatsapp_code' => '123456', 'sms_count' => $count,
            'sms_sent_at' => $at, 'expires_at' => $at->copy()->addMinutes(30),
        ]);
        $make(now(), 2);
        $make(now()->subMonthNoOverflow(), 1);

        $this->getJson('/api/v1/console/whatsapp')->assertOk()
            ->assertJsonPath('data.sms.month', 2)->assertJsonPath('data.sms.last_month', 1)
            ->assertJsonPath('data.sms.fallback', false)->assertJsonPath('data.sms.ready', true);

        $this->putJson('/api/v1/console/whatsapp/sms', ['fallback' => true])->assertOk()->assertJsonPath('data.sms.fallback', true);
        $this->assertSame('on', PlatformSetting::get('sms.fallback'));
        $this->putJson('/api/v1/console/whatsapp/sms', ['fallback' => false])->assertOk()->assertJsonPath('data.sms.fallback', false);

        // Réservé au super administrateur
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/console/whatsapp/sms', ['fallback' => true])->assertForbidden();
    }
}
