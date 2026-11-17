<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Console › WhatsApp : bouton « Connecter WhatsApp en un clic » pour le numéro de Jibiat (inscription intégrée de
 * Meta), à la place de la saisie à la main des identifiants et du jeton.
 */
class ConsoleWhatsAppSignupTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        config([
            'messaging.whatsapp.driver' => 'auto',
            'messaging.whatsapp.app_id' => '1111',
            'messaging.whatsapp.signup_config_id' => '2222',
            'messaging.whatsapp.app_secret' => 'app-secret',
        ]);
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'jeton-jibiat', 'token_type' => 'bearer']),
            '*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => now()->addDays(60)->timestamp]]),
            '*/888/register' => Http::response(['success' => true]),
            '*/999/subscribed_apps' => Http::response(['success' => true]),
            '*/999/message_templates' => Http::response(['id' => '1', 'status' => 'PENDING']),
            '*/888*' => Http::response(['display_phone_number' => '+225 07 00 00 00 99', 'verified_name' => 'Jibiat', 'quality_rating' => 'GREEN']),
        ]);
    }

    private function ticket(): string
    {
        Sanctum::actingAs($this->root);
        $url = $this->postJson('/api/v1/console/whatsapp/signup-ticket')->assertOk()->json('data.url');
        $this->assertStringContainsString('/connecter-whatsapp#', $url);

        return substr($url, strpos($url, '#') + 1);
    }

    public function test_jibiat_number_is_connected_in_one_click(): void
    {
        $ticket = $this->ticket();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/whatsapp-signup/session', ['ticket' => $ticket])->assertOk()
            ->assertJsonPath('data.platform', true)
            ->assertJsonPath('data.merchant', false)
            ->assertJsonPath('data.return', '/console/whatsapp');

        $this->postJson('/api/v1/whatsapp-signup/complete', ['ticket' => $ticket, 'code' => 'code-meta', 'phone_number_id' => '888', 'waba_id' => '999'])
            ->assertOk()
            ->assertJsonPath('data.verified_name', 'Jibiat');

        $account = WhatsAppAccount::platform();
        $this->assertNotNull($account);
        $this->assertNull($account->company_id);
        $this->assertSame('connected', $account->status);
        $this->assertSame('embedded_signup', $account->connected_via);
        $this->assertSame('jeton-jibiat', $account->access_token);
        // Aucune entreprise n'a reçu ce numéro
        $this->assertSame(1, WhatsAppAccount::withoutGlobalScopes()->count());
        // Modèles de la plateforme soumis à Meta
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/999/message_templates'));

        Sanctum::actingAs($this->root);
        $this->getJson('/api/v1/console/whatsapp')->assertOk()
            ->assertJsonPath('data.signup_available', true)
            ->assertJsonPath('data.official.live', true)
            ->assertJsonPath('data.official.connected_via', 'embedded_signup');
    }

    public function test_only_the_owner_gets_a_ticket_and_it_follows_their_rights(): void
    {
        $support = User::factory()->withRole(Role::PlatformSupport)->create(['company_id' => null]);
        Sanctum::actingAs($support);
        $this->postJson('/api/v1/console/whatsapp/signup-ticket')->assertForbidden();

        // Propriétaire qui perd son rôle entre le ticket et la fin de la fenêtre de Meta
        $ticket = $this->ticket();
        $this->root->syncRoles([Role::PlatformSupport->value]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/whatsapp-signup/complete', ['ticket' => $ticket, 'code' => 'code-meta', 'phone_number_id' => '888', 'waba_id' => '999'])
            ->assertForbidden();
        $this->assertNull(WhatsAppAccount::platform());
    }

    public function test_button_is_unavailable_without_the_meta_app(): void
    {
        config(['messaging.whatsapp.signup_config_id' => null]);
        Sanctum::actingAs($this->root);

        $this->getJson('/api/v1/console/whatsapp')->assertOk()->assertJsonPath('data.signup_available', false);
        $this->postJson('/api/v1/console/whatsapp/signup-ticket')->assertStatus(422);
    }
}
