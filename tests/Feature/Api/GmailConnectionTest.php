<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * E-mails de la plateforme par Gmail, sans mot de passe : connexion Google (OAuth, envoi seulement) puis envoi par
 * l'API de Gmail.
 */
class GmailConnectionTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        PlatformSetting::put('wave.validator_email', 'validateur@exemple.ci');
    }

    private function configure(): void
    {
        config(['services.google_mail.client_id' => 'client-123.apps.googleusercontent.com', 'services.google_mail.client_secret' => 'secret-456']);
    }

    private function idToken(string $email): string
    {
        return 'h.'.rtrim(strtr(base64_encode(json_encode(['email' => $email])), '+/', '-_'), '=').'.s';
    }

    private function connect(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'acces-1', 'expires_in' => 3599, 'refresh_token' => 'longue-duree-1',
                'scope' => 'openid https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email', 'id_token' => $this->idToken('jibiat.ci@gmail.com'),
            ]),
            'gmail.googleapis.com/*' => Http::response(['id' => 'msg-1']),
            'oauth2.googleapis.com/revoke' => Http::response([]),
        ]);
        Sanctum::actingAs($this->root);
        $url = $this->postJson('/api/v1/console/gmail/connect')->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->get('/gmail/callback?code=code-google&state='.$query['state'])->assertRedirect('/console/paiements?gmail=ok');
    }

    public function test_the_button_needs_the_google_project_on_the_server(): void
    {
        Sanctum::actingAs($this->root);
        $this->getJson('/api/v1/console/wave')->assertJsonPath('data.mail.gmail.available', false);
        $this->postJson('/api/v1/console/gmail/connect')->assertJsonValidationErrors('gmail');
    }

    public function test_the_google_client_is_entered_in_the_console_like_jibiat_marchand(): void
    {
        Sanctum::actingAs(User::factory()->withRole(Role::PlatformSupport)->create(['company_id' => null]));
        $this->putJson('/api/v1/console/gmail/client', ['client_id' => 'x', 'client_secret' => 'y'])->assertForbidden();

        Sanctum::actingAs($this->root);
        $this->putJson('/api/v1/console/gmail/client', ['client_id' => 'client-789.apps.googleusercontent.com'])->assertJsonValidationErrors('client_secret');
        $data = $this->putJson('/api/v1/console/gmail/client', ['client_id' => 'client-789.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-secret'])
            ->assertOk()->assertJsonPath('data.mail.gmail.available', true)
            ->assertJsonPath('data.mail.gmail.client_id', 'client-789.apps.googleusercontent.com')->json();
        $this->assertStringNotContainsString('GOCSPX-secret', json_encode($data));
        $this->assertStringNotContainsString('GOCSPX-secret', (string) PlatformSetting::get('gmail.client_secret'));

        // Le secret vide est gardé ; le lien Google utilise le client saisi
        $this->putJson('/api/v1/console/gmail/client', ['client_id' => 'client-789.apps.googleusercontent.com', 'client_secret' => ''])->assertOk();
        $url = $this->postJson('/api/v1/console/gmail/connect')->assertOk()->json('data.url');
        $this->assertStringContainsString('client_id=client-789.apps.googleusercontent.com', $url);
    }

    public function test_connecting_gmail_then_sending_through_its_api(): void
    {
        $this->configure();
        Sanctum::actingAs($this->root);
        $url = $this->postJson('/api/v1/console/gmail/connect')->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('gmail.send', urldecode($url));
        $this->assertStringContainsString('access_type=offline', $url);

        $this->connect();
        // Autorisation chiffrée, jamais renvoyée à la console
        $this->assertStringNotContainsString('longue-duree-1', (string) PlatformSetting::get('gmail.refresh_token'));
        $this->getJson('/api/v1/console/wave')->assertJsonPath('data.mail.source', 'gmail')
            ->assertJsonPath('data.mail.gmail.connected', true)->assertJsonPath('data.mail.gmail.email', 'jibiat.ci@gmail.com')
            ->assertJsonMissingPath('data.mail.gmail.refresh_token');

        // L'alerte d'essai part par l'API de Gmail, depuis le compte relié
        $this->postJson('/api/v1/console/wave/test-alert')->assertOk()->assertJsonPath('data.mail.ok', true);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'gmail.googleapis.com/gmail/v1/users/me/messages/send')) {
                return false;
            }
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));

            return $request->hasHeader('Authorization', 'Bearer acces-1')
                && str_contains($raw, 'validateur@exemple.ci') && str_contains($raw, 'jibiat.ci@gmail.com');
        });

        // Déconnexion : l'autorisation est retirée chez Google
        $this->deleteJson('/api/v1/console/gmail')->assertOk()->assertJsonPath('data.mail.gmail.connected', false);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
    }

    public function test_a_withdrawn_authorization_is_reported(): void
    {
        $this->configure();
        $this->connect();
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        Sanctum::actingAs($this->root);
        $this->postJson('/api/v1/console/wave/test-alert')->assertOk()->assertJsonPath('data.mail.ok', false);
        $this->getJson('/api/v1/console/wave')->assertJsonPath('data.mail.gmail.connected', false)
            ->assertJsonPath('data.mail.gmail.error', fn ($e) => str_contains($e, 'reconnectez Gmail'));
        $this->getJson('/api/v1/console/health')->assertOk();
    }

    public function test_an_unknown_or_refused_return_from_google_connects_nothing(): void
    {
        $this->configure();
        $this->get('/gmail/callback?code=x&state=inconnu')->assertRedirect();
        $this->assertStringContainsString('gmail=erreur', $this->get('/gmail/callback?code=x&state=inconnu')->headers->get('Location'));
        $this->get('/gmail/callback?error=access_denied')->assertRedirect('/console/paiements?gmail=refus');
        $this->assertNull(PlatformSetting::get('gmail.refresh_token'));

        // Réservé au super administrateur
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/console/gmail/connect')->assertForbidden();
    }
}
