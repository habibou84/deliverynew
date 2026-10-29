<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connexion WhatsApp par QR code (non officielle) autorisée entreprise par entreprise, depuis la console. Éteinte
 * par défaut ; allumée pour les entreprises qui l'utilisent déjà, pour qu'elles ne perdent rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('whatsapp_qr_enabled')->default(false);
        });

        DB::table('companies')
            ->whereIn('id', DB::table('whatsapp_accounts')->where('provider', 'qr')->where('owner_type', 'company')->select('company_id'))
            ->update(['whatsapp_qr_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('whatsapp_qr_enabled');
        });
    }
};
