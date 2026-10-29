<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point de départ de l'argent chez les livreurs, pour les courses reprises de l'ancienne application : avant la date
 * choisie, l'argent est compté comme remis (l'ancienne application n'était pas toujours mise à jour), sauf ce que
 * chaque livreur a vraiment en main. Annulable pendant 7 jours ; un encaissement réglé ainsi ne revient plus chez le
 * livreur quand l'ancienne application est recopiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_cash_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('cutoff');
            // Par livreur : choix (tout remis / montant en main), avant, après, encaissements réglés
            $table->json('lines');
            $table->unsignedInteger('settled_count')->default(0);
            $table->unsignedBigInteger('settled_amount')->default(0);
            $table->string('status', 20)->default('done');
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('cash_collections', function (Blueprint $table) {
            $table->foreignId('legacy_cash_baseline_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_cash_baseline_id');
        });
        Schema::dropIfExists('legacy_cash_baselines');
    }
};
