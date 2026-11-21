<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paliers de prix par commune de départ (Proche, Moyen, Loin, Très loin) : l'écran des tarifs règle chaque trajet
 * en touchant un palier ; changer un palier change tous les trajets qui l'utilisent. Les prix eux-mêmes restent
 * dans pricing_rules : les paliers ne servent qu'à les saisir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_grids', function (Blueprint $table) {
            // { "zone_id": [1000, 1500, 2000, 3000] }
            $table->json('tiers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pricing_grids', function (Blueprint $table) {
            $table->dropColumn('tiers');
        });
    }
};
