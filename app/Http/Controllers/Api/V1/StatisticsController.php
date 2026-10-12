<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Reports\Statistics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Statistiques de l'entreprise (back-office) : vue d'ensemble, livraisons, livreurs.
 * Les montants (chiffre d'affaires, encaissements, gains) sont réservés aux comptes
 * qui voient la caisse.
 */
class StatisticsController extends Controller
{
    public function __construct(private readonly Statistics $statistics) {}

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->overview($from, $to, $this->withAmounts($request))]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->deliveries($from, $to)]);
    }

    public function couriers(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::OrdersDispatch->value), 403);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->couriers($from, $to, $this->withAmounts($request))]);
    }

    private function stats(Request $request): Statistics
    {
        // Personnel de l'entreprise seulement (pas les e-commerçants)
        abort_if($request->user()->merchant_id !== null || $request->user()->company === null, 403);

        return $this->statistics->for($request->user()->company);
    }

    private function withAmounts(Request $request): bool
    {
        return $request->user()->can(Permission::FinanceView->value);
    }

    /**
     * Période demandée (dates du fuseau de l'entreprise) ; par défaut le mois en cours.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [], ['from' => 'début', 'to' => 'fin']);

        $today = CarbonImmutable::now($request->user()->company?->timezone ?: config('app.timezone'))->startOfDay();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')) : $today->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')) : $today;

        if ($from->diffInDays($to) + 1 > Statistics::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choisissez une période d\'un an au plus.']);
        }

        return [$from, $to];
    }
}
