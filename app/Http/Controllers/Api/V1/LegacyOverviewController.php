<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunLegacyOrdersImport;
use App\Models\LegacyCashBaseline;
use App\Models\LegacyRecheck;
use App\Models\Order;
use App\Models\Zone;
use App\Services\Legacy\LegacyCashBaselines;
use App\Services\Legacy\LegacyOrdersPurge;
use App\Services\Legacy\LegacyRechecks;
use App\Services\Zones\ZoneLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accueil de la reprise (Réglages › Reprise) : où en est chaque étape et ce qui reste à faire, en une lecture légère.
 */
class LegacyOverviewController extends Controller
{
    /** Retard des courses en chemin à vérifier, comme le nettoyage par défaut */
    public const ON_THE_WAY_DAYS = 30;

    public function __construct(
        private readonly LegacyRechecks $rechecks,
        private readonly LegacyOrdersPurge $purges,
        private readonly LegacyCashBaselines $baselines,
        private readonly ZoneLinks $links,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $importExpires = $company->legacy_import_key_expires_at;
        $imported = $company->legacy_orders_imported_at !== null;
        $baseline = LegacyCashBaseline::query()->where('company_id', $company->id)->where('status', 'done')->exists();
        $recheck = LegacyRecheck::query()->where('company_id', $company->id)->latest('id')->first();

        return response()->json(['data' => [
            'connect' => [
                'accounts' => $company->legacy_import_key_hash !== null && $importExpires?->isFuture(),
                'orders' => $company->legacy_sync_url !== null && $company->legacy_sync_key !== null,
                'switch' => $company->legacy_switch_key_hash !== null,
            ],
            'accounts' => ['used_at' => $company->legacy_import_used_at?->toIso8601String()],
            'orders' => [
                'imported_at' => $company->legacy_orders_imported_at?->toIso8601String(),
                'count' => Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_id')->count(),
                'job' => RunLegacyOrdersImport::state($company->id)['status'] ?? null,
                'enabled' => (bool) $company->legacy_sync_enabled,
                'last_at' => $company->legacy_sync_last_at?->toIso8601String(),
                'last_error' => $company->legacy_sync_last_error,
            ],
            'switch' => [
                'switch_at' => $company->legacy_switch_at?->toIso8601String(),
                'started' => $company->legacy_switch_at !== null && ! $company->legacy_switch_at->isFuture(),
            ],
            'todo' => [
                'deleted' => $imported ? $this->rechecks->toCheck($company)->count() : 0,
                'on_the_way_days' => self::ON_THE_WAY_DAYS,
                'on_the_way' => $imported ? (int) collect($this->purges->onTheWayByCourier($company, self::ON_THE_WAY_DAYS))->sum('count') : 0,
                'balances' => $imported && $company->legacy_balances_validated_at === null,
                'cash' => $imported && ! $baseline && $this->baselines->held($company)->exists(),
                'recheck_running' => $recheck?->status === 'running',
                // Zones reprises à rattacher, prix de l'ancienne application à valider
                'zones' => $imported || $company->legacy_import_used_at !== null ? $this->links->pending($company)->count() : 0,
                'prices' => Zone::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_price')
                    ->whereNull('legacy_price_done_at')->whereNull('merged_into_id')->count(),
            ],
        ]]);
    }
}
