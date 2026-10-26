<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\ConsoleLoginCode;
use App\Services\Accounts\ConsoleTwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Code de connexion à la console : après le mot de passe, un code par e-mail ; appareil retenu 30 jours.
 */
class ConsoleTwoFactorTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'jibiat.test']);
        $this->buildWorld();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '+2250700000099', 'email' => 'habib@gmail.com']);
    }

    private function login(array $extra = [])
    {
        return $this->postJson('http://admin.jibiat.test/api/v1/auth/login', ['login' => '0700000099', 'password' => 'password', ...$extra]);
    }

    private function sentCode(): string
    {
        $code = null;
        Notification::assertSentOnDemand(ConsoleLoginCode::class, function (ConsoleLoginCode $n, array $channels, AnonymousNotifiable $to) use (&$code) {
            preg_match('/\*\*(\d{6})\*\*/', implode("\n", $n->toMail($to)->introLines), $m);
            $code = $m[1] ?? null;

            return $to->routes['mail'] === 'habib@gmail.com';
        });

        return (string) $code;
    }

    public function test_the_console_asks_for_the_code_sent_by_email(): void
    {
        Notification::fake();
        $data = $this->login()->assertOk()->assertJsonMissingPath('token')
            ->assertJsonPath('two_factor.channel', 'mail')->assertJsonPath('two_factor.to', 'ha•••@gmail.com')->json('two_factor');
        $this->assertSame(0, PersonalAccessToken::count());
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('http://admin.jibiat.test/api/v1/auth/two-factor', ['challenge' => $data['challenge'], 'code' => $wrong])
            ->assertJsonValidationErrors(['code' => 'Code incorrect. Il reste 4 essai(s).']);
        $session = $this->postJson('http://admin.jibiat.test/api/v1/auth/two-factor', ['challenge' => $data['challenge'], 'code' => $code, 'remember' => true])
            ->assertOk()->assertJsonPath('user.id', $this->root->id)->json();
        $this->assertNotEmpty($session['token']);
        $this->assertStringStartsWith('td_', $session['trusted_device']);

        // Code à usage unique
        $this->postJson('http://admin.jibiat.test/api/v1/auth/two-factor', ['challenge' => $data['challenge'], 'code' => $code])->assertJsonValidationErrors('code');

        // Appareil retenu : la session s'ouvre directement ; un autre jeton ne suffit pas
        $this->login(['trusted_device' => $session['trusted_device']])->assertOk()->assertJsonPath('user.id', $this->root->id);
        $this->login(['trusted_device' => 'td_'.str_repeat('x', 48)])->assertOk()->assertJsonPath('two_factor.channel', 'mail');

        // Retenu 30 jours seulement
        $this->travel(31)->days();
        $this->login(['trusted_device' => $session['trusted_device']])->assertOk()->assertJsonPath('two_factor.channel', 'mail');
    }

    public function test_the_code_is_also_shown_on_screen_while_the_temporary_switch_is_on(): void
    {
        Notification::fake();
        $shown = $this->login()->assertOk()->json('two_factor.code');
        $this->assertSame(ConsoleTwoFactor::SHOW_CODE_ON_SCREEN ? $this->sentCode() : null, $shown);
    }

    public function test_five_wrong_codes_end_the_attempt(): void
    {
        Notification::fake();
        $challenge = $this->login()->json('two_factor.challenge');
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('http://admin.jibiat.test/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $wrong]);
        }
        $this->postJson('http://admin.jibiat.test/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertJsonValidationErrors(['code' => 'Code expiré : reconnectez-vous pour en recevoir un nouveau.']);
    }

    public function test_without_any_way_to_send_the_code_the_console_still_opens_and_health_says_so(): void
    {
        config(['mail.default' => 'log']);
        $this->root->update(['email' => null]);
        $token = $this->login()->assertOk()->assertJsonPath('user.id', $this->root->id)->json('token');

        $this->withToken($token)->getJson('http://admin.jibiat.test/api/v1/console/health')->assertOk()
            ->assertJsonPath('data.checks', fn ($checks) => collect($checks)->firstWhere('key', 'two_factor')['status'] === 'error');
    }

    public function test_company_accounts_log_in_without_code_and_a_new_password_forgets_devices(): void
    {
        $this->company->update(['slug' => 'rapide']);
        $this->postJson('http://rapide.jibiat.test/api/v1/auth/login', ['login' => $this->admin->phone, 'password' => 'password'])->assertOk()->assertJsonPath('user.id', $this->admin->id);

        TrustedDevice::create(['user_id' => $this->root->id, 'token_hash' => hash('sha256', 'td_x'), 'expires_at' => now()->addDay()]);
        $this->actingAs($this->root, 'sanctum')->putJson('http://admin.jibiat.test/api/v1/auth/password', [
            'current_password' => 'password', 'password' => 'un-nouveau-mot-de-passe', 'password_confirmation' => 'un-nouveau-mot-de-passe',
        ])->assertOk();
        $this->assertSame(0, TrustedDevice::count());
    }

    public function test_my_account_page_changes_identity_and_forgets_devices(): void
    {
        $url = 'http://admin.jibiat.test/api/v1/console/account';
        TrustedDevice::create(['user_id' => $this->root->id, 'token_hash' => hash('sha256', 'td_a'), 'label' => 'Mozilla Chrome Windows', 'expires_at' => now()->addDay()]);
        TrustedDevice::create(['user_id' => $this->root->id, 'token_hash' => hash('sha256', 'td_b'), 'expires_at' => now()->addDay()]);
        $this->actingAs($this->root, 'sanctum');

        $this->getJson($url)->assertOk()->assertJsonPath('data.email', 'habib@gmail.com')->assertJsonCount(2, 'data.devices');

        // Le nom seul : sans mot de passe ; l'e-mail : mot de passe redemandé
        $this->putJson($url, ['name' => 'Habib', 'phone' => '0700000099', 'email' => 'habib@gmail.com'])->assertOk()->assertJsonPath('data.name', 'Habib');
        $this->putJson($url, ['name' => 'Habib', 'phone' => '0700000099', 'email' => 'nouveau@gmail.com'])->assertJsonValidationErrors('current_password');
        $this->putJson($url, ['name' => 'Habib', 'phone' => '0700000099', 'email' => 'nouveau@gmail.com', 'current_password' => 'faux'])
            ->assertJsonValidationErrors(['current_password' => 'Mot de passe incorrect.']);
        $this->putJson($url, ['name' => 'Habib', 'phone' => '0700000099', 'email' => 'Nouveau@Gmail.com', 'current_password' => 'password'])
            ->assertOk()->assertJsonPath('data.email', 'nouveau@gmail.com');

        // Un appareil, puis tous
        $first = TrustedDevice::query()->orderBy('id')->value('id');
        $this->deleteJson($url.'/devices/'.$first)->assertOk()->assertJsonCount(1, 'data.devices');
        $this->deleteJson($url.'/devices')->assertOk()->assertJsonCount(0, 'data.devices');

        // Réservé au super administrateur
        $this->company->update(['slug' => 'rapide']);
        $this->actingAs($this->admin, 'sanctum')->getJson('http://rapide.jibiat.test/api/v1/console/account')->assertForbidden();
    }
}
