<?php

use App\Models\PlatformSetting;
use App\Services\Zones\StandardZones;
use Illuminate\Database\Migrations\Migration;
use Jibiat\Socle\Places\Places;

/**
 * Référentiel des lieux 1.13 (socle) : nouveaux quartiers et villes ajoutés aux zones standard de chaque entreprise
 * (les villes de l'intérieur arrivent masquées). Ensuite, « places:sync » le refait seul à chaque changement.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(StandardZones::class)->syncAll();
        PlatformSetting::put('places_checksum', Places::checksum());
    }

    public function down(): void {}
};
