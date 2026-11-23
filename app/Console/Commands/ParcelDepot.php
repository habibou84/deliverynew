<?php

namespace App\Console\Commands;

use App\Services\Couriers\ParcelDrops;
use App\Services\Couriers\ParcelReminders;
use Illuminate\Console\Command;

class ParcelDepot extends Command
{
    protected $signature = 'parcels:depot';

    protected $description = 'Confirme les dépôts de colis non contrôlés depuis 24 h et rappelle aux livreurs leurs colis à rendre (19 h)';

    public function handle(ParcelDrops $drops, ParcelReminders $reminders): int
    {
        $this->info($drops->autoConfirm().' dépôt(s) confirmé(s) automatiquement, '.$reminders->evening().' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}
