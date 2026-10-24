<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avis sur une course : le destinataire (puis l'e-commerçant) note le livreur qui l'a livrée.
        // Une note par course et par source ; un avis abusif est annulé, jamais effacé.
        Schema::create('courier_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);          // recipient | merchant
            $table->unsignedTinyInteger('score');  // 1 à 5
            $table->json('tags')->nullable();
            $table->string('comment', 500)->nullable();
            $table->string('channel', 20);         // whatsapp | web | app
            $table->string('rater_phone', 30)->nullable();
            $table->foreignId('rater_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('flagged')->default(false);   // signalement grave à traiter
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'source']);
            $table->index(['company_id', 'courier_id', 'created_at']);
        });

        // Évaluation du mois par un responsable, sur quelques critères
        Schema::create('courier_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('period');                // premier jour du mois évalué
            $table->json('criteria');              // {punctuality: 4, ...}
            $table->decimal('score', 3, 2);
            $table->string('comment', 1000)->nullable();
            $table->boolean('comment_visible')->default(false);
            $table->timestamps();

            $table->unique(['courier_id', 'period']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Lien d'avis envoyé au destinataire après la livraison
            $table->string('rating_token', 40)->nullable()->unique();
            $table->timestamp('rating_requested_at')->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('ask_recipient_rating')->default(true);
            $table->decimal('rating_alert_threshold', 3, 2)->default(3.5);
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['ask_recipient_rating', 'rating_alert_threshold']));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['rating_token']);
            $table->dropColumn(['rating_token', 'rating_requested_at']);
        });
        Schema::dropIfExists('courier_reviews');
        Schema::dropIfExists('courier_ratings');
    }
};
