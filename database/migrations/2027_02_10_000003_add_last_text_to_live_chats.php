<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Onglet « Messages » de l'écran du live : dernier message de la cliente et quartiers non reconnus (la vendeuse
     * choisit alors le quartier elle-même).
     */
    public function up(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->string('last_text', 300)->nullable();
            $table->unsignedTinyInteger('misses')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('live_chats', fn (Blueprint $table) => $table->dropColumn(['last_text', 'misses']));
    }
};
