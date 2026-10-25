<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\PlatformAuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\ConsoleInvitation;
use App\Notifications\ConsoleLoginAlert;
use App\Notifications\SupportSessionOpened;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Équipe de la console : rôles (Propriétaire, Support, Comptable), invitations, tableau de bord, recherche,
 * assistance annoncée à l'entreprise, alerte de connexion avec « Ce n'était pas moi ».
 */
class ConsoleTeamTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'jibiat.test']);
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide', 'name' => 'Rapide Express']);
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'name' => 'Habib Root', 'phone' => '+2250700000099', 'email' => 'habib@gmail.com']);
    }

    private function console(string $path): string
    {
        return 'http://admin.jibiat.test/api/v1'.$path;
    }

    private function member(Role $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create(['company_id' => null, ...$attributes]);
    }

    public function test_each_console_role_only_reaches_its_own_pages(): void
    {
        $company = "/console/companies/{$this->company->id}";

        Sanctum::actingAs($this->member(Role::PlatformAccountant));
        $this->getJson($this->console('/console/companies'))->assertOk();
        $this->getJson($this->console('/console/dashboard'))->assertOk();
        $this->getJson($this->console('/console/invoices'))->assertOk();
        $this->getJson($this->console('/console/wave/payments'))->assertOk();
        $this->getJson($this->console('/console/wave'))->assertForbidden();
        $this->getJson($this->console('/console/whatsapp'))->assertForbidden();
        $this->getJson($this->console('/console/audit'))->assertForbidden();
        $this->getJson($this->console('/console/team'))->assertForbidden();
        $this->postJson($this->console("{$company}/support-session"), ['reason' => 'Test'])->assertForbidden();
        $this->postJson($this->console('/console/plans'), ['name' => 'Pro'])->assertForbidden();
        $this->putJson($this->console('/console/settings'), ['company_signup' => true])->assertForbidden();
        $this->patchJson($this->console("/companies/{$this->company->id}"), ['status' => 'suspended'])->assertForbidden();

        Sanctum::actingAs($this->member(Role::PlatformSupport));
        $this->getJson($this->console('/console/companies'))->assertOk();
        $this->getJson($this->console('/console/health'))->assertOk();
        $this->getJson($this->console('/console/whatsapp'))->assertOk();
        $this->putJson($this->console('/console/whatsapp/sms'), ['fallback' => true])->assertForbidden();
        $this->getJson($this->console('/console/invoices'))->assertForbidden();
        $this->getJson($this->console('/console/wave/payments'))->assertForbidden();
        $this->putJson($this->console("{$company}/billing-cycle"), ['billing_cycle' => 'weekly'])->assertForbidden();
        $this->getJson($this->console('/console/team'))->assertForbidden();
        $this->postJson($this->console("{$company}/support-session"), ['reason' => 'Aide au réglage'])->assertCreated();

        // Un compte d'entreprise n'entre pas dans la console
        Sanctum::actingAs($this->admin);
        $this->getJson($this->console('/console/dashboard'))->assertUnauthorized();
    }

    public function test_the_owner_invites_a_member_who_then_signs_in_normally(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->root);

        $this->postJson($this->console('/console/team'), ['name' => 'Awa', 'phone' => '07 07 12 34 56', 'email' => 'Awa@Jibiat.com', 'role' => 'company_admin'])
            ->assertJsonValidationErrors('role');
        $link = $this->postJson($this->console('/console/team'), ['name' => 'Awa', 'phone' => '07 07 12 34 56', 'email' => 'Awa@Jibiat.com', 'role' => 'platform_support'])
            ->assertCreated()->assertJsonPath('data.mailed', true)->json('data');
        $this->assertStringStartsWith('https://admin.jibiat.test/invitation/', $link['url']);
        Notification::assertSentOnDemand(ConsoleInvitation::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === 'awa@jibiat.com');

        $awa = User::query()->where('email', 'awa@jibiat.com')->firstOrFail();
        $this->assertNull($awa->company_id);
        $this->assertTrue($awa->isPlatformStaff());
        $this->getJson($this->console('/console/team'))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Awa', 'role' => 'platform_support', 'invited' => true]);

        // Le lien : mot de passe de 12 caractères obligatoire, et pas de session ouverte
        $this->app['auth']->forgetGuards();
        $token = basename($link['url']);
        $this->getJson($this->console("/invitations/{$token}"))->assertOk()->assertJsonPath('data.console', true);
        $this->postJson($this->console("/invitations/{$token}/accept"), [])->assertJsonValidationErrors('password');
        $this->postJson($this->console("/invitations/{$token}/accept"), ['password' => 'court-court', 'password_confirmation' => 'court-court'])->assertJsonValidationErrors('password');
        $this->postJson($this->console("/invitations/{$token}/accept"), ['password' => 'un mot de passe solide', 'password_confirmation' => 'un mot de passe solide'])
            ->assertOk()->assertJsonPath('login', true)->assertJsonMissingPath('token');
        $this->assertSame(0, $awa->tokens()->count());
        $this->assertTrue(Hash::check('un mot de passe solide', $awa->fresh()->password));

        // Connexion : mot de passe, puis code par e-mail comme le Propriétaire
        $this->postJson($this->console('/auth/login'), ['login' => '0707123456', 'password' => 'un mot de passe solide'])
            ->assertOk()->assertJsonPath('two_factor.channel', 'mail');
    }

    public function test_roles_and_suspension_keep_one_active_owner(): void
    {
        Sanctum::actingAs($this->root);
        $support = $this->member(Role::PlatformSupport);
        $support->createToken('test');
        TrustedDevice::query()->create(['user_id' => $support->id, 'token_hash' => hash('sha256', 'x'), 'label' => 'PC', 'expires_at' => now()->addDays(30)]);

        // Personne ne change son propre compte
        $this->patchJson($this->console("/console/team/{$this->root->id}"), ['role' => 'platform_support'])->assertJsonValidationErrors('member');
        // Un compte d'entreprise n'est pas un membre de la console
        $this->patchJson($this->console("/console/team/{$this->admin->id}"), ['status' => 'suspended'])->assertNotFound();

        // Changer le rôle déconnecte partout
        $this->patchJson($this->console("/console/team/{$support->id}"), ['role' => 'platform_accountant'])->assertOk()
            ->assertJsonFragment(['id' => $support->id, 'role' => 'platform_accountant']);
        $this->assertSame(0, $support->tokens()->count());
        $this->assertSame(0, TrustedDevice::query()->where('user_id', $support->id)->count());

        // Un autre Propriétaire peut être suspendu (il en reste un actif) : il ne se connecte plus
        $other = $this->member(Role::SuperAdmin, ['phone' => '+2250700000088']);
        $this->patchJson($this->console("/console/team/{$other->id}"), ['status' => 'suspended'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->console('/auth/login'), ['login' => '0700000088', 'password' => 'password'])->assertStatus(403);
    }

    public function test_dashboard_and_search_cover_the_whole_platform(): void
    {
        $order = $this->createOrder(['recipient_phone' => '+2250505123456']);
        Sanctum::actingAs($this->root);

        foreach (['month', '30d', 'year'] as $period) {
            $this->getJson($this->console("/console/dashboard?period={$period}"))->assertOk()
                ->assertJsonPath('data.kpis.orders.value', 1)
                ->assertJsonStructure(['data' => ['kpis' => ['revenue', 'receivable', 'orders', 'companies'], 'series', 'watch', 'top']]);
        }

        $this->getJson($this->console('/console/search?q=a'))->assertJsonValidationErrors('q');
        $this->getJson($this->console('/console/search?q=rapide'))->assertOk()->assertJsonPath('data.companies.0.id', $this->company->id);
        $this->getJson($this->console('/console/search?q=05 05 12 34'))->assertOk()->assertJsonPath('data.orders.0.id', $order->id);
        $this->getJson($this->console('/console/search?q='.urlencode($this->admin->name)))->assertOk()
            ->assertJsonPath('data.users.0.company.id', $this->company->id);

        // Chaque recherche est au journal
        $this->assertSame(3, PlatformAuditLog::query()->where('action', 'console.search')->where('user_id', $this->root->id)->count());
    }

    public function test_the_company_is_told_when_support_opens_its_space(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->root);
        $this->postJson($this->console("/console/companies/{$this->company->id}/support-session"), ['reason' => 'Réglage des tarifs'])->assertCreated();

        Notification::assertSentTo($this->admin, SupportSessionOpened::class, fn (SupportSessionOpened $n) => str_contains($n->toArray($this->admin)['body'], 'Réglage des tarifs'));
        Notification::assertNotSentTo($this->dispatcher, SupportSessionOpened::class);

        // L'administrateur voit l'historique et l'accès en cours ; un dispatcheur non
        Sanctum::actingAs($this->admin);
        $this->getJson('http://rapide.jibiat.test/api/v1/company/support-sessions')->assertOk()
            ->assertJsonPath('data.0.opened_by', 'Habib Root')->assertJsonPath('data.0.reason', 'Réglage des tarifs')
            ->assertJsonPath('active.open', true);
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('http://rapide.jibiat.test/api/v1/company/support-sessions')->assertForbidden();
    }

    public function test_a_console_login_sends_an_alert_with_a_not_me_link(): void
    {
        Notification::fake();
        $this->root->createToken('ancienne session');
        TrustedDevice::query()->create(['user_id' => $this->root->id, 'token_hash' => hash('sha256', 'td_'.str_repeat('y', 48)), 'label' => 'PC', 'expires_at' => now()->addDays(30)]);

        $this->postJson($this->console('/auth/login'), ['login' => '0700000099', 'password' => 'password', 'trusted_device' => 'td_'.str_repeat('y', 48)], ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])->assertOk();

        $url = null;
        Notification::assertSentOnDemand(ConsoleLoginAlert::class, function (ConsoleLoginAlert $n, array $c, AnonymousNotifiable $to) use (&$url) {
            $url = $n->toMail($to)->actionUrl;

            return $to->routes['mail'] === 'habib@gmail.com';
        });
        $this->assertStringStartsWith('https://admin.jibiat.test/console/securite/pas-moi/'.$this->root->id.'?', $url);
        $path = substr($url, strlen('https://admin.jibiat.test'));

        // Lien modifié : refusé
        $this->get('http://admin.jibiat.test'.str_replace('signature=', 'signature=0', $path))->assertForbidden();
        $this->get('http://admin.jibiat.test'.$path)->assertOk()->assertSee('nouveau mot de passe', false);
        $this->post('http://admin.jibiat.test'.$path, ['password' => 'trop court', 'password_confirmation' => 'trop court'])->assertOk()->assertSee('12');
        $this->post('http://admin.jibiat.test'.$path, ['password' => 'mon nouveau mot de passe', 'password_confirmation' => 'mon nouveau mot de passe'])->assertOk();

        $this->assertTrue(Hash::check('mon nouveau mot de passe', $this->root->fresh()->password));
        $this->assertSame(0, $this->root->tokens()->count());
        $this->assertSame(0, TrustedDevice::query()->where('user_id', $this->root->id)->count());
        $this->assertDatabaseHas('platform_audit_logs', ['user_id' => $this->root->id, 'action' => 'console.not-me']);
    }
}
