<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartes Membre Premium Jibiat Marchand offertes par l'entreprise à ses meilleurs marchands (créées chez Jibiat
 * Marchand, contrat socle premium-card), carte automatique et nombre de cartes propre à l'entreprise, cartes payées
 * par l'entreprise sur sa facture mensuelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->unsignedInteger('number')->nullable();
            $table->string('label', 120)->nullable();
            $table->string('url', 255);
            $table->string('state', 16)->default('available');
            $table->string('reason', 16); // manual | automatic
            $table->foreignId('offered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('offered_at');
            $table->timestamp('revoked_at')->nullable();
            $table->date('free_until')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('store_name', 160)->nullable();
            $table->string('store_url', 255)->nullable();
            $table->timestamp('synced_at')->nullable();
            // Prix de la carte quand l'entreprise paie (0 quand Jibiat paie), facture où elle est comptée
            $table->unsignedInteger('price')->default(0);
            $table->foreignId('platform_invoice_id')->nullable()->constrained('platform_invoices')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'merchant_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('premium_auto')->default(false);
            $table->unsignedInteger('premium_quota')->nullable();
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->unsignedInteger('premium_cards_count')->default(0);
            $table->unsignedInteger('premium_cards_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropColumn(['premium_cards_count', 'premium_cards_amount']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['premium_auto', 'premium_quota']);
        });
        Schema::dropIfExists('premium_cards');
    }
};
