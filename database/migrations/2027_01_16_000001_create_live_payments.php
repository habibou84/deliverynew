<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements PayDunya des e-commerçants (live, forfait du mois, fiches IA) payés directement à Jibiat, et crédit des
 * agences : leur part de ces paiements, déduite de leurs factures (le reste est reporté).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('live_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->unsignedInteger('amount');
            $table->string('provider', 20)->default('paydunya');
            $table->string('token', 100)->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->string('method', 40)->nullable();
            $table->string('error', 255)->nullable();
            $table->foreignId('live_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason', 20);
            $table->string('label', 190);
            $table->foreignId('live_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('platform_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('live_charges', function (Blueprint $table) {
            $table->string('paid_via', 20)->default('ledger');
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->integer('credit_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', fn (Blueprint $t) => $t->dropColumn('credit_amount'));
        Schema::table('live_charges', fn (Blueprint $t) => $t->dropColumn('paid_via'));
        Schema::dropIfExists('platform_credits');
        Schema::dropIfExists('live_payments');
    }
};
