<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messagerie WhatsApp de la console : conversations du numéro de Jibiat (relié par QR code) avec les entreprises et
 * les prospects, et leurs messages (reçus, ou envoyés depuis la console).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('name')->nullable();
            // Entreprise reconnue d'après le numéro (compte de son équipe, ou son numéro)
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->string('last_message', 200)->nullable();
            $table->unsignedInteger('unread')->default(0);
            $table->timestamps();
        });

        Schema::create('platform_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            $table->text('body');
            $table->string('status', 20)->default('received'); // received | queued | sent | delivered | read | failed
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('error')->nullable();
            // Membre de la console qui a répondu
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_messages');
        Schema::dropIfExists('platform_conversations');
    }
};
