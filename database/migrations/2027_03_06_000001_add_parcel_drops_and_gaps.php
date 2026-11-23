<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colis rendus au dépôt par les livreurs :
 *  - dépôts en libre-service (le livreur scanne la borne du dépôt, chaque colis, prend une photo ; un agent contrôle
 *    le bac plus tard, sinon confirmation automatique au bout de 24 h) ;
 *  - écarts de remise : colis que le livreur devait rendre et que l'agent n'a pas réceptionnés (motif obligatoire).
 *    Le livreur confirme ou conteste ; une contestation est tranchée par un administrateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_drops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            // pending (à contrôler), controlled (bac contrôlé), auto (confirmé sans contrôle)
            $table->string('status', 12)->default('pending');
            $table->json('order_ids');
            // Codes tapés à la main (étiquette illisible), signalés au contrôle
            $table->json('typed_codes')->nullable();
            $table->string('photo_path')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('dropped_at');
            $table->timestamp('controlled_at')->nullable();
            $table->foreignId('controlled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Déposé au dépôt par un livreur, en attente du contrôle du bac
            $table->foreignId('parcel_drop_id')->nullable()->after('custody_plan')->constrained()->nullOnDelete();
        });

        Schema::create('parcel_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // cash_point (point de caisse, réception au dépôt), drop_control (contrôle du bac)
            $table->string('source', 20);
            $table->foreignId('parcel_drop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            // Réponse du livreur : kept (je l'ai encore), handed (je l'ai remis)
            $table->string('courier_answer', 10)->nullable();
            $table->timestamp('answered_at')->nullable();
            // Décision : at_hub (il est au dépôt), courier (le livreur l'a)
            $table->string('resolution', 10)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'courier_answer', 'resolution']);
            $table->index(['courier_id', 'courier_answer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_gaps');
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('parcel_drop_id'));
        Schema::dropIfExists('parcel_drops');
    }
};
