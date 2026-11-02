<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reprise : un paiement « terminé » à l'e-commerçant dans l'ancienne application vaut remise de l'argent par le
 * livreur (activé par défaut ; à couper si l'entreprise paie parfois l'e-commerçant avant que le livreur ait rendu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('legacy_paid_settles_courier')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('legacy_paid_settles_courier');
        });
    }
};
