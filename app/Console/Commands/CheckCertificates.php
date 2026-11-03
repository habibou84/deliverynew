<?php

namespace App\Console\Commands;

use App\Services\Platform\Certificates;
use Illuminate\Console\Command;

/**
 * Relève la date d'expiration des certificats HTTPS (plateforme et domaines personnalisés) pour la console › Santé.
 */
class CheckCertificates extends Command
{
    protected $signature = 'platform:certificates';

    protected $description = 'Relève la date d\'expiration des certificats HTTPS de la plateforme et des domaines personnalisés';

    public function handle(Certificates $certificates): int
    {
        foreach ($certificates->refresh() as $host => $result) {
            $this->line($host.' : '.($result['expires_at'] ?? 'erreur, '.$result['error']));
        }

        return self::SUCCESS;
    }
}
