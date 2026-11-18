<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Solder l'ancien » : sommes dues aux e-commerçants pour les courses de l'ancienne application, marquées réglées hors
 * Jibiat (reversement sans message), et l'argent de ces courses compté remis à la caisse. Annulable 7 jours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('before');
            $table->boolean('clear_cash')->default(true);
            $table->unsignedInteger('merchants_count')->default(0);
            $table->unsignedInteger('entries_count')->default(0);
            $table->bigInteger('amount')->default(0);
            $table->unsignedInteger('cash_count')->default(0);
            $table->bigInteger('cash_amount')->default(0);
            $table->string('status', 20)->default('done');
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });
        Schema::table('merchant_payouts', fn (Blueprint $table) => $table->foreignId('legacy_settlement_id')->nullable()->constrained()->nullOnDelete());
        Schema::table('cash_collections', fn (Blueprint $table) => $table->foreignId('legacy_settlement_id')->nullable()->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('cash_collections', fn (Blueprint $table) => $table->dropConstrainedForeignId('legacy_settlement_id'));
        Schema::table('merchant_payouts', fn (Blueprint $table) => $table->dropConstrainedForeignId('legacy_settlement_id'));
        Schema::dropIfExists('legacy_settlements');
    }
};
