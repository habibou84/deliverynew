<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nouveau prix du live : 500 F, jamais plus de 5 000 F par mois (au-delà, les lives du mois sont gratuits) ; les
 * fiches IA ne sont plus comprises dans le live : 20 offertes une fois, puis des recharges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->timestamp('live_welcome_cards_at')->nullable();
        });

        // Réglages déjà enregistrés dans la console : remplacés par la nouvelle offre
        $stored = json_decode((string) PlatformSetting::get('live.settings'), true);
        if (is_array($stored)) {
            PlatformSetting::put('live.settings', json_encode([
                ...$stored,
                'price_live' => 500, 'cards_per_live' => 0, 'trial_cards' => 0, 'monthly_cap' => 5000, 'welcome_cards' => 20,
            ]));
        }
    }

    public function down(): void
    {
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn('live_welcome_cards_at'));
    }
};
