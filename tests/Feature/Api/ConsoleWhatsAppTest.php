<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\PlatformConversation;
use App\Models\PlatformMessage;
use App\Models\User;
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

    public function test_reserved_to_the_console(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/console/whatsapp')->assertForbidden();
        $this->getJson('/api/v1/console/whatsapp/conversations')->assertForbidden();
    }
}
