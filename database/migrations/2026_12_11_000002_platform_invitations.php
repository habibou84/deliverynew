<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invitations de l'équipe de la console : comptes sans entreprise
        Schema::table('phone_verifications', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Les invitations de la console (sans entreprise) empêchent de revenir en arrière : rien à faire
    }
};
