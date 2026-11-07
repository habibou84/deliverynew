<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Nouveaux prix du live : 10 lives offerts au départ, 500 F par jour de live (plus de plafond du mois),
 * forfait du mois à 7 000 F. Les réglages déjà enregistrés par la console sont mis à jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stored = json_decode((string) PlatformSetting::get('live.settings'), true);
        if (! is_array($stored)) {
            return;
        }
        unset($stored['monthly_cap']);
        $stored['trial_lives'] = 10;
        $stored['price_month'] = 7000;
        PlatformSetting::put('live.settings', json_encode($stored));
    }

    public function down(): void
    {
        // Les anciens prix ne sont pas remis : réglables dans la console
    }
};
