<?php

namespace Tests\Feature\Api;

use App\Enums\WhatsAppTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Bouton « Connecter WhatsApp » (inscription intégrée de Meta) : le code d'autorisation est
 * échangé contre un jeton, le numéro est activé, vérifié, abonné et ses modèles soumis.
 */
class WhatsAppEmbeddedSignupTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config([
            'messaging.whatsapp.driver' => 'auto',
            'messaging.whatsapp.app_id' => '1111',
            'messaging.whatsapp.signup_config_id' => '2222',
            'messaging.whatsapp.app_secret' => 'app-secret',
        ]);
    }

    private function fakeMeta(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'jeton-entreprise', 'token_type' => 'bearer']),
            '*/555/register' => Http::response(['success' => true]),
            '*/777/subscribed_apps' => Http::response(['success' => true]),
            '*/777/message_templates' => Http::response(['id' => '1', 'status' => 'PENDING']),
            '*/555*' => Http::response(['display_phone_number' => '+225 07 00 00 00 01', 'verified_name' => 'Express DS', 'quality_rating' => 'GREEN']),
        ]);
    }

    private function signup(array $data = [])
    {
        return $this->postJson('/api/v1/whatsapp/embedded-signup', ['code' => 'code-meta', 'phone_number_id' => '555', 'waba_id' => '777', ...$data]);
    }

    public function test_one_click_connection_activates_the_number(): void
    {
        $this->fakeMeta();
        Sanctum::actingAs($this->admin);

        // Identifiants publics de l'application pour le bouton, jamais la clé secrète
        $this->getJson('/api/v1/whatsapp/settings')
            ->assertJsonPath('data.embedded_signup.app_id', '1111')
            ->assertJsonPath('data.embedded_signup.config_id', '2222')
            ->assertJsonMissing(['app-secret']);

        $this->signup()->assertOk()
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.account.status', 'connected')
            ->assertJsonPath('data.account.verified_name', 'Express DS')
            ->assertJsonPath('data.account.connected_via', 'embedded_signup')
            ->assertJsonPath('data.account.last_error', null);

        $account = WhatsAppAccount::forCompanyId($this->company->id);
        $this->assertSame('jeton-entreprise', $account->access_token);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $account->registration_pin);
        $this->assertNotNull($account->webhook_subscribed_at);

        // Code échangé avec la clé secrète, numéro activé avec son PIN, modèles soumis avec le nouveau jeton
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/oauth/access_token')
            && $r['client_id'] === '1111' && $r['client_secret'] === 'app-secret' && $r['code'] === 'code-meta');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/555/register')
            && $r['pin'] === $account->registration_pin && $r->hasHeader('Authorization', 'Bearer jeton-entreprise'));
        Http::assertSentCount(4 + count(WhatsAppTemplate::cases()));
        $this->assertSame(count(WhatsAppTemplate::cases()), $account->templates()->where('status', 'pending')->count());

        // Reconnexion du même numéro : même PIN
        $pin = $account->registration_pin;
        $this->signup(['code' => 'autre-code'])->assertOk();
        $this->assertSame($pin, $account->fresh()->registration_pin);
    }

    public function test_refused_authorization_changes_nothing(): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid verification code format.', 'code' => 100]], 400)]);
        Sanctum::actingAs($this->admin);

        $this->signup()->assertStatus(422)->assertJsonPath('message', 'Autorisation de Meta refusée ou expirée : recommencez la connexion. (Meta : Invalid verification code format.)');
        $this->assertNull(WhatsAppAccount::forCompanyId($this->company->id));
    }

    public function test_button_requires_server_configuration_and_settings_permission(): void
    {
        Http::fake();
        config(['messaging.whatsapp.signup_config_id' => null]);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/whatsapp/settings')->assertJsonPath('data.embedded_signup', null);
        $this->signup()->assertStatus(422);

        Sanctum::actingAs($this->dispatcher);
        $this->signup()->assertForbidden();
        Http::assertNothingSent();
    }
}
