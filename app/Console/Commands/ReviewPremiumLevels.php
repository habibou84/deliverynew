<?php

namespace App\Console\Commands;

use App\Services\Premium\PremiumLevels;
use Illuminate\Console\Command;

class ReviewPremiumLevels extends Command
{
    protected $signature = 'premium:levels';

    protected $description = 'Bilan du mois des niveaux Premium : chaque e-commerçant passe au niveau dont il remplit les conditions';

    public function handle(PremiumLevels $levels): int
    {
        foreach ($levels->reviewAll() as $slug => $r) {
            $this->line("{$slug} : {$r['up']} monté(s), {$r['down']} descendu(s), {$r['same']} inchangé(s), {$r['cards']} carte(s) offerte(s)");
        }

        return self::SUCCESS;
    }
}
