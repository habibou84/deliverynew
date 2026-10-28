<?php

namespace App\Console\Commands;

use App\Services\Accounts\AccountDeletions;
use Illuminate\Console\Command;

class EraseDeletedAccounts extends Command
{
    protected $signature = 'accounts:erase-deleted';

    protected $description = 'Efface les données personnelles des comptes dont la suppression a été demandée il y a plus de 30 jours';

    public function handle(AccountDeletions $deletions): int
    {
        $this->info($deletions->erase().' compte(s) effacé(s).');

        return self::SUCCESS;
    }
}
