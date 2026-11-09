<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\PlatformHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Console › Santé › « Réglages de sécurité du serveur » : les réglages dangereux de production sont signalés.
 */
class SecurityHealthTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function security(): array
    {
        return collect(app(PlatformHealth::class)->checks())->firstWhere('key', 'security');
    }

    private function production(string $envMode = '0640'): void
    {
        $dir = sys_get_temp_dir().'/jibiat-env-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/.env', "APP_ENV=production\n");
        chmod($dir.'/.env', octdec($envMode));
        $this->app->useEnvironmentPath($dir);
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.url' => 'https://jibiat.test',
            'session.secure' => true,
            'trustedproxy.proxies' => null,
            'logging.channels.single.level' => 'info',
            'messaging.whatsapp.bridge.token' => str_repeat('a', 40),
        ]);
    }

    public function test_a_sound_production_setup_is_ok(): void
    {
        $this->production();

        $this->assertSame('ok', $this->security()['status'], $this->security()['detail']);
    }

    public function test_insecure_cookie_and_open_proxies_are_errors(): void
    {
        $this->production();
        config(['session.secure' => false, 'trustedproxy.proxies' => '*']);

        $check = $this->security();
        $this->assertSame('error', $check['status']);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE', $check['detail']);
        $this->assertStringContainsString('TRUSTED_PROXIES', $check['detail']);
    }

    public function test_a_world_readable_env_file_is_an_error(): void
    {
        $this->production('0644');

        $this->assertStringContainsString('chmod 640', $this->security()['detail']);
    }

    public function test_paydunya_test_mode_and_demo_password_are_errors_in_production(): void
    {
        $this->production();
        config(['payments.paydunya.master_key' => 'cle', 'payments.paydunya.mode' => 'test']);
        User::factory()->create(['company_id' => null, 'name' => 'Démo console', 'password' => 'password', 'status' => 'active']);

        $check = $this->security();
        $this->assertSame('error', $check['status']);
        $this->assertStringContainsString('PAYDUNYA_MODE=test', $check['detail']);
        $this->assertStringContainsString('Démo console', $check['detail']);
    }

    public function test_debug_logs_are_a_warning(): void
    {
        $this->production();
        config(['logging.channels.single.level' => 'debug']);

        $this->assertSame('warning', $this->security()['status']);
    }
}
