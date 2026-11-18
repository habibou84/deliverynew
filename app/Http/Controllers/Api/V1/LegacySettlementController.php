<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegacySettlement;
use App\Services\Legacy\LegacySettlements;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Réglages › Reprise › Contrôles et ménage › Solder l'ancien : simulation, solde, annulation (administrateurs).
 */
class LegacySettlementController extends Controller
{
    public function __construct(private readonly LegacySettlements $settlements) {}

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $before = $this->before($request);

        return response()->json(['data' => [
            'before' => $before->toDateString(),
            'simulation' => $this->settlements->simulate($company, $before),
            'last' => $this->settlements->describe(LegacySettlement::query()->with('user')->where('company_id', $company->id)->latest('id')->first()),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'before' => ['required', 'date_format:Y-m-d'],
            'clear_cash' => ['sometimes', 'boolean'],
            'confirm' => ['required', 'string'],
        ]);
        if (! in_array(mb_strtoupper(trim($data['confirm'])), ['SOLDER'], true)) {
            throw ValidationException::withMessages(['confirm' => 'Tapez SOLDER pour confirmer.']);
        }
        $before = $this->before($request);

        try {
            $settlement = $this->settlements->settle($request->user()->company, $request->user(), $before, (bool) ($data['clear_cash'] ?? true));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['before' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->settlements->describe($settlement->load('user'))], 201);
    }

    public function undo(Request $request, LegacySettlement $settlement): JsonResponse
    {
        abort_unless($settlement->company_id === $request->user()->company_id, 404);
        try {
            $this->settlements->undo($settlement);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->settlements->describe($settlement->fresh()->load('user'))]);
    }

    /** Date limite : jusqu'à aujourd'hui (non compris) ; par défaut aujourd'hui = toutes les anciennes courses livrées avant */
    private function before(Request $request): CarbonImmutable
    {
        $request->validate(['before' => ['nullable', 'date_format:Y-m-d']]);
        $tz = $request->user()->company->timezone ?: config('app.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $before = $request->filled('before') ? CarbonImmutable::parse($request->string('before'), $tz)->startOfDay() : $today;

        return $before->gt($today) ? $today : $before;
    }
}
