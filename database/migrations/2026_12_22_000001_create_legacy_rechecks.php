<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recomparaison complète avec l'ancienne application : toutes les courses reprises sont relues ; les modifiées sont
 * mises à jour, les supprimées là-bas sont annulées sans message quand aucun argent n'est en jeu, sinon mises dans la
 * liste « Supprimées à vérifier ». La relecture de chaque nuit repère aussi les suppressions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_rechecks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('manual'); // manual | nightly
            $table->string('status', 20)->default('running'); // running | done | failed | undone
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->json('counts')->nullable();
            // Annulations des supprimées (annulables 7 jours, comme un nettoyage)
            $table->foreignId('legacy_purge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('legacy_deleted_at')->nullable();
            $table->timestamp('legacy_deleted_kept_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['legacy_deleted_at', 'legacy_deleted_kept_at']);
        });
        Schema::dropIfExists('legacy_rechecks');
    }
};
