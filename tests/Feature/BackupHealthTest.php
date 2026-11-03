<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\Certificates;
use App\Services\Platform\PlatformHealth;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sauvegardes (états écrits par deploy/backup.sh) et certificats HTTPS dans la console › Santé, et alertes.
 */
class BackupHealthTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/jibiat-backups-'.uniqid();
        File::makeDirectory($this->dir);
        config(['platform.backup_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $data */
    private function state(string $name, array $data, string $ago = '1 hour'): void
    {
        File::put($this->dir.'/etat-'.$name.'.json', json_encode(['at' => now()->sub($ago)->utc()->format('Y-m-d\TH:i:s\Z'), ...$data]));
    }

    /** @return array<string, array{status: string, detail: string}> */
    private function checks(): array
    {
        return collect(app(PlatformHealth::class)->checks())->keyBy('key')->all();
    }

    public function test_the_nightly_backup_state_is_read(): void
    {
        $this->assertSame('error', $this->checks()['backup']['status']);

        $this->state('sauvegarde', ['ok' => true, 'base' => 'base-2026-11-03.dump', 'base_bytes' => 12 * 1024 ** 2, 'files_bytes' => 300 * 1024 ** 2, 'copies' => 9]);
        $backup = $this->checks()['backup'];
        $this->assertSame('ok', $backup['status']);
        $this->assertStringContainsString('base 12 Mo, fichiers 300 Mo, 9 copie(s)', $backup['detail']);

        // Trop ancienne : la sauvegarde de la nuit ne tourne plus
        $this->state('sauvegarde', ['ok' => true, 'base_bytes' => 1, 'files_bytes' => 1], '30 hours');
        $this->assertSame('error', $this->checks()['backup']['status']);

        // Échec signalé par le script
        $this->state('sauvegarde', ['ok' => false, 'message' => 'La sauvegarde de la nuit a échoué.']);
        $backup = $this->checks()['backup'];
        $this->assertSame('error', $backup['status']);
        $this->assertStringContainsString('a échoué', $backup['detail']);
    }

    public function test_an_old_hand_made_backup_still_counts(): void
    {
        File::put($this->dir.'/2026-11-02.sql.gz', 'x');
        $this->assertSame('ok', $this->checks()['backup']['status']);
    }

    public function test_offsite_copy_is_expected_in_production_only(): void
    {
        $this->assertSame('ok', $this->checks()['backup_offsite']['status']);
        $this->app['env'] = 'production';
        $offsite = $this->checks()['backup_offsite'];
        $this->assertSame('warning', $offsite['status']);
        $this->assertStringContainsString('non configurée', $offsite['detail']);

        $this->state('distant', ['ok' => true, 'files' => 2, 'bytes' => 5 * 1024 ** 2]);
        $this->assertSame('ok', $this->checks()['backup_offsite']['status']);
        $this->state('distant', ['ok' => false, 'message' => 'Envoi impossible : accès refusé']);
        $offsite = $this->checks()['backup_offsite'];
        $this->assertSame(['error', true], [$offsite['status'], str_contains($offsite['detail'], 'accès refusé')]);
    }

    public function test_weekly_restore_test(): void
    {
        $this->assertSame('warning', $this->checks()['backup_restore']['status']);

        $this->state('restauration', ['ok' => true, 'backup' => 'base-2026-11-01.dump', 'counts' => ['orders' => [1250, 1260], 'merchants' => [40, 40]]], '2 days');
        $restore = $this->checks()['backup_restore'];
        $this->assertSame('ok', $restore['status']);
        $this->assertStringContainsString('1250 course(s), 40 e-commerçant(s)', $restore['detail']);

        $this->state('restauration', ['ok' => true, 'counts' => []], '10 days');
        $this->assertSame('warning', $this->checks()['backup_restore']['status']);

        $this->state('restauration', ['ok' => false, 'backup' => 'base-2026-11-01.dump', 'message' => 'table orders vide dans la sauvegarde']);
        $restore = $this->checks()['backup_restore'];
        $this->assertSame('error', $restore['status']);
        $this->assertStringContainsString('ne se restaure pas : table orders vide', $restore['detail']);
    }

    public function test_certificates_close_to_expiry_are_flagged(): void
    {
        config(['app.url' => 'https://jibiat.test', 'platform.domain' => 'jibiat.test']);
        Company::factory()->create(['custom_domain' => 'livraison.exemple.ci', 'custom_domain_status' => 'active']);
        Company::factory()->create(['custom_domain' => 'attente.exemple.ci', 'custom_domain_status' => 'pending']);
        $this->assertSame(['jibiat.test', 'admin.jibiat.test', 'livraison.exemple.ci'], app(Certificates::class)->hosts());

        $this->assertSame('ok', $this->checks()['certificates']['status']);
        $put = fn (array $hosts) => Cache::forever(Certificates::CACHE, ['checked_at' => now()->toIso8601String(), 'hosts' => $hosts]);

        $put(['jibiat.test' => ['expires_at' => now()->addDays(60)->toIso8601String(), 'error' => null]]);
        $this->assertSame('ok', $this->checks()['certificates']['status']);

        $put(['jibiat.test' => ['expires_at' => now()->addDays(60)->toIso8601String(), 'error' => null], 'livraison.exemple.ci' => ['expires_at' => now()->addDays(10)->toIso8601String(), 'error' => null]]);
        $certificates = $this->checks()['certificates'];
        $this->assertSame('warning', $certificates['status']);
        $this->assertStringContainsString('livraison.exemple.ci : expire dans', $certificates['detail']);

        $put(['jibiat.test' => ['expires_at' => now()->subDay()->toIso8601String(), 'error' => null]]);
        $certificates = $this->checks()['certificates'];
        $this->assertSame('error', $certificates['status']);
        $this->assertStringContainsString('jibiat.test : expiré', $certificates['detail']);
    }

    public function test_a_certificate_warning_alerts_once_a_day(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        // Les pannes (tâches planifiées, sauvegarde…) partent d'abord
        $this->artisan('platform:health --alert')->assertSuccessful();
        $this->assertSame(1, $root->notifications()->count());

        Cache::forever(Certificates::CACHE, ['checked_at' => now()->toIso8601String(), 'hosts' => ['jibiat.test' => ['expires_at' => now()->addDays(10)->toIso8601String(), 'error' => null]]]);
        $this->artisan('platform:health --alert')->assertSuccessful();
        $this->assertSame(2, $root->notifications()->count());
        $this->assertTrue($root->notifications()->get()->contains(fn ($n) => str_contains($n->data['title'], 'Certificats HTTPS')));
        $this->artisan('platform:health --alert')->assertSuccessful();
        $this->assertSame(2, $root->notifications()->count());
    }
}
