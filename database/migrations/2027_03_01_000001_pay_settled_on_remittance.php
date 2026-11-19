<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paie déduite à chaque versement (réglage du plan de paie) : au point de caisse, les gains pas encore payés du
 * livreur (courses, primes, retenues ; pas le salaire fixe) sont retirés de ce qu'il doit verser. Une fiche de paie
 * « réglée sur le versement » garde la trace, liée au versement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_plans', function (Blueprint $table) {
            $table->boolean('settle_on_remittance')->default(false);
        });

        Schema::table('courier_payouts', function (Blueprint $table) {
            $table->foreignId('remittance_id')->nullable()->constrained('courier_remittances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courier_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remittance_id');
        });
        Schema::table('pay_plans', fn (Blueprint $table) => $table->dropColumn('settle_on_remittance'));
    }
};
