<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reprise : commandes de l'ancienne application mises de côté (e-commerçant introuvable, erreur) et relancées à chaque
 * copie, et copie des comptes (e-commerçants, livreurs) depuis l'ancienne application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_pending_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('legacy_id', 40);
            $table->string('client_legacy_id', 40)->nullable();
            $table->string('client_name')->nullable();
            $table->string('reason', 255);
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('last_tried_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'legacy_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            // Copie des comptes (module version 4) : heure de départ de la copie suivante, dernière copie, chiffres du jour
            $table->string('legacy_accounts_cursor', 32)->nullable();
            $table->timestamp('legacy_accounts_last_at')->nullable();
            $table->json('legacy_accounts_stats')->nullable();
            // Version du module de l'ancienne application, relue au plus une fois par heure
            $table->unsignedSmallInteger('legacy_module_version')->nullable();
            $table->timestamp('legacy_module_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['legacy_accounts_cursor', 'legacy_accounts_last_at', 'legacy_accounts_stats', 'legacy_module_version', 'legacy_module_checked_at']);
        });
        Schema::dropIfExists('legacy_pending_commands');
    }
};
