<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Compte Jibiat Marchand d'un marchand parrainé par l'entreprise, tel que Jibiat Marchand le rapporte
        // (PUT /api/public/v1/merchant-app, contrat merchant-app de habibou84/socle) : abonnement et commission
        Schema::create('merchant_app_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('store_name', 120);
            $table->string('store_url', 255);
            $table->boolean('referred')->default(true);
            $table->timestamp('signed_up_at')->nullable();
            $table->string('plan_name', 80)->nullable();
            $table->string('plan_status', 20)->default('none'); // trialing | active | past_due | cancelled | expired | none
            $table->decimal('commission_rate', 5, 4)->default(0);
            $table->unsignedSmallInteger('commission_months')->default(0);
            $table->date('commission_ends_at')->nullable();
            $table->string('currency', 3)->default('XOF');
            $table->unsignedBigInteger('earned_total')->default(0);
            $table->unsignedBigInteger('earned_month')->default(0);
            $table->unsignedBigInteger('paid_total')->default(0);
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['company_id', 'referred']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_app_accounts');
    }
};
