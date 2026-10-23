<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clients sous contrat (dans la durée, facturés par période) et clients ponctuels (une distribution à la fois).
 * Les e-commerçants existants sont considérés sous contrat, sans dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('client_type', 20)->default('contract')->after('status')->index();
            $table->date('contract_starts_on')->nullable()->after('client_type');
            $table->date('contract_ends_on')->nullable()->after('contract_starts_on');
            // monthly | fortnightly | weekly | per_distribution
            $table->string('billing_period', 20)->nullable()->after('contract_ends_on');
            $table->text('contract_terms')->nullable()->after('billing_period');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['client_type', 'contract_starts_on', 'contract_ends_on', 'billing_period', 'contract_terms']);
        });
    }
};
