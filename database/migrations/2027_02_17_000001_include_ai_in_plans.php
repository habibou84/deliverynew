<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation simplifiée :
 * - Jibiat IA comprise dans la formule (niveau de l'IA choisi dans la console pour chaque formule), plus d'abonnement
 *   à part ; les abonnements à part déjà pris continuent jusqu'à leur arrêt ;
 * - programme Premium éteint (rallumé depuis la console, page Programme Premium).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // essentiel, pro, entreprise ; null : pas d'IA dans la formule
            $table->string('ai_tier', 20)->nullable();
        });

        DB::table('platform_settings')->updateOrInsert(['key' => 'premium.enabled'], ['value' => '0', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('ai_tier');
        });
    }
};
