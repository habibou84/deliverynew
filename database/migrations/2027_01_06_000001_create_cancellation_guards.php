<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annulations : qui a annulé et pourquoi (motif obligatoire), demandes d'annulation à valider par un administrateur
 * au-delà de la limite du jour, règles d'annulation de l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cancel_reason_code', 40)->nullable();
            // merchant : l'e-commerçant ; company : l'équipe de l'entreprise
            $table->string('cancelled_by_type', 10)->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'cancelled_at']);
        });

        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // cancelled ou rejected
            $table->string('to_status', 20);
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason_code', 40);
            $table->text('note')->nullable();
            // pending, approved, refused, expired (course déjà ramassée au moment de décider)
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->json('cancel_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('cancel_rules'));
        Schema::dropIfExists('cancellation_requests');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'cancelled_at']);
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancel_reason_code', 'cancelled_by_type']);
        });
    }
};
