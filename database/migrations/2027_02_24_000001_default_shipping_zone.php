<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jibiat\Socle\Places\Places;

/**
 * Expédition par défaut : chaque entreprise a une zone « Expédition » (prix fixe de la course jusqu'à la gare) ; la
 * ville de destination se choisit sur la course. Les villes lointaines ne sont plus des zones de livraison directe
 * (Grand-Bassam reste) : celles sans course en cours sont refermées, leurs prix gardés (« On livre ici » les rouvre).
 */
return new class extends Migration
{
    public const NAME = 'Expédition (intérieur du pays)';

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_city', 100)->nullable()->after('is_shipping');
        });
        Schema::table('zones', function (Blueprint $table) {
            $table->boolean('default_shipping')->default(false)->after('is_shipping');
        });

        $farCities = collect(Places::all())->where('type', Places::CITY)
            ->reject(fn ($p) => in_array($p['code'], ['abidjan', 'grand-bassam'], true))->pluck('code')->all();
        $final = ['delivered', 'returned', 'cancelled', 'rejected', 'lost'];

        DB::table('companies')->orderBy('id')->pluck('id')->each(function (int $companyId) use ($farCities, $final) {
            if (! DB::table('zones')->where('company_id', $companyId)->where('default_shipping', true)->exists()) {
                DB::table('zones')->insert([
                    'company_id' => $companyId, 'name' => self::NAME, 'city' => 'Côte d\'Ivoire', 'is_shipping' => true,
                    'default_shipping' => true, 'is_active' => true, 'is_pickup' => false,
                    'sort_order' => (int) DB::table('zones')->where('company_id', $companyId)->max('sort_order') + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $busy = DB::table('orders')->where('company_id', $companyId)->whereNotIn('status', $final)->pluck('delivery_zone_id')->unique()->all();
            DB::table('zones')->where('company_id', $companyId)->whereIn('place_code', $farCities)
                ->whereNotIn('id', $busy)->update(['is_active' => false]);
        });
    }

    public function down(): void
    {
        DB::table('zones')->where('default_shipping', true)->delete();
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('default_shipping'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('shipping_city'));
    }
};
