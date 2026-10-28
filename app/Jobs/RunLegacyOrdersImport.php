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
 * Simulation ou reprise des commandes de l'ancienne application, en arrière-plan : l'écran suit l'avancement et
 * affiche le rapport (gardé un jour).
 */
class RunLegacyOrdersImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $companyId, public bool $dryRun) {}

    public static function key(int $companyId): string
    {
        return "legacy-orders:{$companyId}";
    }

    public function handle(LegacyOrdersSync $sync): void
    {
        $company = Company::findOrFail($this->companyId);
        $key = self::key($this->companyId);
        $state = ['mode' => $this->dryRun ? 'simulate' : 'import', 'status' => 'running', 'done' => 0, 'total' => 0, 'started_at' => now()->toIso8601String()];
        Cache::put($key, $state, now()->addDay());

        try {
            $report = $sync->run($company, $this->dryRun, function (int $done, int $total) use ($key, &$state) {
                $state = [...$state, 'done' => $done, 'total' => $total];
                Cache::put($key, $state, now()->addDay());
            });
            Cache::put($key, [...$state, 'status' => 'done', 'finished_at' => now()->toIso8601String(), 'report' => $report], now()->addDay());
        } catch (Throwable $e) {
            report($e);
            Cache::put($key, [...$state, 'status' => 'failed', 'error' => $e->getMessage()], now()->addDay());
        }
    }
}
