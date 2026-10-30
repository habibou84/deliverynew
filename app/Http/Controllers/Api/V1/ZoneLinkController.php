<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PricingGrid;
use App\Models\Zone;
use App\Models\ZoneLink;
use App\Services\Legacy\LegacyOrdersImporter;
use App\Services\Legacy\LegacyOrdersSync;
use App\Services\Zones\LegacyPriceProposals;
use App\Services\Zones\ZoneLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Places\Places;
use RuntimeException;
use Throwable;

/**
 * Reprise › Contrôles et ménage : rattacher les zones reprises aux lieux de la liste commune, reclasser les courses
 * « À préciser », et valider les tarifs proposés depuis l'ancienne application.
 */
class ZoneLinkController extends Controller
{
    public function __construct(private readonly ZoneLinks $links, private readonly LegacyPriceProposals $prices) {}

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $recent = ZoneLink::query()->where('company_id', $company->id)->where('status', 'done')
            ->where('created_at', '>', now()->subDays(ZoneLink::UNDO_DAYS))->with('zone:id,name')->latest('id')->limit(30)->get();

        return response()->json(['data' => [
            'proposals' => $this->links->proposals($company),
            'unknown_orders' => $this->links->unknownCount($company),
            'can_reclass' => $company->legacy_sync_url !== null && $company->legacy_sync_key !== null,
            'recent' => $recent->map(fn (ZoneLink $l) => [
                'id' => $l->id, 'action' => $l->action, 'zone' => $l->before['zone']['name'] ?? $l->zone?->name,
                'place' => $l->place_code ? Places::label($l->place_code) : null, 'created_at' => $l->created_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    /** Lieux de la liste commune, pour « Autre lieu… » */
    public function places(Request $request): JsonResponse
    {
        $q = Places::normalize((string) $request->query('q', ''));
        $places = collect(Places::all())->reject(fn ($p) => $p['code'] === 'abidjan')
            ->filter(fn ($p) => $q === '' || str_contains(Places::normalize(Places::label($p['code'])), $q)
                || collect($p['aliases'])->contains(fn ($a) => str_contains(Places::normalize($a), $q)))
            ->take(20)->map(fn ($p) => ['code' => $p['code'], 'label' => Places::label($p['code']), 'type' => $p['type']])->values();

        return response()->json(['data' => $places]);
    }

    public function link(Request $request, Zone $zone): JsonResponse
    {
        $data = $request->validate(['place' => ['required', 'string', 'max:120']], [], ['place' => 'lieu']);

        return $this->attempt(fn () => $this->links->link($zone, $data['place'], $request->user()), 'Zone rattachée.');
    }

    public function linkSure(Request $request): JsonResponse
    {
        return $this->attempt(fn () => ['count' => $this->links->linkSure($request->user()->company, $request->user())]);
    }

    public function keep(Zone $zone): JsonResponse
    {
        $this->links->keep($zone);

        return response()->json(['data' => ['id' => $zone->id]]);
    }

    public function undo(ZoneLink $link): JsonResponse
    {
        return $this->attempt(function () use ($link) {
            $this->links->undo($link);

            return ['id' => $link->id];
        });
    }

    public function reclass(Request $request, LegacyOrdersImporter $importer, LegacyOrdersSync $sync): JsonResponse
    {
        $company = $request->user()->company;

        return $this->attempt(fn () => $this->links->reclass($company, $importer, function (array $ids) use ($sync, $company) {
            $page = $sync->get($company, 'commands', ['ids' => implode(',', $ids)]);
            if (! is_array($page['data'] ?? null)) {
                throw new RuntimeException('Réponse inattendue de l\'ancienne application.');
            }

            return collect($page['data'])->keyBy(fn ($c) => (string) $c['id'])->all();
        }));
    }

    public function prices(Request $request): JsonResponse
    {
        $grid = $this->grid($request);

        return response()->json(['data' => [
            'grid_id' => $grid->id,
            'grids' => PricingGrid::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            ...$this->prices->proposals($request->user()->company, $grid),
        ]]);
    }

    public function applyPrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'zone_ids' => ['required', 'array', 'min:1'],
            'zone_ids.*' => ['integer'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'name' => ['nullable', 'string', 'max:100'],
        ], [], ['price' => 'prix']);
        $grid = $this->grid($request);

        return $this->attempt(fn () => ['group_id' => $this->prices->apply($request->user()->company, $grid, $data['zone_ids'], $data['price'], $data['name'] ?? null)->id], 'Tarif validé.');
    }

    public function ignorePrice(Request $request): JsonResponse
    {
        $data = $request->validate(['zone_ids' => ['required', 'array', 'min:1'], 'zone_ids.*' => ['integer']]);

        return response()->json(['data' => ['count' => $this->prices->ignore($request->user()->company, $data['zone_ids'])]]);
    }

    private function grid(Request $request): PricingGrid
    {
        return $request->filled('grid_id')
            ? PricingGrid::query()->findOrFail($request->integer('grid_id'))
            : PricingGrid::query()->where('is_default', true)->firstOrFail();
    }

    private function attempt(callable $action, ?string $message = null): JsonResponse
    {
        try {
            $result = DB::transaction($action);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'L\'ancienne application n\'a pas répondu. Réessayez dans un instant.'], 422);
        }

        return response()->json(['data' => $result instanceof ZoneLink ? ['id' => $result->id, 'action' => $result->action] : $result, 'message' => $message]);
    }
}
