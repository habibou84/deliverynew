<?php

namespace App\Jobs;

use App\Models\LegacyRecheck;
use App\Services\Legacy\LegacyRechecks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recomparaison complète avec l'ancienne application, par étapes : chaque étape relit un paquet puis lance la
 * suivante. Une étape coupée (redémarrage du serveur) est relancée par le planificateur (resumeStalled).
 */
class RunLegacyRecheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Sans nouvelles depuis ce délai : étape coupée */
    public const STALE_MINUTES = 10;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $recheckId)
    {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long')->onQueue('long');
        }
    }

    public static function resumeStalled(): int
    {
        return LegacyRecheck::query()->where('status', 'running')->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))->get()
            ->each(function (LegacyRecheck $recheck) {
                $recheck->touch();
                self::dispatch($recheck->id);
            })->count();
    }

    public function handle(LegacyRechecks $rechecks): void
    {
        $recheck = LegacyRecheck::find($this->recheckId);
        if ($recheck === null || $recheck->status !== 'running') {
            return;
        }
        $recheck->touch();

        if (! $rechecks->step($recheck)) {
            self::dispatch($recheck->id);
        }
    }
}
