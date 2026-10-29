<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunLegacyPurge;
use App\Models\LegacyPurge;
use App\Services\Legacy\LegacyOrdersPurge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Nettoyage des anciennes courses reprises (Réglages › Reprise de l'ancienne application) : simulation, clôture,
 * annulation pendant 7 jours, et courses en chemin à vérifier livreur par livreur.
 */
class LegacyPurgeController extends Controller
{
    public function __construct(private readonly LegacyOrdersPurge $purges) {}

    /** Dernier nettoyage et simulation avec les filtres demandés */
    public function show(Request $request): JsonResponse
    {
        [$days, $filters] = $this->filters($request);
        $company = $request->user()->company;

        return response()->json(['data' => [
            'days' => $days,
            'simulation' => $this->purges->simulate($company, $days, $filters),
            'last' => $this->describe(LegacyPurge::query()->where('company_id', $company->id)->latest('id')->first()),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        [$days, $filters] = $this->filters($request);
        $data = $request->validate([
            'include' => ['required', 'array', 'min:1'],
            'include.*' => [Rule::in(['never_picked', 'not_delivered'])],
            'confirm' => ['required', 'string', Rule::in(['CLÔTURER', 'CLOTURER'])],
        ], ['confirm.in' => 'Tapez CLÔTURER pour confirmer.'], ['confirm' => 'confirmation']);

        try {
            $purge = $this->purges->start($request->user()->company, $request->user(), $days, $filters, $data['include']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        RunLegacyPurge::dispatch($purge->id);

        return response()->json(['data' => $this->describe($purge->fresh())], 202);
    }

    public function undo(Request $request, LegacyPurge $purge): JsonResponse
    {
        abort_unless($purge->company_id === $request->user()->company_id, 404);
        try {
            $restored = $this->purges->undo($purge);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [...$this->describe($purge->fresh()), 'restored' => $restored]]);
    }

    /** En chemin depuis longtemps : livreurs, puis les courses du livreur choisi */
    public function onTheWay(Request $request): JsonResponse
    {
        [$days] = $this->filters($request);
        $company = $request->user()->company;
        $courierId = $request->filled('courier_id') ? $request->integer('courier_id') : null;
        $orders = $request->has('courier_id')
            ? $this->purges->onTheWay($company, $days, $courierId ?: null)->paginate(50)
            : null;

        return response()->json(['data' => [
            'couriers' => $this->purges->onTheWayByCourier($company, $days),
            'orders' => $orders?->getCollection()->map(fn ($o) => [
                'id' => $o->id,
                'legacy_id' => $o->legacy_id,
                'tracking_code' => $o->tracking_code,
                'recipient' => trim(($o->recipient_name ?? '').' · '.($o->delivery_address ?? ''), ' ·'),
                'merchant' => $o->merchant?->business_name,
                'day' => ($o->delivery_scheduled_date ?? $o->created_at)?->toDateString(),
                'cod_amount' => (int) $o->cod_amount,
            ])->all(),
            'total' => $orders?->total(),
        ]]);
    }

    public function resolve(Request $request): JsonResponse
    {
        [$days] = $this->filters($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'outcome' => ['required', Rule::in(['delivered', 'returned'])],
        ]);

        return response()->json(['data' => ['done' => $this->purges->resolve($request->user()->company, $days, $data['ids'], $data['outcome'])]]);
    }

    /** @return array{0: int, 1: array{merchant_id: ?int, courier_id: ?int}} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', Rule::in([7, 15, 30, 60, 90])],
            'merchant_id' => ['nullable', 'integer'],
            'filter_courier_id' => ['nullable', 'integer'],
        ]);

        return [(int) ($data['days'] ?? 30), ['merchant_id' => $data['merchant_id'] ?? null, 'courier_id' => $data['filter_courier_id'] ?? null]];
    }

    private function describe(?LegacyPurge $purge): ?array
    {
        return $purge ? [
            'id' => $purge->id,
            'status' => $purge->status,
            'days' => $purge->min_days,
            'counts' => $purge->counts,
            'include' => $purge->filters['include'] ?? [],
            'done' => $purge->done,
            'total' => $purge->total,
            'by' => $purge->user?->name,
            'started_at' => $purge->created_at?->toIso8601String(),
            'finished_at' => $purge->finished_at?->toIso8601String(),
            'undone_at' => $purge->undone_at?->toIso8601String(),
            'can_undo' => $purge->canBeUndone(),
            'undo_until' => $purge->finished_at?->copy()->addDays(LegacyPurge::UNDO_DAYS)->toIso8601String(),
        ] : null;
    }
}
