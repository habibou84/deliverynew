<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Connexion à la console en deux étapes : mot de passe puis code d'une application d'authentification (TOTP),
 * codes de secours, appareil retenu 30 jours.
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

    private function confirm(string $challenge, string $code, array $extra = [])
    {
        return $this->postJson('http://admin.jibiat.test/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code, ...$extra]);
    }

    /** Application déjà configurée pour le compte ; renvoie la clé */
    private function enroll(): string
    {
        $secret = Totp::secret();
        $this->root->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => [hash('sha256', 'ABCDE23456')]])->save();

        return $secret;
    }

    private static function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_totp_matches_the_rfc_6238_reference_values(): void
    {
        // Clé « 12345678901234567890 » de la RFC 6238 (SHA-1), codes à 8 chiffres ramenés à 6
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('081804', Totp::code($secret, Totp::step(1111111109)));
        $this->assertSame('005924', Totp::code($secret, Totp::step(1234567890)));
        $this->assertSame(Totp::step(59), Totp::match($secret, '287 082', 59 + 30));
        $this->assertNull(Totp::match($secret, '287082', 59 + 90));
        $this->assertStringStartsWith('otpauth://totp/Jibiat%20Console:habib%40gmail.com?secret=', Totp::uri($secret, 'habib@gmail.com', 'Jibiat Console'));
    }

    public function test_the_first_console_login_sets_up_the_authenticator_app(): void
    {
        Notification::fake();
        $data = $this->login()->assertOk()->assertJsonMissingPath('token')
            ->assertJsonPath('two_factor.method', 'setup')->assertJsonPath('two_factor.account', 'habib@gmail.com')->json('two_factor');
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertStringStartsWith('otpauth://totp/', $data['uri']);
        // Plus aucun code envoyé par e-mail
        Notification::assertNothingSent();

        $secret = str_replace(' ', '', $data['secret']);
        $code = Totp::code($secret, Totp::step());
        $this->confirm($data['challenge'], self::wrong($code))->assertJsonValidationErrors(['code' => 'Code incorrect. Il reste 4 essai(s).']);
        $this->assertNull($this->root->fresh()->two_factor_secret);

        $session = $this->confirm($data['challenge'], $code)->assertOk()->assertJsonPath('user.id', $this->root->id)
            ->assertJsonCount(8, 'recovery_codes')->assertJsonMissingPath('user.two_factor_secret')->json();
        $this->assertNotEmpty($session['token']);
        $root = $this->root->fresh();
        $this->assertSame($secret, $root->two_factor_secret);
        $this->assertNotNull($root->two_factor_confirmed_at);
        // Clé chiffrée en base, codes de secours en empreintes
        $this->assertStringNotContainsString($secret, (string) DB::table('users')->where('id', $root->id)->value('two_factor_secret'));
        $this->assertNotContains($session['recovery_codes'][0], $root->two_factor_recovery_codes);

        // Connexion suivante : code de l'application, pas de nouvelle configuration
        $this->login()->assertOk()->assertJsonPath('two_factor.method', 'totp')->assertJsonMissingPath('two_factor.secret');
    }

    public function test_the_console_asks_for_the_authenticator_code_once_per_step(): void
    {
        $secret = $this->enroll();
        $challenge = $this->login()->assertOk()->assertJsonPath('two_factor.method', 'totp')->json('two_factor.challenge');
        $code = Totp::code($secret, Totp::step());

        $this->confirm($challenge, self::wrong($code))->assertJsonValidationErrors(['code' => 'Code incorrect. Il reste 4 essai(s).']);
        $session = $this->confirm($challenge, $code, ['remember' => true])->assertOk()->assertJsonPath('user.id', $this->root->id)
            ->assertJsonPath('recovery_codes', null)->json();
        $this->assertStringStartsWith('td_', $session['trusted_device']);

        // Demande à usage unique ; le même code ne rouvre pas une autre demande (rejeu)
        $this->confirm($challenge, $code)->assertJsonValidationErrors('code');
        $this->confirm($this->login()->json('two_factor.challenge'), $code)->assertJsonValidationErrors(['code' => 'Code incorrect. Il reste 4 essai(s).']);
        // Code du pas suivant (horloge du téléphone en avance) : accepté
        $this->confirm($this->login()->json('two_factor.challenge'), Totp::code($secret, Totp::step() + 1))->assertOk();

        // Appareil retenu : la session s'ouvre directement ; un autre jeton ne suffit pas
        $this->login(['trusted_device' => $session['trusted_device']])->assertOk()->assertJsonPath('user.id', $this->root->id);
        $this->login(['trusted_device' => 'td_'.str_repeat('x', 48)])->assertOk()->assertJsonPath('two_factor.method', 'totp');

        // Retenu 30 jours seulement
        $this->travel(31)->days();
        $this->login(['trusted_device' => $session['trusted_device']])->assertOk()->assertJsonPath('two_factor.method', 'totp');
    }

    public function test_a_recovery_code_works_once(): void
    {
        $this->enroll();
        $this->confirm($this->login()->json('two_factor.challenge'), 'abcde-23456')->assertOk()
            ->assertJsonPath('user.id', $this->root->id)->assertJsonPath('recovery_codes_left', 0);
        $this->confirm($this->login()->json('two_factor.challenge'), 'ABCDE23456')->assertJsonValidationErrors('code');
    }

    public function test_five_wrong_codes_end_the_attempt(): void
    {
        $secret = $this->enroll();
        $challenge = $this->login()->json('two_factor.challenge');
        $code = Totp::code($secret, Totp::step());
        for ($i = 0; $i < 5; $i++) {
            $this->confirm($challenge, self::wrong($code));
        }
        $this->confirm($challenge, $code)->assertJsonValidationErrors(['code' => 'Demande expirée : reconnectez-vous avec votre mot de passe.']);
    }

    public function test_the_server_command_removes_a_lost_authenticator(): void
    {
        $this->enroll();
        TrustedDevice::create(['user_id' => $this->root->id, 'token_hash' => hash('sha256', 'td_x'), 'expires_at' => now()->addDay()]);
        $this->artisan('console:2fa-reset', ['login' => '0711111111', '--force' => true])->assertFailed();
        $this->artisan('console:2fa-reset', ['login' => 'Habib@gmail.com', '--force' => true])->assertSuccessful();

        $this->assertNull($this->root->fresh()->two_factor_secret);
        $this->assertSame(0, TrustedDevice::count());
        $this->login()->assertOk()->assertJsonPath('two_factor.method', 'setup');
    }

    public function test_my_account_changes_the_app_and_the_recovery_codes(): void
    {
        $old = $this->enroll();
        $url = 'http://admin.jibiat.test/api/v1/console/account';
        $this->actingAs($this->root, 'sanctum');
        $this->getJson($url)->assertOk()->assertJsonPath('data.two_factor.enabled', true)->assertJsonPath('data.two_factor.recovery_codes_left', 1);

        // Mot de passe redemandé
        $this->postJson($url.'/two-factor/setup', ['current_password' => 'faux'])->assertJsonValidationErrors(['current_password' => 'Mot de passe incorrect.']);
        $setup = $this->postJson($url.'/two-factor/setup', ['current_password' => 'password'])->assertOk()->json('data');
        $new = str_replace(' ', '', $setup['secret']);
        $this->postJson($url.'/two-factor/confirm', ['code' => Totp::code($old, Totp::step())])->assertJsonValidationErrors('code');
        $this->assertSame($old, $this->root->fresh()->two_factor_secret);
        $this->postJson($url.'/two-factor/confirm', ['code' => Totp::code($new, Totp::step())])->assertOk()
            ->assertJsonCount(8, 'recovery_codes')->assertJsonPath('data.two_factor.recovery_codes_left', 8);
        $this->assertSame($new, $this->root->fresh()->two_factor_secret);

        $codes = $this->postJson($url.'/two-factor/recovery-codes', ['current_password' => 'password'])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codes[0]);
    }

    public function test_the_health_page_lists_accounts_without_the_app(): void
    {
        $this->actingAs($this->root, 'sanctum');
        $check = fn () => collect($this->getJson('http://admin.jibiat.test/api/v1/console/health')->assertOk()->json('data.checks'))->firstWhere('key', 'two_factor');
        $this->assertSame('warning', $check()['status']);
        $this->enroll();
        $this->assertSame('ok', $check()['status']);
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
