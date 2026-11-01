<?php

namespace App\Console\Commands;

use App\Services\Ai\JibiatAi;
use Illuminate\Console\Command;

class ReviewJibiatAi extends Command
{
    protected $signature = 'ai:review';

    protected $description = 'Jibiat IA : fins d\'essai, arrêts demandés, années renouvelées, alertes de volume';

    public function handle(JibiatAi $ai): int
    {
        $result = $ai->review();
        $this->line("Essais terminés : {$result['trials_ended']} · arrêts : {$result['stopped']} · alertes : {$result['alerts']}");

        return self::SUCCESS;
    }
}
