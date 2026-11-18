<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inscription en ligne des e-commerçants : vérification du numéro (WhatsApp ou SMS) facultative.
 * Désactivée, le compte est créé et ouvert dès l'envoi du formulaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('merchant_signup_verify')->default(true)->after('merchant_signup');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('merchant_signup_verify'));
    }
};
