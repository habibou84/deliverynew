<?php

namespace Tests\Feature\Api;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Compte sans mot de passe (inscription vérifiée par WhatsApp), connexion par WhatsApp, mot de passe défini plus tard.
 */
class WhatsAppLoginTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const PHONE = '+2250712345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['messaging.sms.driver' => 'twilio', 'messaging.whatsapp.driver' => 'auto', 'messaging.whatsapp.bridge.url' => 'http://bridge.test', 'messaging.whatsapp.bridge.token' => 'secret-bridge']);
        Http::fake(['bridge.test/*' => Http::response(['id' => 'qr-x'], 202)]);
        // Numéro de Jibiat relié : il reçoit les messages de vérification
        PlatformSetting::put('whatsapp.status', 'connected');
        PlatformSetting::put('whatsapp.phone', '+2250100000000');
    }

    private function platformMessage(string $from, string $text, string $id = 'p-1')
    {
        $body = json_encode(['session' => 'platform', 'type' => 'message', 'id' => $id, 'from' => $from, 'kind' => 'text', 'text' => $text]);

        return $this->call('POST', '/api/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $body, 'secret-bridge'),
        ], $body);
    }

    public function test_signup_without_password_opens_the_account_and_the_session(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/v1/signup', [
            'business_name' => 'Boutique Awa', 'contact_name' => 'Awa Koné', 'phone' => '07 12 34 56 78',
            'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Riviera 2',
        ])->assertCreated();

        $this->platformMessage(self::PHONE, $response->json('data.whatsapp.text'))->assertOk();

        $user = User::where('phone', self::PHONE)->firstOrFail();
        $this->assertNull($user->password);
        $session = $this->getJson('/api/v1/verifications/'.$response->json('token'))->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('session.user.has_password', false);

        // Sans mot de passe, la connexion classique est impossible…
        $this->postJson('/api/v1/auth/login', ['login' => '07 12 34 56 78', 'password' => ''])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['login' => '07 12 34 56 78', 'password' => 'nimporte'])->assertUnprocessable();

        // … jusqu'à ce que la personne définisse un mot de passe (sans mot de passe actuel à donner)
        $token = $session->json('session.token');
        $this->withToken($token)->putJson('/api/v1/auth/password', ['password' => 'motdepasse', 'password_confirmation' => 'motdepasse'])
            ->assertOk()->assertJsonPath('message', 'Mot de passe défini.')->assertJsonPath('user.has_password', true);
        $this->postJson('/api/v1/auth/login', ['login' => '07 12 34 56 78', 'password' => 'motdepasse'])->assertOk();

        // Le changer demande ensuite le mot de passe actuel, et déconnecte les autres appareils
        $this->withToken($token)->putJson('/api/v1/auth/password', ['password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp'])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'faux', 'password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['login' => '07 12 34 56 78', 'password' => 'motdepasse'])->assertOk();
        $this->assertSame(3, $user->tokens()->count());
        $this->withToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'motdepasse', 'password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp'])->assertOk();
        // Seule la session de l'appareil qui a changé le mot de passe reste ouverte
        $this->assertSame(['inscription'], $user->tokens()->pluck('name')->all());
        $this->postJson('/api/v1/auth/login', ['login' => '07 12 34 56 78', 'password' => 'nouveau-mdp'])->assertOk();
    }

    public function test_whatsapp_login_opens_the_session_once_the_message_is_received(): void
    {
        $courier = $this->courierA->user;

        $response = $this->postJson('/api/v1/auth/whatsapp', ['phone' => $courier->phone, 'space' => 'courier', 'device_name' => 'Téléphone de Koffi'])
            ->assertCreated()
            ->assertJsonPath('data.purpose', 'login')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.whatsapp.number', '01 00 00 00 00')
            ->assertJsonPath('data.sms.available', false);
        $token = $response->json('token');
        $text = $response->json('data.whatsapp.text');

        $this->getJson("/api/v1/verifications/{$token}")->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('session');

        // Depuis un autre numéro : rien
        $this->platformMessage('+2250511111111', $text, 'p-0')->assertOk();
        $this->getJson("/api/v1/verifications/{$token}")->assertJsonMissingPath('session');

        $this->platformMessage($courier->phone, $text)->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sessions/platform/send') && str_contains($r['text'], 'vous y êtes connecté'));

        $session = $this->getJson("/api/v1/verifications/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('session.user.id', $courier->id);
        $this->getJson("/api/v1/verifications/{$token}")->assertJsonMissingPath('session');
        $this->withToken($session->json('session.token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role', 'courier');
        $this->assertSame('Téléphone de Koffi', $courier->tokens()->latest('id')->value('name'));
    }

    public function test_whatsapp_login_follows_the_rules_of_the_login_page(): void
    {
        // Numéro inconnu
        $this->postJson('/api/v1/auth/whatsapp', ['phone' => '07 99 99 99 99', 'space' => 'merchant'])
            ->assertUnprocessable()->assertJsonPath('errors.phone.0', 'Aucun compte avec ce numéro. Vérifiez-le, ou créez votre compte.');

        // Compte d'un autre espace
        $this->postJson('/api/v1/auth/whatsapp', ['phone' => $this->courierA->user->phone, 'space' => 'merchant'])
            ->assertUnprocessable()->assertJsonPath('space', 'courier');

        // Compte suspendu
        $this->merchantUser->forceFill(['status' => 'suspended'])->save();
        $this->postJson('/api/v1/auth/whatsapp', ['phone' => $this->merchantUser->phone, 'space' => 'merchant'])->assertForbidden();
    }
}
