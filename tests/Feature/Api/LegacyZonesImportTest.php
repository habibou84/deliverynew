<?php

namespace Tests\Feature\Api;

use App\Models\PricingRule;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Zones de l'ancienne application (table « fees ») : seuls les noms des lieux sont ajoutés aux zones, sans aucun tarif.
 */
class LegacyZonesImportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const SQL = <<<'SQL'
        INSERT INTO `fees` (`id`, `created_at`, `updated_at`, `destination`, `price`, `extraprice`, `category`, `zone`, `city`, `express`, `urgent`, `saved_by`, `is_ram_zone`) VALUES
        (2, NULL, NULL, 'Abobo', 1500, 1500, 1, 'ABJ_NORD', 'Abidjan', NULL, NULL, 'Admin', 'yes'),
        (8, NULL, NULL, 'Cocody', 1500, 1500, 1, 'ABJ_NORD', 'Abidjan', NULL, NULL, 'Admin', 'yes'),
        (28, NULL, NULL, 'Expedition (1500f + frais de la compagnie de transport)', 0, 0, 1, 'ABJ_NORD', 'Abidjan', NULL, NULL, NULL, NULL),
        (34, NULL, NULL, 'ABOBO  N\'DOTRE', 2000, 2000, 1, 'ABJ_NORD', 'Abidjan', NULL, NULL, 'Admin', NULL),
        (35, NULL, NULL, 'SONGON(Après Brimbresso 2500F)', 2500, 2500, 1, 'ABJ_NORD', 'Abidjan', NULL, NULL, 'Admin', 'yes');
        SQL;

    private function send(bool $dryRun)
    {
        return $this->post('/api/v1/company/legacy-import/zones', [
            'file' => UploadedFile::fake()->createWithContent('fees.sql', self::SQL),
            'dry_run' => $dryRun ? '1' : '0',
        ], ['Accept' => 'application/json']);
    }

    public function test_only_zone_names_are_added_without_prices(): void
    {
        $this->buildWorld();
        Sanctum::actingAs($this->admin);
        $zones = Zone::count();
        $rules = PricingRule::count();

        // Simulation : rien n'est enregistré ; Cocody existe déjà ; l'expédition n'est pas un lieu
        $this->send(true)->assertOk()
            ->assertJsonPath('data.counts.zones_created', 3)
            ->assertJsonPath('data.counts.zones_existing', 1)
            ->assertJsonPath('data.skipped.0.id', '28');
        $this->assertSame($zones, Zone::count());

        $this->send(false)->assertOk()->assertJsonPath('data.counts.zones_created', 3);
        $this->assertSame(['Abobo', "Abobo N'Dotre", 'Songon (Après Brimbresso)'],
            Zone::query()->whereIn('name', ['Abobo', "Abobo N'Dotre", 'Songon (Après Brimbresso)'])->orderBy('name')->pluck('name')->all());
        // Aucun tarif créé ni modifié
        $this->assertSame($rules, PricingRule::count());

        // Relancer ne crée pas de doublon
        $this->send(false)->assertOk()->assertJsonPath('data.counts.zones_created', 0)->assertJsonPath('data.counts.zones_existing', 4);
    }
}
