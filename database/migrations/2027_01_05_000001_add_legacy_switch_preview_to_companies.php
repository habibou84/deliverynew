<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bascule : avant la date, bandeau dans l'ancienne application (équipe : ouvrir Jibiat ; e-commerçants et livreurs :
 * essayer la nouvelle application, déjà connectés). Réglage par public et message, coupé par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('legacy_switch_preview')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('legacy_switch_preview');
        });
    }
};
