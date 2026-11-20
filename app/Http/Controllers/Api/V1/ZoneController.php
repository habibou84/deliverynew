<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ZoneResource;
use App\Models\Merchant;
use App\Models\Zone;
use App\Services\Zones\StandardZones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ZoneController extends Controller
{
    /**
     * Zones de l'entreprise (communes puis quartiers), visibles par tous ses utilisateurs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $zones = Zone::query()
            ->with('parent')
            // Adresses de l'ancienne application : rubrique à part (legacy), jamais dans les destinations
            ->where('legacy', false)
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderByRaw('COALESCE(parent_id, id)')
            ->orderByRaw('parent_id IS NOT NULL')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ZoneResource::collection($zones);
    }

    /**
     * Adresses de l'ancienne application (zones reprises sans lieu du référentiel) et leurs commandes : seulement pour
     * une entreprise qui a fait la reprise, tant que la reprise est ouverte sur la plateforme.
     */
    public function legacy(Request $request): JsonResponse
    {
        abort_unless($request->user()->company?->usesLegacy(), 404);

        $zones = Zone::query()->where('legacy', true)->whereNull('merged_into_id')->with('parent')->orderBy('name')->get();
        $orders = DB::table('orders')->where('company_id', $request->user()->company_id)->whereIn('delivery_zone_id', $zones->pluck('id'))
            ->selectRaw('delivery_zone_id, COUNT(*) AS n')->groupBy('delivery_zone_id')->pluck('n', 'delivery_zone_id');

        return response()->json(['data' => $zones->map(fn (Zone $z) => [
            'id' => $z->id,
            'name' => $z->name,
            'parent' => $z->parent?->name,
            'orders' => (int) ($orders[$z->id] ?? 0),
            'legacy_price' => $z->legacy_price,
        ])->values()]);
    }

    /** Villes de destination proposées pour une expédition */
    public function shippingCities(): JsonResponse
    {
        return response()->json(['data' => StandardZones::shippingCities()]);
    }

    public function store(Request $request): ZoneResource
    {
        $data = $request->validate($this->rules($request));
        // Les lieux viennent du référentiel commun (socle) : seule une zone d'expédition se crée ici. Un quartier
        // manquant se signale (POST /place-reports) et arrive pour toutes les entreprises.
        if (empty($data['is_shipping'])) {
            throw new BusinessRuleException('Les communes et quartiers viennent de la liste commune : signalez le quartier manquant, il sera ajouté pour tout le monde.', 'name');
        }

        return ZoneResource::make(Zone::create($data)->load('parent'));
    }

    public function update(Request $request, Zone $zone): ZoneResource
    {
        $data = $request->validate($this->rules($request, $zone));

        // Un seul niveau : une commune qui a des quartiers ne peut pas devenir un quartier
        if (! empty($data['parent_id']) && $zone->children()->exists()) {
            throw new BusinessRuleException("{$zone->name} a des quartiers : elle ne peut pas être placée dans une autre commune.", 'parent_id');
        }

        // Zone « Expédition » d'office : on règle seulement si on livre ici et ses frais habituels
        if ($zone->default_shipping) {
            $data = array_intersect_key($data, array_flip(['is_active', 'shipping_fee_estimate', 'sort_order']));
        }

        $zone->update($data);

        return ZoneResource::make($zone->load('parent'));
    }

    /**
     * Supprime une zone créée par erreur et jamais utilisée. Ses tarifs et son
     * rattachement aux livreurs partent avec elle. Une zone déjà utilisée (courses,
     * marchands, entrepôts, quartiers) se désactive à la place.
     */
    public function destroy(Zone $zone): JsonResponse
    {
        if ($zone->default_shipping) {
            throw new BusinessRuleException('La zone « Expédition » est créée d\'office : elle ne se supprime pas. Décochez « On livre ici » si vous n\'expédiez pas.');
        }
        $orders = DB::table('orders')->where(fn ($q) => $q->where('pickup_zone_id', $zone->id)->orWhere('delivery_zone_id', $zone->id))->count();
        $blockers = array_filter([
            $zone->children()->count() ? $zone->children()->count().' quartier(s)' : null,
            $orders ? $orders.' course(s)' : null,
            ($n = Merchant::where('pickup_zone_id', $zone->id)->count()) ? $n.' e-commerçant(s) (zone de ramassage)' : null,
            ($n = DB::table('hubs')->where('zone_id', $zone->id)->count()) ? $n.' entrepôt(s)' : null,
        ]);

        if ($blockers !== []) {
            throw new BusinessRuleException("{$zone->name} ne peut pas être supprimée : elle est utilisée par ".implode(', ', $blockers)
                .($orders ? '. Désactivez-la plutôt : elle ne sera plus proposée, l\'historique est conservé.' : '. Modifiez-les d\'abord, ou désactivez la zone.'));
        }

        $zone->delete();

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?Zone $zone = null): array
    {
        $required = $zone ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:100',
                Rule::unique('zones')->where('company_id', $request->user()->company_id)
                    ->where('parent_id', $request->input('parent_id', $zone?->parent_id))
                    ->ignore($zone)],
            // Un seul niveau de sous-zone : commune -> quartier
            'parent_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('zones', 'id')->where('company_id', $request->user()->company_id)->whereNull('parent_id'),
                Rule::notIn(array_filter([$zone?->id]))],
            'city' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_pickup' => ['sometimes', 'boolean'],
            'is_shipping' => ['sometimes', 'boolean'],
            'shipping_fee_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
