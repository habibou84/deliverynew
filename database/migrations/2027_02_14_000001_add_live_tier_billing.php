<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lives payés selon le nombre de commandes, par palier, à la fin du live : crédit live rechargé par PayDunya,
 * commandes offertes au départ (et en plus, par la console), ce que chaque live a compté. Les prix au jour et
 * les lives offerts disparaissent des réglages ; tout le monde repart avec les commandes offertes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // Peut passer sous zéro après un gros live : le live suivant attend une recharge
            $table->integer('live_credit')->default(0);
            $table->unsignedInteger('live_orders_used')->default(0);
            $table->unsignedInteger('live_orders_bonus')->default(0);
        });
        Schema::table('lives', function (Blueprint $table) {
            $table->unsignedInteger('billed_orders')->nullable();
            $table->unsignedInteger('billed_offered')->nullable();
            $table->unsignedInteger('billed_amount')->nullable();
            $table->timestamp('billed_at')->nullable();
        });

        $stored = json_decode((string) PlatformSetting::get('live.settings'), true);
        if (is_array($stored)) {
            unset($stored['price_live'], $stored['trial_lives'], $stored['trial_cards']);
            PlatformSetting::put('live.settings', json_encode($stored));
        }
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['live_credit', 'live_orders_used', 'live_orders_bonus']);
        });
        Schema::table('lives', function (Blueprint $table) {
            $table->dropColumn(['billed_orders', 'billed_offered', 'billed_amount', 'billed_at']);
        });
    }
};
