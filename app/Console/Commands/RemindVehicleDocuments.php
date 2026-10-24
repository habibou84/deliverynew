<?php

namespace App\Console\Commands;

use App\Services\Vehicles\VehicleFleet;
use Illuminate\Console\Command;

class RemindVehicleDocuments extends Command
{
    protected $signature = 'vehicles:documents';

    protected $description = 'Prévient les responsables des papiers des véhicules et des permis qui arrivent à leur fin (15 jours avant, puis le jour même)';

    public function handle(VehicleFleet $fleet): int
    {
        $this->info($fleet->remind().' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}
