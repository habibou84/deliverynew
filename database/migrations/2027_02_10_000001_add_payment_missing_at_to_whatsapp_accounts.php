<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mode sans carte : Meta a refusé un message payant (modèle) faute de moyen de paiement sur le compte de
     * l'agence. Gardé jusqu'au prochain modèle accepté.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->timestamp('payment_missing_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', fn (Blueprint $table) => $table->dropColumn('payment_missing_at'));
    }
};
