<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunLegacyRecheck;
use App\Models\LegacyRecheck;
use App\Models\Order;
use App\Services\Legacy\LegacyRechecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $default = $this->rechecks->defaultSince($company);
        $since = $this->since($request) ?? $default;

        return response()->json(['data' => [
            'default_since' => $default?->toDateString(),
            'since' => $since?->toDateString(),
            // Commandes qui seront relues depuis cette date
            'count' => $this->rechecks->scope($company, $since)->count(),
            'last' => $this->rechecks->describe(LegacyRecheck::query()->where('company_id', $company->id)->with('user:id,name')->latest('id')->first()),
            'to_check' => $this->rechecks->toCheck($company)->count(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate(['since' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']], [], ['since' => 'date']);
            $company = $request->user()->company;
            $recheck = $this->rechecks->start($company, $request->user(), $this->since($request) ?? $this->rechecks->defaultSince($company));
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

    private function since(Request $request): ?Carbon
    {
        $value = $request->input('since');

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::parse($value) : null;
    }
}
