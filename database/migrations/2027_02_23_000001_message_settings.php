<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp › Messages automatiques : un interrupteur par message. Les messages qui n'avaient pas le leur
 * (colis en route et colis expédié au client, colis expédié au marchand, nouvelle date, Premium, versement reçu…)
 * en reçoivent un, rangé par nom de modèle ; absent, le message part.
 * Reprise : « Prévenir le destinataire » coupé coupait les trois messages aux clients, l'avis compris ; le
 * « Colis expédié » au marchand suivait l'interrupteur « Colis livré ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('message_settings')->nullable();
        });

        DB::table('companies')->orderBy('id')->each(function ($company) {
            $events = json_decode($company->merchant_whatsapp_events ?? 'null', true) ?: [];
            $recipients = (bool) $company->notify_recipients;
            DB::table('companies')->where('id', $company->id)->update([
                'message_settings' => json_encode([
                    'colis_en_route' => $recipients,
                    'colis_expedie_client' => $recipients,
                    'colis_expedie' => (bool) ($events['order.delivered'] ?? true),
                ]),
                ...($recipients ? [] : ['ask_recipient_rating' => false]),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('message_settings'));
    }
};
