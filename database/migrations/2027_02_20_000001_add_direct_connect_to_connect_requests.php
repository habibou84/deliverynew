<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connexion directe de Jibiat Marchand (contrat socle 1.12) : la demande vient du serveur de Jibiat Marchand,
 * sans mot de passe, avec la boutique, le téléphone vérifié, les colis qui attendent et le délai de décision.
 * La clé d'API donnée à l'acceptation est gardée chiffrée le temps de la remettre à Jibiat Marchand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connect_requests', function (Blueprint $table) {
            // Identifiant de la demande chez Jibiat Marchand (même demande, même réponse)
            $table->string('request_id', 64)->nullable();
            // request : ouverture d'un compte ; confirmation : rattacher un compte existant, accord du marchand
            $table->string('kind', 20)->default('request');
            $table->string('store_reference', 60)->nullable();
            $table->string('store_url', 500)->nullable();
            $table->string('pickup_place', 120)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->unsignedSmallInteger('waiting_orders')->default(0);
            $table->timestamp('decide_before')->nullable();
            $table->json('activity')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->text('pending_key')->nullable();
            $table->timestamp('decision_sent_at')->nullable();
            $table->unsignedSmallInteger('decision_attempts')->default(0);

            $table->unique(['company_id', 'request_id']);
            $table->index(['status', 'decide_before']);
        });
    }

    public function down(): void
    {
        Schema::table('connect_requests', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'request_id']);
            $table->dropIndex(['status', 'decide_before']);
            $table->dropColumn([
                'request_id', 'kind', 'store_reference', 'store_url', 'pickup_place', 'phone_verified_at', 'consent_at',
                'waiting_orders', 'decide_before', 'activity', 'decline_reason', 'pending_key', 'decision_sent_at', 'decision_attempts',
            ]);
        });
    }
};
