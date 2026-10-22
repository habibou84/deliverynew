<?php

namespace App\Console\Commands;

use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class InvoiceCompanies extends Command
{
    protected $signature = 'billing:invoice
        {--month= : mois à facturer (AAAA-MM)}
        {--week= : semaine à facturer (AAAA-Wss, ex. 2026-W43)}';

    protected $description = 'Factures d\'abonnement : le 1er du mois, celles du mois écoulé ; le lundi, celles de la semaine écoulée (une seule par période)';

    public function handle(Billing $billing): int
    {
        $periods = array_filter([$this->option('month'), $this->option('week')]);
        if ($periods === []) {
            $today = CarbonImmutable::now(config('app.timezone'));
            if ($today->day === 1) {
                $periods[] = $today->subMonthNoOverflow()->format('Y-m');
            }
            if ($today->isMonday()) {
                $periods[] = $today->subWeek()->isoFormat('GGGG-[W]WW');
            }
        }

        foreach ($periods as $period) {
            if (! preg_match('/^\d{4}-((0[1-9]|1[0-2])|W(0[1-9]|[1-4]\d|5[0-3]))$/', $period)) {
                $this->error("Période invalide ({$period}) : utilisez AAAA-MM ou AAAA-Wss.");

                return self::FAILURE;
            }
            $created = $billing->generate($period);
            $this->info("{$created->count()} facture(s) créée(s) pour {$period}.");
        }

        return self::SUCCESS;
    }
}
