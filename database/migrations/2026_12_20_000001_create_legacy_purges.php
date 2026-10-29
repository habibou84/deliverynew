<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nettoyage des anciennes courses reprises de l'ancienne application : courses jamais terminées là-bas, dont le jour
 * de livraison est passé depuis longtemps, clôturées sans message (annulées ou retournées). Chaque nettoyage garde
 * l'état d'avant de ses courses pour pouvoir être annulé pendant 7 jours ; une course nettoyée n'est plus recopiée
 * depuis l'ancienne application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_purges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('min_days');
            $table->json('filters')->nullable();
            $table->json('counts')->nullable();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('legacy_purge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legacy_purge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // État d'avant (statut, garde, dates, missions annulées) : pour annuler le nettoyage
            $table->json('previous');
            $table->unique(['legacy_purge_id', 'order_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('legacy_purged_at')->nullable();
            $table->foreignId('legacy_purge_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_purge_id');
            $table->dropColumn('legacy_purged_at');
        });
        Schema::dropIfExists('legacy_purge_items');
        Schema::dropIfExists('legacy_purges');
    }
};
