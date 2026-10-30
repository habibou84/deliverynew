<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\PricingGrid;
use App\Models\PricingGroup;
use App\Models\Zone;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Réglages › Tarifs : prix par destination « depuis partout », par groupes de lieux (une commune entière couvre ses
 * quartiers ; un quartier placé dans un autre groupe prend le prix de ce groupe), lieux encore sans prix, et
 * vérification du prix d'un trajet.
 */
class PricingGroupController extends Controller
{
    public function index(PricingGrid $pricingGrid): JsonResponse
    {
        $groups = $pricingGrid->groups()->with('zones.parent')->get();
        $covered = $groups->flatMap(fn (PricingGroup $g) => $g->zones->pluck('id'))->flip();

        // Lieux actifs sans prix par destination : ni eux ni le lieu qui les contient ne sont dans un groupe
        $zones = Zone::query()->active()->get(['id', 'parent_id', 'name', 'place_code', 'is_shipping']);
        $byId = $zones->keyBy('id');
        $missing = $zones->filter(function (Zone $zone) use ($covered, $byId) {
            for ($z = $zone, $depth = 0; $z !== null && $depth < 5; $z = $byId->get($z->parent_id), $depth++) {
                if ($covered->has($z->id)) {
                    return false;
                }
            }

            return true;
        });

        return response()->json(['data' => [
            'groups' => $groups->map(fn (PricingGroup $g) => $this->describe($g))->values(),
            // Une commune sans prix suffit à résumer ses quartiers
            'missing' => $missing->filter(fn (Zone $z) => $z->parent_id === null || ! $missing->has($z->parent_id))
                ->map(fn (Zone $z) => ['id' => $z->id, 'name' => $z->name, 'is_shipping' => $z->is_shipping,
                    'quartiers' => $missing->where('parent_id', $z->id)->count()])->values(),
            'rules_count' => $pricingGrid->rules()->count(),
        ]]);
    }

    public function store(Request $request, PricingGrid $pricingGrid): JsonResponse
    {
        $data = $this->validated($request);
        $group = DB::transaction(function () use ($pricingGrid, $data) {
            $group = PricingGroup::create([
                'pricing_grid_id' => $pricingGrid->id,
                'name' => $data['name'],
                'price' => $data['price'],
                'sort_order' => (int) PricingGroup::where('pricing_grid_id', $pricingGrid->id)->max('sort_order') + 1,
            ]);
            $this->syncZones($group, $data['zone_ids']);

            return $group;
        });

        return response()->json(['data' => $this->describe($group->load('zones.parent'))], 201);
    }

    public function update(Request $request, PricingGrid $pricingGrid, PricingGroup $group): JsonResponse
    {
        abort_unless($group->pricing_grid_id === $pricingGrid->id, 404);
        $data = $this->validated($request);
        DB::transaction(function () use ($group, $data) {
            $group->update(['name' => $data['name'], 'price' => $data['price']]);
            $this->syncZones($group, $data['zone_ids']);
        });

        return response()->json(['data' => $this->describe($group->load('zones.parent'))]);
    }

    public function destroy(PricingGrid $pricingGrid, PricingGroup $group): JsonResponse
    {
        abort_unless($group->pricing_grid_id === $pricingGrid->id, 404);
        $group->delete();

        return response()->json(null, 204);
    }

    /** Prix d'un trajet avec cette grille, et d'où il vient */
    public function check(Request $request, PricingGrid $pricingGrid, PricingService $pricing): JsonResponse
    {
        $zone = Rule::exists('zones', 'id')->where('company_id', $pricingGrid->company_id);
        $data = $request->validate([
            'pickup_zone_id' => ['required', 'integer', $zone],
            'delivery_zone_id' => ['required', 'integer', $zone],
        ]);
        $merchant = (new Merchant)->forceFill(['company_id' => $pricingGrid->company_id, 'pricing_grid_id' => $pricingGrid->id]);
        $quote = $pricing->quote($merchant, Zone::findOrFail($data['pickup_zone_id']), Zone::findOrFail($data['delivery_zone_id']));
        $group = $quote->pricingGroupId ? PricingGroup::find($quote->pricingGroupId) : null;
        $rule = $quote->pricingRuleId ? DB::table('pricing_rules')->where('id', $quote->pricingRuleId)->first() : null;

        return response()->json(['data' => [
            ...$quote->toArray(),
            'source' => $group ? 'group' : 'rule',
            'group' => $group?->name,
            'rule' => $rule ? [Zone::find($rule->origin_zone_id)?->name, Zone::find($rule->destination_zone_id)?->name] : null,
            'grid_id' => $quote->pricingGridId,
        ]]);
    }

    /**
     * @return array{name: string, price: int, zone_ids: list<int>}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'zone_ids' => ['required', 'array', 'min:1'],
            'zone_ids.*' => ['integer', 'distinct', Rule::exists('zones', 'id')->where('company_id', $request->user()->company_id)],
        ], [], ['name' => 'nom', 'price' => 'prix', 'zone_ids' => 'lieux']);
    }

    /** Un lieu n'est que dans un groupe par grille : l'ajouter ici le retire de l'autre */
    private function syncZones(PricingGroup $group, array $zoneIds): void
    {
        DB::table('pricing_group_zone')->where('pricing_grid_id', $group->pricing_grid_id)->whereIn('zone_id', $zoneIds)
            ->where('pricing_group_id', '!=', $group->id)->delete();
        $group->zones()->sync(collect($zoneIds)->mapWithKeys(fn ($id) => [$id => ['pricing_grid_id' => $group->pricing_grid_id]])->all());
    }

    private function describe(PricingGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'price' => $group->price,
            'zones' => $group->zones->sortBy('name')->map(fn (Zone $z) => [
                'id' => $z->id, 'name' => $z->name, 'parent' => $z->parent?->name, 'full_name' => $z->fullName(),
                'place' => $z->place_code, 'is_neighborhood' => $z->parent_id !== null,
            ])->values(),
        ];
    }
}
