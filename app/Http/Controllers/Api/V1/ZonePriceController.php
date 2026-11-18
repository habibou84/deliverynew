<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PricingGrid;
use App\Models\PricingGroup;
use App\Models\Zone;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Zones et tarifs › Zones et prix : pour une grille, le prix de livraison de chaque lieu, sur sa ligne.
 * Les prix restent des groupes « depuis partout » (un groupe par prix : « Livraison à 1 500 F ») : donner un
 * prix à un lieu le range dans le groupe de ce prix (créé au besoin), l'effacer le retire de son groupe, et il
 * reprend alors le prix de sa commune. Les trajets particuliers passent toujours avant.
 */
class ZonePriceController extends Controller
{
    public function index(PricingGrid $pricingGrid): JsonResponse
    {
        $own = $this->ownPrices($pricingGrid);
        $zones = Zone::query()->with('parent:id,name')
            ->orderByRaw('COALESCE(parent_id, id)')->orderByRaw('parent_id IS NOT NULL')->orderBy('sort_order')->orderBy('name')
            ->get();
        $byId = $zones->keyBy('id');

        return response()->json(['data' => [
            'zones' => $zones->map(function (Zone $zone) use ($own, $byId) {
                $parent = $zone->parent_id ? $byId->get($zone->parent_id) : null;

                return [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'parent_id' => $zone->parent_id,
                    'is_active' => $zone->is_active,
                    'is_shipping' => $zone->is_shipping,
                    // Lieu de la liste commune de Jibiat (masquable) ; sinon zone créée par l'entreprise
                    'place' => $zone->place_code,
                    'shipping_fee_estimate' => $zone->shipping_fee_estimate,
                    'price' => $own[$zone->id]['price'] ?? null,
                    'group' => $own[$zone->id]['group'] ?? null,
                    // Prix repris de la commune quand le quartier n'a pas le sien
                    'inherited_price' => $parent ? ($own[$parent->id]['price'] ?? null) : null,
                ];
            })->values(),
            'rules_count' => $pricingGrid->rules()->count(),
        ]]);
    }

    public function update(Request $request, PricingGrid $pricingGrid, Zone $zone): JsonResponse
    {
        abort_unless($zone->company_id === $pricingGrid->company_id, 404);
        $data = $request->validate(['price' => ['present', 'nullable', 'integer', 'min:0', 'max:10000000']], [], ['price' => 'prix']);
        $price = $data['price'];

        DB::transaction(function () use ($pricingGrid, $zone, $price) {
            $previous = DB::table('pricing_group_zone')->where('pricing_grid_id', $pricingGrid->id)->where('zone_id', $zone->id)->value('pricing_group_id');
            DB::table('pricing_group_zone')->where('pricing_grid_id', $pricingGrid->id)->where('zone_id', $zone->id)->delete();

            if ($price !== null) {
                $group = PricingGroup::where('pricing_grid_id', $pricingGrid->id)->where('price', $price)->orderBy('sort_order')->first()
                    ?? PricingGroup::create([
                        'pricing_grid_id' => $pricingGrid->id,
                        'name' => 'Livraison à '.Money::format($price),
                        'price' => $price,
                        'sort_order' => (int) PricingGroup::where('pricing_grid_id', $pricingGrid->id)->max('sort_order') + 1,
                    ]);
                $group->zones()->attach($zone->id, ['pricing_grid_id' => $pricingGrid->id]);
            }

            // Un groupe resté sans lieu ne sert plus
            if ($previous !== null && ! DB::table('pricing_group_zone')->where('pricing_group_id', $previous)->exists()) {
                PricingGroup::whereKey($previous)->delete();
            }
        });

        $own = $this->ownPrices($pricingGrid);

        return response()->json(['data' => ['id' => $zone->id, 'price' => $own[$zone->id]['price'] ?? null, 'group' => $own[$zone->id]['group'] ?? null]]);
    }

    /**
     * Prix propre de chaque lieu dans la grille : celui du groupe qui le contient.
     *
     * @return array<int, array{price: int, group: string}>
     */
    private function ownPrices(PricingGrid $grid): array
    {
        return DB::table('pricing_group_zone')
            ->join('pricing_groups', 'pricing_groups.id', '=', 'pricing_group_zone.pricing_group_id')
            ->where('pricing_group_zone.pricing_grid_id', $grid->id)
            ->get(['pricing_group_zone.zone_id', 'pricing_groups.price', 'pricing_groups.name'])
            ->mapWithKeys(fn ($row) => [(int) $row->zone_id => ['price' => (int) $row->price, 'group' => $row->name]])
            ->all();
    }
}
