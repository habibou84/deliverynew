<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recomparaison à partir d'une date (date de création des commandes dans l'ancienne application) ; par défaut, le
 * jour du premier lancement de la reprise, gardé désormais sur l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_rechecks', function (Blueprint $table) {
            $table->date('since')->nullable();
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('legacy_orders_started_at')->nullable();
        });
        // Reprises déjà faites : faute de mieux, la fin de la reprise
        DB::table('companies')->whereNull('legacy_orders_started_at')->whereNotNull('legacy_orders_imported_at')
            ->update(['legacy_orders_started_at' => DB::raw('legacy_orders_imported_at')]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('legacy_orders_started_at');
        });
        Schema::table('legacy_rechecks', function (Blueprint $table) {
            $table->dropColumn('since');
        });
    }
};
