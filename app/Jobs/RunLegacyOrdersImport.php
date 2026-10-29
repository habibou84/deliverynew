<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Legacy\LegacyOrdersSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Simulation ou reprise des commandes de l'ancienne application, en arrière-plan et par étapes courtes : chaque étape
 * garde son point d'arrêt et lance la suivante. L'écran suit l'avancement et affiche le rapport (gardé un jour).
 * Une étape coupée (redémarrage du serveur, mise à jour) est relancée toute seule depuis son point d'arrêt.
 */
class RunLegacyOrdersImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Durée d'une étape (puis paquet en cours terminé) */
    public static int $stepSeconds = 60;

    /** Sans nouvelles depuis ce délai (un paquet lent de l'ancienne application prend jusqu'à 3 minutes) : coupée */
    public const STALE_SECONDS = 600;

    /** Erreurs de suite avant d'abandonner */
    public const MAX_ERRORS = 5;

    /** File « long » (config/queue.php, redis-long : 15 minutes avant de reprendre une tâche) */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $companyId, public bool $dryRun)
    {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long')->onQueue('long');
        }
    }

    public static function key(int $companyId): string
    {
        return "legacy-orders:{$companyId}";
    }

    /** Démarre une simulation ou une reprise (point d'arrêt effacé) */
    public static function start(int $companyId, bool $dryRun): void
    {
        Cache::put(self::key($companyId), [
            'mode' => $dryRun ? 'simulate' : 'import', 'status' => 'running', 'done' => 0, 'total' => 0,
            'started_at' => now()->toIso8601String(), 'beat_at' => now()->toIso8601String(), 'cursor' => null,
        ], now()->addDay());
        self::dispatch($companyId, $dryRun);
    }

    /** État pour l'écran (sans le point d'arrêt) ; « stalled » si l'étape en cours ne donne plus de nouvelles */
    public static function state(int $companyId): ?array
    {
        $state = Cache::get(self::key($companyId));
        if ($state === null) {
            return null;
        }
        if (self::stalled($state)) {
            $state['status'] = 'stalled';
        }
        unset($state['cursor']);

        return $state;
    }

    /** Relance les reprises coupées, depuis leur point d'arrêt (chaque minute) */
    public static function resumeStalled(): int
    {
        $count = 0;
        Company::query()->whereNotNull('legacy_sync_url')->pluck('id')->each(function (int $id) use (&$count) {
            $state = Cache::get(self::key($id));
            if ($state !== null && self::stalled($state)) {
                Cache::put(self::key($id), [...$state, 'beat_at' => now()->toIso8601String(), 'resumed' => ($state['resumed'] ?? 0) + 1], now()->addDay());
                self::dispatch($id, $state['mode'] === 'simulate');
                $count++;
            }
        });

        return $count;
    }

    private static function stalled(array $state): bool
    {
        return ($state['status'] ?? null) === 'running'
            && now()->parse($state['beat_at'] ?? $state['started_at'] ?? now()->subDay())->lt(now()->subSeconds(self::STALE_SECONDS));
    }

    public function handle(LegacyOrdersSync $sync): void
    {
        $key = self::key($this->companyId);
        $state = Cache::get($key);
        // Reprise annulée ou terminée entre-temps
        if (($state['status'] ?? null) !== 'running') {
            return;
        }
        $company = Company::findOrFail($this->companyId);
        Cache::put($key, [...$state, 'beat_at' => now()->toIso8601String()], now()->addDay());

        try {
            $cursor = $sync->step($company, $this->dryRun, $state['cursor'] ?? null, self::$stepSeconds,
                fn (int $done) => Cache::put($key, [...$state, 'done' => $done, 'beat_at' => now()->toIso8601String()], now()->addDay()));
        } catch (Throwable $e) {
            report($e);
            // Ancienne application lente ou injoignable : nouvel essai dans quelques minutes, abandon au bout de 5
            $errors = ($state['errors'] ?? 0) + 1;
            Cache::put($key, $errors >= self::MAX_ERRORS
                ? [...$state, 'status' => 'failed', 'error' => $e->getMessage()]
                : [...$state, 'errors' => $errors, 'error' => $e->getMessage()], now()->addDay());

            return;
        }

        $state = [...$state, 'errors' => 0, 'error' => null, 'done' => $cursor['done'], 'total' => $cursor['total'], 'beat_at' => now()->toIso8601String()];
        if ($cursor['finished']) {
            Cache::put($key, [...$state, 'status' => 'done', 'cursor' => null, 'finished_at' => now()->toIso8601String(), 'report' => $cursor['report']], now()->addDay());

            return;
        }

        Cache::put($key, [...$state, 'cursor' => $cursor], now()->addDay());
        self::dispatch($this->companyId, $this->dryRun);
    }
}
