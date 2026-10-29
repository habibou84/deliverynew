<?php

namespace App\Jobs;

use App\Services\Couriers\MissionRing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sonnerie des missions d'un livreur : une seule à la fois par livreur et par tentative (lot de missions).
 */
class RingCourier implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 30;

    public function __construct(public readonly int $courierId, public readonly int $attempt) {}

    public function uniqueId(): string
    {
        return "{$this->courierId}-{$this->attempt}";
    }

    public function handle(MissionRing $ring): void
    {
        $ring->ring($this->courierId, $this->attempt);
    }
}
