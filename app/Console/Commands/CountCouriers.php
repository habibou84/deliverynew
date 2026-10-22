<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\Platform\Subscription;
use Illuminate\Console\Command;

class CountCouriers extends Command
{
    protected $signature = 'billing:couriers';

    protected $description = 'Relève le nombre de livreurs actifs du jour des entreprises dont la formule a une limite (livreurs en plus facturés)';

    public function handle(Subscription $subscription): int
    {
        Company::query()->where('status', CompanyStatus::Active->value)
            ->whereHas('plan', fn ($q) => $q->whereNotNull('max_couriers'))
            ->with('plan')->orderBy('id')
            ->each(fn (Company $company) => $subscription->recordCouriers($company));

        return self::SUCCESS;
    }
}
