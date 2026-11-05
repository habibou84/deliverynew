<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lives des e-commerçants : produits du live (codes A1, A2…), commandes prises pendant le live, fiches produit
 * remplies par l'IA (comptées) et frais du live (débités à l'e-commerçant, part de Jibiat sur la facture de
 * l'entreprise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // draft : en préparation ; live : en direct ; ended : terminé
            $table->string('status', 16)->default('draft');
            // single : payé à l'unité ; monthly : forfait du mois ; trial : découverte ; offered : offert par l'agence
            $table->string('plan', 16)->nullable();
            $table->unsignedSmallInteger('ai_cards_included')->default(0);
            $table->unsignedSmallInteger('ai_cards_used')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['merchant_id', 'status']);
        });

        Schema::create('live_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 4);
            $table->string('name', 160);
            $table->unsignedInteger('price');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('sold')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['live_id', 'code']);
        });

        Schema::create('live_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 32);
            $table->unsignedSmallInteger('quantity')->default(1);
            // ordered : course créée ; waiting : produit épuisé, liste d'attente
            $table->string('status', 16)->default('ordered');
            $table->string('source', 16)->default('manual');
            $table->timestamps();
            $table->index(['live_id', 'created_at']);
        });

        // Fiches produit remplies par l'IA : une ligne par fiche (reprises de photo comprises)
        Schema::create('live_ai_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 40)->unique();
            // live : comprise dans le live ; monthly : forfait ; pack : fiches achetées en plus
            $table->string('source', 16);
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->unsignedInteger('cost_millis')->default(0);
            $table->timestamps();
            $table->index(['merchant_id', 'created_at']);
        });

        // Frais du live : ce que paie l'e-commerçant (ou l'agence quand elle l'offre) et la part de Jibiat
        Schema::create('live_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_id')->nullable()->constrained()->nullOnDelete();
            // live, monthly, pack
            $table->string('kind', 16);
            $table->string('label', 160);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('platform_amount');
            $table->boolean('offered_by_company')->default(false);
            $table->unsignedSmallInteger('cards')->default(0);
            $table->foreignId('platform_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'platform_invoice_id']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            // Forfait live du mois en cours et fiches IA achetées en plus (non utilisées)
            $table->timestamp('live_plan_until')->nullable();
            $table->unsignedInteger('live_extra_cards')->default(0);
        });

        Schema::table('companies', function (Blueprint $table) {
            // L'agence offre le live à ses e-commerçants (elle paie à leur place)
            $table->boolean('live_offered')->default(false);
        });

        Schema::table('products', function (Blueprint $table) {
            // Catégorie reconnue par l'IA (fourchette de prix des boutiques Jibiat)
            $table->string('category', 80)->nullable();
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->unsignedInteger('live_amount')->default(0);
            $table->json('live_lines')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', fn (Blueprint $t) => $t->dropColumn(['live_amount', 'live_lines']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('category'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('live_offered'));
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn(['live_plan_until', 'live_extra_cards']));
        Schema::dropIfExists('live_charges');
        Schema::dropIfExists('live_ai_cards');
        Schema::dropIfExists('live_orders');
        Schema::dropIfExists('live_items');
        Schema::dropIfExists('lives');
    }
};
