<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Vérification d'un numéro par WhatsApp en premier : numéro de l'entreprise relié par QR code, sinon numéro de
 * Jibiat (console) ; SMS caché tant qu'un WhatsApp est possible.
 */
class WhatsAppFirstVerificationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const PHONE = '+2250712345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['messaging.sms.driver' => 'twilio', 'messaging.whatsapp.driver' => 'auto', 'messaging.whatsapp.bridge.url' => 'http://bridge.test', 'messaging.whatsapp.bridge.token' => 'secret-bridge']);
        Http::fake(['bridge.test/*' => Http::response(['id' => 'qr-x'], 202)]);
    }

    private function event(string $session, array $event)
    {
        $body = json_encode(['session' => $session, ...$event]);

        return $this->call('POST', '/api/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $body, 'secret-bridge'),
        ], $body);
    }

    private function signup(): array
    {
        return $this->postJson('/api/v1/signup', [
            'business_name' => 'Boutique Awa', 'contact_name' => 'Awa Koné', 'phone' => '07 12 34 56 78',
            'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Riviera 2',
            'password' => 'motdepasse', 'password_confirmation' => 'motdepasse',
        ])->assertCreated()->json();
    }

    public function test_without_any_whatsapp_number_sms_is_offered(): void
    {
        $data = $this->signup()['data'];
        $this->assertNull($data['whatsapp']);
        $this->assertTrue($data['sms']['available']);
    }

    public function test_jibiat_number_verifies_when_the_company_has_none(): void
    {
        Notification::fake();
        PlatformSetting::put('whatsapp.status', 'connected');
        PlatformSetting::put('whatsapp.phone', '+2250100000000');

        $response = $this->signup();
        $this->assertSame('01 00 00 00 00', $response['data']['whatsapp']['number']);
        $this->assertFalse($response['data']['sms']['available']);
        $text = $response['data']['whatsapp']['text'];

        // Message depuis un autre numéro : refusé ; depuis le bon : compte créé, réponse par le numéro de Jibiat
        $this->event('platform', ['type' => 'message', 'id' => 'p-1', 'from' => '+2250511111111', 'kind' => 'text', 'text' => $text])->assertOk();
        $this->assertFalse(Merchant::where('phone', self::PHONE)->exists());
        $this->event('platform', ['type' => 'message', 'id' => 'p-2', 'from' => self::PHONE, 'kind' => 'text', 'text' => $text])->assertOk();
        $this->assertTrue(Merchant::where('phone', self::PHONE)->exists());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sessions/platform/send') && $r['to'] === self::PHONE && str_contains($r['text'], 'Numéro vérifié'));
        // Pas de conversation ouverte dans la console pour un code
        $this->assertDatabaseCount('platform_conversations', 0);

        $this->getJson('/api/v1/verifications/'.$response['token'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('session.user.phone', self::PHONE);
    }

    public function test_company_qr_number_comes_first_and_sms_can_be_allowed_as_fallback(): void
    {
        PlatformSetting::put('whatsapp.status', 'connected');
        PlatformSetting::put('whatsapp.phone', '+2250100000000');
        WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'provider' => WhatsAppAccount::QR, 'status' => 'connected', 'display_phone' => '+2250700000001']);

        $data = $this->signup()['data'];
        $this->assertSame('07 00 00 00 01', $data['whatsapp']['number']);
        $this->assertFalse($data['sms']['available']);

        PlatformSetting::put('sms.fallback', 'on');
        $this->assertTrue($this->postJson('/api/v1/password/forgot', ['phone' => $this->merchantUser->phone])->json('data.sms.available'));
    }

    public function test_sms_is_refused_while_whatsapp_is_possible(): void
    {
        PlatformSetting::put('whatsapp.status', 'connected');
        PlatformSetting::put('whatsapp.phone', '+2250100000000');
        $token = $this->signup()['token'];
        $this->postJson("/api/v1/verifications/{$token}/sms")->assertUnprocessable();
        $this->assertSame(0, User::where('phone', self::PHONE)->count());
    }
}
