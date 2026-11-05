<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes du live par WhatsApp, sans intervention : le WhatsApp de l'e-commerçante est relié à Jibiat (code
 * d'association ou QR code). Pendant le live, une cliente envoie le code d'un produit, donne son nom et son quartier,
 * et la course part toute seule à l'agence. Une conversation par cliente et par live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // État du WhatsApp relié pour les lives : connected, disconnected (null : jamais relié)
            $table->string('live_wa_status', 16)->nullable();
            $table->string('live_wa_phone', 32)->nullable();
            $table->timestamp('live_wa_connected_at')->nullable();
        });

        Schema::create('live_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('push_name', 120)->nullable();
            // details : nom et quartier attendus ; confirm : « OUI » attendu (cliente déjà connue)
            $table->string('step', 16);
            $table->foreignId('live_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('customer_name', 120)->nullable();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['live_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chats');
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn(['live_wa_status', 'live_wa_phone', 'live_wa_connected_at']));
    }
};
