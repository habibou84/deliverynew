<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunLegacyOrdersImport;
use App\Models\Company;
use App\Models\Order;
use App\Services\Legacy\LegacyOrdersSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reprise des commandes de l'ancienne application (Réglages › Intégrations) : adresse et clé de lecture, simulation,
 * reprise, copie en continu (pause, « copier maintenant »), contrôle et validation des soldes.
 */
class LegacyOrdersController extends Controller
{
    public const MODULE = 'legacy/laravel-5.8/JibiatSyncController.php';

    public function __construct(private readonly LegacyOrdersSync $sync) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->describe($request->user()->company)]);
    }

    /** Adresse de l'ancienne application */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url:https', 'max:255']], [], ['url' => 'adresse']);
        // Le serveur de Jibiat appelle cette adresse : jamais une adresse interne (réseau privé, machine elle-même)
        $ip = gethostbyname((string) parse_url($data['url'], PHP_URL_HOST));
        if (filter_var($ip, FILTER_VALIDATE_IP) && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return response()->json(['message' => 'Cette adresse est interne : indiquez l\'adresse publique de l\'ancienne application.', 'errors' => ['url' => ['Adresse interne refusée.']]], 422);
        }
        $company = $request->user()->company;
        $company->forceFill(['legacy_sync_url' => rtrim($data['url'], '/')])->save();

        return response()->json(['data' => $this->describe($company)]);
    }

    /** Nouvelle clé de lecture, affichée une seule fois, à mettre dans le .env de l'ancienne application */
    public function createKey(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $key = 'jbr_'.Str::random(40);
        $company->forceFill(['legacy_sync_key' => $key])->save();

        return response()->json(['data' => [...$this->describe($company), 'key' => $key]], 201);
    }

    /** Vérifie que l'ancienne application répond, et donne ses chiffres */
    public function test(Request $request): JsonResponse
    {
        try {
            $summary = $this->sync->get($request->user()->company, 'summary', ['from' => LegacyOrdersSync::from()]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $summary['data'] ?? []]);
    }

    /** Simulation (rien n'est enregistré) ou reprise, en arrière-plan */
    public function run(Request $request, string $mode): JsonResponse
    {
        $company = $request->user()->company;
        abort_unless(in_array($mode, ['simulate', 'import'], true), 404);
        if (! $company->legacy_sync_url || ! $company->legacy_sync_key) {
            return response()->json(['message' => 'Indiquez d\'abord l\'adresse de l\'ancienne application et créez la clé de lecture.'], 422);
        }
        if ((Cache::get(RunLegacyOrdersImport::key($company->id))['status'] ?? null) === 'running') {
            return response()->json(['message' => 'Une reprise est déjà en cours : attendez qu\'elle se termine.'], 422);
        }

        Cache::put(RunLegacyOrdersImport::key($company->id), ['mode' => $mode, 'status' => 'running', 'done' => 0, 'total' => 0], now()->addDay());
        RunLegacyOrdersImport::dispatch($company->id, $mode === 'simulate');

        return response()->json(['data' => $this->describe($company)], 202);
    }

    /** Copie en continu : marche ou pause */
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $company = $request->user()->company;
        if ($data['enabled'] && $company->legacy_orders_imported_at === null) {
            return response()->json(['message' => 'Faites d\'abord la reprise des commandes.'], 422);
        }
        $company->forceFill(['legacy_sync_enabled' => $data['enabled']])->save();

        return response()->json(['data' => $this->describe($company)]);
    }

    /** Copie tout de suite, sans attendre la minute suivante */
    public function syncNow(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        if ($company->legacy_orders_imported_at === null) {
            return response()->json(['message' => 'Faites d\'abord la reprise des commandes.'], 422);
        }

        try {
            $counts = $this->sync->sync($company);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [...$this->describe($company->fresh()), 'counts' => $counts]]);
    }

    /** Soldes de l'ancienne application face à Jibiat : e-commerçants et livreurs */
    public function balances(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->sync->balances($request->user()->company)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function validateBalances(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $company->forceFill(['legacy_balances_validated_at' => now()])->save();

        return response()->json(['data' => $this->describe($company)]);
    }

    public function module(): BinaryFileResponse
    {
        return response()->download(base_path(self::MODULE), 'JibiatSyncController.php', ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @return array<string, mixed> */
    private function describe(Company $company): array
    {
        $orders = Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_id');

        return [
            'url' => $company->legacy_sync_url,
            'has_key' => $company->legacy_sync_key !== null,
            'enabled' => (bool) $company->legacy_sync_enabled,
            'imported_at' => $company->legacy_orders_imported_at?->toIso8601String(),
            'last_at' => $company->legacy_sync_last_at?->toIso8601String(),
            'last_error' => $company->legacy_sync_last_error,
            'today' => ($company->legacy_sync_stats['day'] ?? null) === today()->toDateString() ? $company->legacy_sync_stats : null,
            'switch_at' => $company->legacy_switch_at?->toIso8601String(),
            'balances_validated_at' => $company->legacy_balances_validated_at?->toIso8601String(),
            'orders' => (clone $orders)->count(),
            'conflicts' => (clone $orders)->whereNotNull('legacy_conflict_at')->count(),
            'months' => LegacyOrdersSync::MONTHS,
            'job' => Cache::get(RunLegacyOrdersImport::key($company->id)),
        ];
    }
}
