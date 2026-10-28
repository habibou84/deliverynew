<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Legacy\LegacyOrdersSync;
use Illuminate\Console\Command;
use Throwable;

class SyncLegacyOrders extends Command
{
    protected $signature = 'legacy:sync {--recheck : Relit toutes les courses reprises encore en cours (chaque nuit)}';

    protected $description = 'Copie dans Jibiat les commandes créées ou modifiées dans l\'ancienne application';

    public function handle(LegacyOrdersSync $sync): int
    {
        Company::query()->where('legacy_sync_enabled', true)->whereNotNull('legacy_orders_imported_at')->whereNotNull('legacy_sync_url')
            ->each(function (Company $company) use ($sync) {
                try {
                    if ($this->option('recheck')) {
                        $this->line("{$company->slug} : ".$sync->recheck($company).' course(s) relue(s)');

                        return;
                    }
                    $counts = $sync->sync($company);
                    $this->line("{$company->slug} : {$counts['created']} nouvelle(s), {$counts['updated']} mise(s) à jour");
                } catch (Throwable $e) {
                    // Ancienne application injoignable : noté sur l'écran de suivi, nouvel essai à la minute suivante
                    $this->warn("{$company->slug} : ".$e->getMessage());
                }
            });

        return self::SUCCESS;
    }
}
