<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lives payés à la commande, comme Jibiat Marchand : un solde de commandes rechargé par packs (fiches IA incluses),
 * au lieu d'un prix par palier pris sur un crédit en francs. Le crédit live restant devient des commandes (25 F la
 * commande, le prix du petit pack). Les commandes offertes au départ passent de 300 à 50 : ceux qui ont déjà
 * préparé un live gardent les 300 annoncées (la différence en bonus), sauf si la console avait réglé ce nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', fn (Blueprint $table) => $table->integer('live_order_credits')->default(0));
        Schema::table('live_payments', function (Blueprint $table) {
            $table->unsignedInteger('orders')->nullable();
            $table->unsignedInteger('cards')->nullable();
        });

        DB::table('merchants')->where('live_credit', '!=', 0)->orderBy('id')->each(function ($m) {
            $orders = (int) ceil(abs((int) $m->live_credit) / 25) * ((int) $m->live_credit < 0 ? -1 : 1);
            DB::table('merchants')->where('id', $m->id)->update(['live_order_credits' => $orders, 'live_credit' => 0]);
        });

        $saved = json_decode((string) DB::table('platform_settings')->where('key', 'live.settings')->value('value'), true) ?: [];
        if (! array_key_exists('free_orders', $saved)) {
            DB::table('merchants')->whereNotNull('live_welcome_cards_at')->update(['live_orders_bonus' => DB::raw('live_orders_bonus + 250')]);
        }
    }

    public function down(): void
    {
        Schema::table('live_payments', fn (Blueprint $table) => $table->dropColumn(['orders', 'cards']));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('live_order_credits'));
    }
};
