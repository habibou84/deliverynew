<?php

namespace App\Jobs;

use App\Models\LegacyPurge;
use App\Services\Legacy\LegacyOrdersPurge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Nettoyage des anciennes courses, par étapes : chaque étape clôture un paquet puis lance la suivante. Une étape coupée
 * (redémarrage du serveur) est relancée par le planificateur (resumeStalled), sans rien refaire deux fois.
 */
class RunLegacyPurge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Sans nouvelles depuis ce délai : étape coupée */
    public const STALE_MINUTES = 10;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $purgeId)
    {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long')->onQueue('long');
        }
    }

    public static function resumeStalled(): int
    {
        return LegacyPurge::query()->where('status', 'running')->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))->get()
            ->each(function (LegacyPurge $purge) {
                $purge->touch();
                self::dispatch($purge->id);
            })->count();
    }

    public function handle(LegacyOrdersPurge $purges): void
    {
        $purge = LegacyPurge::find($this->purgeId);
        if ($purge === null || $purge->status !== 'running') {
            return;
        }
        $purge->touch();

        if (! $purges->step($purge)) {
            self::dispatch($purge->id);
        }
    }
}
