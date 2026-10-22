<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation à la semaine au choix de l'entreprise : rythme (mois ou semaine) et date du dernier changement ;
 * période d'une facture : mois (AAAA-MM) ou semaine ISO (AAAA-Wss).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('billing_cycle', 10)->default('monthly');
            // Jour où le rythme actuel commence (changement pris en compte le lundi suivant la demande)
            $table->date('billing_cycle_from')->nullable();
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->string('period', 10)->change();
            $table->string('cycle', 10)->default('monthly');
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropColumn('cycle');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle', 'billing_cycle_from']);
        });
    }
};
