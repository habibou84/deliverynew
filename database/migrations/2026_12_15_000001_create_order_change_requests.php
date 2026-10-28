<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modifications des e-commerçants : appliquées tout de suite (never), à valider si le prix change (price) ou
        // toujours à valider (always)
        Schema::table('companies', function (Blueprint $table) {
            $table->string('merchant_edit_approval', 10)->default('price')->after('validation_mode');
        });

        // Modification demandée par l'e-commerçant, en attente de l'équipe
        Schema::create('order_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            // Données envoyées (champs du formulaire) et changements calculés (avant → après)
            $table->json('data');
            $table->json('changes');
            $table->unsignedInteger('fees_before')->default(0);
            $table->unsignedInteger('fees_after')->default(0);
            $table->unsignedInteger('cod_before')->default(0);
            $table->unsignedInteger('cod_after')->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        // Le livreur a vu la dernière modification de sa mission (« J'ai vu »)
        Schema::table('order_assignments', function (Blueprint $table) {
            $table->timestamp('edit_seen_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_assignments', fn (Blueprint $table) => $table->dropColumn('edit_seen_at'));
        Schema::dropIfExists('order_change_requests');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('merchant_edit_approval'));
    }
};
