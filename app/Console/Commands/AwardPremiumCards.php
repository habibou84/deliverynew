<?php

namespace App\Console\Commands;

use App\Services\Premium\PremiumCards;
use Illuminate\Console\Command;

class AwardPremiumCards extends Command
{
    protected $signature = 'premium:award';

    protected $description = 'Relit les cartes Membre Premium chez Jibiat Marchand et offre la carte automatique aux marchands qui remplissent les conditions';

    public function handle(PremiumCards $premium): int
    {
        if (! PremiumCards::configured()) {
            $this->warn('JIBIAT_PREMIUM_SECRET n\'est pas réglé : rien à faire.');

            return self::SUCCESS;
        }
        $counts = $premium->run();
        $this->info("{$counts['offered']} carte(s) offerte(s) dans {$counts['companies']} entreprise(s), {$counts['synced']} carte(s) relue(s).");

        return self::SUCCESS;
    }
}
