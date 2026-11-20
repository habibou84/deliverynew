<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Services\Zones\StandardZones;
use Illuminate\Console\Command;
use Jibiat\Socle\Places\Places;

/**
 * Référentiel des lieux (socle) : quand la liste change (nouveaux quartiers), les zones standard de chaque entreprise
 * sont complétées. Sans changement, rien n'est fait.
 */
class SyncPlaces extends Command
{
    protected $signature = 'places:sync {--force : Synchroniser même si la liste n\'a pas changé}';

    protected $description = 'Complète les zones standard des entreprises avec la liste commune des lieux';

    public function handle(StandardZones $zones): int
    {
        $checksum = Places::checksum();
        if (! $this->option('force') && PlatformSetting::get('places_checksum') === $checksum) {
            $this->info('Liste des lieux inchangée.');

            return self::SUCCESS;
        }

        $zones->syncAll();
        PlatformSetting::put('places_checksum', $checksum);
        $this->info('Zones standard complétées : '.count(Places::all()).' lieux.');

        return self::SUCCESS;
    }
}
