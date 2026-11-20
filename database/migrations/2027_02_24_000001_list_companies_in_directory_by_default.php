<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Annuaire automatique : toutes les entreprises y figurent dès qu'elles sont prêtes (zones et tarifs), y compris
 * celles d'aujourd'hui ; chacune peut s'en retirer dans ses réglages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('directory_listed')->default(true)->change();
        });
        DB::table('companies')->update(['directory_listed' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('directory_listed')->default(false)->change();
        });
    }
};
