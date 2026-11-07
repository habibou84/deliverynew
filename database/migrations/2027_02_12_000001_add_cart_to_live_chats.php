<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commande du live par WhatsApp : plusieurs articles dans une même course (panier), récapitulatif à confirmer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            // [{item_id, quantity}, …] ; live_item_id / quantity restent le dernier article demandé
            $table->json('cart')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->dropColumn('cart');
        });
    }
};
