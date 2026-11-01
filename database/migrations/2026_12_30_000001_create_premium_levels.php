<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Niveaux Premium de l'entreprise (Argent, Or, Platine) : conditions, avantages de livraison et formule Jibiat
 * Marchand offerte. Le niveau du marchand est revu chaque mois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->string('name', 40);
            // Conditions, sur la période du programme (90 jours par défaut)
            $table->unsignedInteger('min_delivered')->default(50);
            $table->decimal('min_success_rate', 4, 3)->default(0.85);
            $table->unsignedInteger('min_client_days')->default(0);
            // Avantages livraison
            $table->unsignedTinyInteger('delivery_discount_percent')->default(0);
            $table->unsignedTinyInteger('return_discount_percent')->default(0);
            $table->unsignedSmallInteger('cutoff_extra_minutes')->default(0);
            $table->boolean('priority_pickup')->default(false);
            $table->string('payout_frequency', 20)->nullable();
            // Jibiat Marchand offert
            $table->string('jm_plan', 20)->nullable();
            $table->unsignedTinyInteger('jm_free_months')->default(0);
            $table->unsignedTinyInteger('jm_discount_percent')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'rank']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->foreignId('premium_level_id')->nullable()->constrained('premium_levels')->nullOnDelete();
            $table->timestamp('premium_level_since')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            // Réduction Premium accordée sur les frais de livraison (déjà retirée de delivery_fee)
            $table->unsignedInteger('premium_discount')->default(0);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('premium_reviewed_at')->nullable();
        });

        Schema::table('premium_cards', function (Blueprint $table) {
            $table->string('plan', 20)->nullable();
            $table->unsignedTinyInteger('free_months')->nullable();
            $table->decimal('discount_rate', 4, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('premium_cards', fn (Blueprint $t) => $t->dropColumn(['plan', 'free_months', 'discount_rate']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('premium_reviewed_at'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('premium_discount'));
        Schema::table('merchants', function (Blueprint $t) {
            $t->dropConstrainedForeignId('premium_level_id');
            $t->dropColumn('premium_level_since');
        });
        Schema::dropIfExists('premium_levels');
    }
};
