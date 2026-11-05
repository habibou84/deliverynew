<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des comptes : création, changement de rôle, suspension, accès modifiés, lien de connexion envoyé,
 * déconnexion de tous les appareils, avec la personne qui l'a fait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_account_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_account_events');
    }
};
