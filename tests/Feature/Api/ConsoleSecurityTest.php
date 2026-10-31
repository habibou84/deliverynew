<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\PlatformAuditLog;
use App\Models\SupportSession;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Sécurité de la console : journal des actions, assistance, session courte, réglages Wave protégés.
 */
class ConsoleSecurityTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'jibiat.test']);
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide', 'name' => 'Rapide Express']);
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'name' => 'Habib Root', 'phone' => '+2250700000099']);
    }

    private function console(string $path): string
    {
        return 'http://admin.jibiat.test/api/v1'.$path;
    }

    public function test_console_actions_are_logged_without_secrets(): void
    {
        Sanctum::actingAs($this->root);
        $this->patchJson($this->console("/companies/{$this->company->id}"), ['status' => 'suspended'])->assertOk();
        $this->putJson($this->console('/console/wave'), ['link' => 'https://pay.wave.com/m/M_ci_x', 'validator_pin' => '135790', 'current_password' => 'password'])->assertOk();
        // Lecture : rien n'est écrit
        $this->getJson($this->console('/console/companies'))->assertOk();

        $this->assertSame(2, PlatformAuditLog::count());
        $suspend = PlatformAuditLog::where('action', 'companies.update')->sole();
        $this->assertSame([$this->root->id, $this->company->id, 'suspended'], [$suspend->user_id, $suspend->company_id, $suspend->data['input']['status']]);
        $wave = PlatformAuditLog::where('action', 'console.wave.update')->sole();
        $this->assertSame('••••', $wave->data['input']['validator_pin']);
        $this->assertSame('••••', $wave->data['input']['current_password']);
        $this->assertStringNotContainsString('135790', json_encode($wave->data));

        // Consultable dans la console, filtrable par entreprise ; réservé au super administrateur
        $this->getJson($this->console('/console/audit?company_id='.$this->company->id))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.name', 'Habib Root')->assertJsonPath('data.0.company.name', 'Rapide Express');
        Sanctum::actingAs($this->admin);
        $this->getJson('http://rapide.jibiat.test/api/v1/console/audit')->assertForbidden();
    }

    public function test_team_actions_are_not_in_the_console_log(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("http://rapide.jibiat.test/api/v1/companies/{$this->company->id}", ['name' => 'Rapide'])->assertOk();
        $this->assertSame(0, PlatformAuditLog::count());
    }

    public function test_support_session_actions_are_logged_and_the_session_can_be_closed(): void
    {
        Sanctum::actingAs($this->root);
        $url = $this->postJson($this->console("/console/companies/{$this->company->id}/support-session"), ['reason' => 'Réglage des tarifs'])->assertCreated()->json('data.url');
        $token = substr($url, strlen('https://rapide.jibiat.test/admin#support='));

        // Action faite pendant l'assistance : attribuée au super administrateur, « en tant que » le compte utilisé
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->patchJson("http://rapide.jibiat.test/api/v1/companies/{$this->company->id}", ['name' => 'Rapide Express CI'])->assertOk();
        $log = PlatformAuditLog::where('action', 'companies.update')->sole();
        $this->assertSame([$this->root->id, $this->company->id, $this->admin->id], [$log->user_id, $log->company_id, $log->data['support_as']['id']]);

        // Fermée depuis la console : le jeton ne marche plus
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->root);
        $session = $this->getJson($this->console("/console/companies/{$this->company->id}"))->assertJsonPath('data.support_sessions.0.open', true)->json('data.support_sessions.0.id');
        $this->deleteJson($this->console("/console/companies/{$this->company->id}/support-sessions/{$session}"))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertUnauthorized();
    }

    /** Audit n° 2, point 17 : rien qui garde un accès après l'heure ; suspendre le membre coupe ses sessions */
    public function test_support_session_cannot_create_lasting_access_and_ends_with_the_member(): void
    {
        $helper = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000098']);
        Sanctum::actingAs($helper);
        $url = $this->postJson($this->console("/console/companies/{$this->company->id}/support-session"), ['reason' => 'Aide'])->assertCreated()->json('data.url');
        $token = substr($url, strlen('https://rapide.jibiat.test/admin#support='));

        $this->app['auth']->forgetGuards();
        $company = 'http://rapide.jibiat.test/api/v1';
        $this->withToken($token)->postJson("{$company}/api-keys", ['name' => 'Clé'])->assertForbidden();
        $this->withToken($token)->postJson("{$company}/users", ['name' => 'Moi', 'phone' => '0700000077', 'role' => 'admin'])->assertForbidden();
        $this->withToken($token)->postJson("{$company}/company/legacy-switch/key")->assertForbidden();
        $this->withToken($token)->putJson("{$company}/auth/password", ['current_password' => 'x', 'password' => 'y'])->assertForbidden();
        // Le reste de l'aide marche
        $this->withToken($token)->getJson("{$company}/auth/me")->assertOk();

        // Membre suspendu depuis la console : sa session d'assistance s'arrête
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->root);
        $this->patchJson($this->console("/console/team/{$helper->id}"), ['status' => 'suspended'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson("{$company}/auth/me")->assertUnauthorized();
        $this->assertFalse(SupportSession::query()->where('opened_by', $helper->id)->where('expires_at', '>', now())->exists());
    }

    public function test_super_admin_session_lasts_twelve_hours(): void
    {
        // Appareil retenu : pas de code de connexion à saisir
        $device = 'td_'.str_repeat('a', 48);
        TrustedDevice::create(['user_id' => $this->root->id, 'token_hash' => hash('sha256', $device), 'expires_at' => now()->addDay()]);
        $this->postJson($this->console('/auth/login'), ['login' => '0700000099', 'password' => 'password', 'trusted_device' => $device])->assertOk()->assertJsonPath('user.id', $this->root->id);
        $expires = PersonalAccessToken::query()->where('tokenable_id', $this->root->id)->sole()->expires_at;
        $this->assertTrue($expires->between(now()->addHours(11), now()->addHours(13)));

        // Pas de connexion par WhatsApp pour un compte de la plateforme (mot de passe seulement)
        $this->postJson($this->console('/auth/whatsapp'), ['phone' => '0700000099'])->assertJsonValidationErrors('phone');
    }

    public function test_wave_settings_need_the_password_and_a_six_digit_code(): void
    {
        Sanctum::actingAs($this->root);
        $this->putJson($this->console('/console/wave'), ['link' => 'https://pay.wave.com/m/M_ci_x'])->assertJsonValidationErrors('current_password');
        $this->putJson($this->console('/console/wave'), ['link' => 'https://pay.wave.com/m/M_ci_x', 'current_password' => 'faux'])
            ->assertJsonValidationErrors(['current_password' => 'Mot de passe incorrect.']);
        $this->putJson($this->console('/console/wave'), ['validator_pin' => '1234', 'current_password' => 'password'])->assertJsonValidationErrors('validator_pin');
    }

    public function test_health_page_and_alerts_for_breakdowns(): void
    {
        config(['platform.backup_path' => sys_get_temp_dir().'/jibiat-no-backup-'.uniqid()]);
        Sanctum::actingAs($this->root);
        $checks = collect($this->getJson($this->console('/console/health'))->assertOk()->json('data.checks'))->keyBy('key');
        // Sans battement ni sauvegarde : en panne ; le WhatsApp de Jibiat n'est pas relié
        $this->assertSame('error', $checks['scheduler']['status']);
        $this->assertSame('error', $checks['backup']['status']);
        $this->assertSame('error', $checks['whatsapp']['status']);
        $this->assertSame('ok', $checks['failed_jobs']['status']);

        // Alerte dans la cloche, une seule fois pour une même panne
        $this->artisan('platform:health --alert')->assertSuccessful();
        $this->assertSame(1, $this->root->notifications()->count());
        $this->assertStringContainsString('Tâches planifiées', $this->root->notifications()->first()->data['title']);
        $this->artisan('platform:health --alert')->assertSuccessful();
        $this->assertSame(1, $this->root->notifications()->count());

        Sanctum::actingAs($this->admin);
        $this->getJson('http://rapide.jibiat.test/api/v1/console/health')->assertForbidden();
    }
}
