<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jibiat IA en points : chaque formule donne des points d'IA par mois (vus par l'entreprise) et un plafond de coût
 * réel (repère interne). Les niveaux d'IA déjà choisis dans les formules deviennent des points (le volume du niveau)
 * et un plafond (celui du niveau). Coupure de l'IA par entreprise (console). Points comptés dans la consommation.
 */
return new class extends Migration
{
    private const TIERS = ['essentiel' => [1500, 12000], 'pro' => [5000, 35000], 'entreprise' => [15000, 95000]];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('ai_points')->nullable();
            // Francs CFA ; null : pas de plafond
            $table->unsignedInteger('ai_cost_cap')->nullable();
        });

        // Niveaux réglés dans la console (volume et plafond) pour convertir les formules
        $stored = json_decode((string) DB::table('platform_settings')->where('key', 'ai.tiers')->value('value'), true) ?: [];
        foreach (DB::table('plans')->whereNotNull('ai_tier')->get(['id', 'ai_tier']) as $plan) {
            [$orders, $cap] = self::TIERS[$plan->ai_tier] ?? [0, null];
            DB::table('plans')->where('id', $plan->id)->update([
                'ai_points' => (int) ($stored[$plan->ai_tier]['orders'] ?? $orders),
                'ai_cost_cap' => $cap === null ? null : (int) ($stored[$plan->ai_tier]['cost_cap'] ?? $cap),
            ]);
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('ai_tier');
        });

        Schema::table('companies', function (Blueprint $table) {
            // IA coupée pour cette entreprise par la console
            $table->boolean('ai_disabled')->default(false);
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            $table->unsignedInteger('points')->default(0);
        });
        // Commandes écrites déjà lues ce mois-ci : 1 point chacune
        DB::table('ai_usages')->where('feature', 'messages')->update(['points' => DB::raw('calls')]);
    }

    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropColumn('points');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('ai_disabled');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->string('ai_tier', 20)->nullable();
            $table->dropColumn(['ai_points', 'ai_cost_cap']);
        });
    }
};
