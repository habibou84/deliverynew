<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jibiat IA : abonnement à part, en plus de la formule (niveau, essai, paiement au mois ou à l'année, arrêt en fin de
 * mois), consommation de l'IA par mois et par fonction, recharges, et ligne sur la facture de la plateforme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('ai_tier', 20)->nullable();
            // trial, active, expired, stopped
            $table->string('ai_status', 20)->nullable();
            $table->string('ai_period', 10)->default('monthly');
            $table->timestamp('ai_trial_started_at')->nullable();
            $table->timestamp('ai_trial_ends_at')->nullable();
            $table->timestamp('ai_started_at')->nullable();
            // Arrêt demandé : l'IA continue jusqu'à cette date (fin du mois, ou de l'année payée)
            $table->timestamp('ai_stop_at')->nullable();
            // Abonnement à l'année : payé jusqu'à cette date
            $table->date('ai_paid_until')->nullable();
            // Plafond de coût atteint ce mois-ci : l'IA se met en pause jusqu'au mois suivant (ou une recharge)
            $table->string('ai_paused_month', 7)->nullable();
            // Alertes déjà envoyées ce mois-ci (volume à 80 %, à 100 %, pause, fin d'essai)
            $table->json('ai_alerts')->nullable();
        });

        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->string('feature', 40);
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('voice_seconds')->default(0);
            // Coût réel en millièmes de franc CFA (petits appels)
            $table->unsignedBigInteger('cost_millis')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'month', 'feature']);
        });

        Schema::create('ai_recharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->unsignedInteger('amount');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('platform_invoice_id')->nullable()->constrained('platform_invoices')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'month']);
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->unsignedInteger('ai_amount')->default(0);
            $table->json('ai_lines')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropColumn(['ai_amount', 'ai_lines']);
        });
        Schema::dropIfExists('ai_recharges');
        Schema::dropIfExists('ai_usages');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['ai_tier', 'ai_status', 'ai_period', 'ai_trial_started_at', 'ai_trial_ends_at', 'ai_started_at',
                'ai_stop_at', 'ai_paid_until', 'ai_paused_month', 'ai_alerts']);
        });
    }
};
