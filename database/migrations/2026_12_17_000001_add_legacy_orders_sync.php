<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reprise des commandes de l'ancienne application : Jibiat va chercher les commandes (12 derniers mois et toutes
 * celles en cours) puis, toutes les minutes, celles créées ou modifiées là-bas. Numéro de l'ancienne fiche gardé sur
 * l'e-commerçant, le livreur, la course et le produit ; empreinte des données reçues pour ne rien refaire quand rien
 * n'a changé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Adresse de l'ancienne application et clé de lecture (chiffrée : Jibiat la présente à chaque appel)
            $table->string('legacy_sync_url')->nullable();
            $table->text('legacy_sync_key')->nullable();
            $table->boolean('legacy_sync_enabled')->default(false);
            // Heure de l'ancienne application jusqu'où les changements sont copiés
            $table->string('legacy_sync_cursor', 32)->nullable();
            $table->timestamp('legacy_sync_last_at')->nullable();
            $table->text('legacy_sync_last_error')->nullable();
            $table->json('legacy_sync_stats')->nullable();
            $table->timestamp('legacy_orders_imported_at')->nullable();
            $table->timestamp('legacy_balances_validated_at')->nullable();
        });

        foreach (['merchants', 'couriers', 'orders', 'products'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('legacy_id', 20)->nullable();
                $table->unique(['company_id', 'legacy_id']);
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('legacy_hash', 64)->nullable();
            $table->timestamp('legacy_synced_at')->nullable();
            // Course passée dans Jibiat (bascule) puis modifiée dans l'ancienne application : signalée, pas écrasée
            $table->timestamp('legacy_conflict_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['legacy_hash', 'legacy_synced_at', 'legacy_conflict_at']));

        foreach (['merchants', 'couriers', 'orders', 'products'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['company_id', 'legacy_id']);
                $table->dropColumn('legacy_id');
            });
        }

        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'legacy_sync_url', 'legacy_sync_key', 'legacy_sync_enabled', 'legacy_sync_cursor', 'legacy_sync_last_at',
            'legacy_sync_last_error', 'legacy_sync_stats', 'legacy_orders_imported_at', 'legacy_balances_validated_at',
        ]));
    }
};
