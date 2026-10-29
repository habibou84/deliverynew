<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunLegacyRecheck;
use App\Models\LegacyRecheck;
use App\Models\Order;
use App\Services\Legacy\LegacyRechecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Recomparaison complète avec l'ancienne application (Réglages › Reprise) : relecture de toutes les courses reprises,
 * commandes supprimées là-bas annulées ou à vérifier, annulation pendant 7 jours.
 */
class LegacyRecheckController extends Controller
{
    public function __construct(private readonly LegacyRechecks $rechecks) {}

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        return response()->json(['data' => [
            'last' => $this->rechecks->describe(LegacyRecheck::query()->where('company_id', $company->id)->with('user:id,name')->latest('id')->first()),
            'to_check' => $this->rechecks->toCheck($company)->count(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $recheck = $this->rechecks->start($request->user()->company, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        RunLegacyRecheck::dispatch($recheck->id);

        return response()->json(['data' => $this->rechecks->describe($recheck->fresh('user'))], 202);
    }

    public function undo(Request $request, LegacyRecheck $recheck): JsonResponse
    {
        abort_unless($recheck->company_id === $request->user()->company_id, 404);
        try {
            $restored = $this->rechecks->undo($recheck);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [...$this->rechecks->describe($recheck->fresh('user')), 'restored' => $restored]]);
    }

    /** Supprimées dans l'ancienne application, à vérifier */
    public function deleted(Request $request): JsonResponse
    {
        $page = $this->rechecks->toCheck($request->user()->company)
            ->with(['merchant:id,business_name', 'cashCollection', 'deliveryCourier.user:id,name'])
            ->orderByDesc('legacy_deleted_at')->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $o) => [
                'id' => $o->id,
                'legacy_id' => $o->legacy_id,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'recipient' => $o->recipient_name,
                'merchant' => $o->merchant?->business_name,
                'courier' => $o->deliveryCourier?->user?->name,
                'cod_amount' => (int) $o->cod_amount,
                'cash_with_courier' => $o->cashCollection && $o->cashCollection->remittance_id === null && ! $o->cashCollection->received_by_company,
                'money' => $this->rechecks->hasMoney($o),
                'deleted_at' => $o->legacy_deleted_at?->toIso8601String(),
            ])->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function resolve(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['keep', 'cancel'])]]);
        $model = $this->rechecks->toCheck($request->user()->company)->whereKey($order)->firstOrFail();
        try {
            $data['action'] === 'keep' ? $this->rechecks->keep($model) : $this->rechecks->cancel($model);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['to_check' => $this->rechecks->toCheck($request->user()->company)->count()]]);
    }
}
