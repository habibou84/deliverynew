<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Accounts\ConsoleTwoFactor;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Procédure de secours : si le code de connexion à la console n'a pu partir ni par e-mail ni par WhatsApp, la
 * personne qui a accès au serveur obtient ici un code pour la demande en cours (valable 10 minutes).
 */
class ConsoleLoginCode extends Command
{
    protected $signature = 'console:code {login : Téléphone ou e-mail du super administrateur}';

    protected $description = 'Donne un code de connexion à la console pour la demande en cours (secours si l\'envoi a échoué)';

    public function handle(ConsoleTwoFactor $twoFactor): int
    {
        $login = trim((string) $this->argument('login'));
        $phone = PhoneNumber::normalize($login);
        $user = User::query()->whereNull('company_id')
            ->where(fn ($q) => $q->where('email', $login)->when($phone, fn ($q) => $q->orWhere('phone', $phone)))
            ->first();
        if ($user === null || ! $twoFactor->applies($user)) {
            $this->error("Aucun super administrateur « {$login} ».");

            return self::FAILURE;
        }

        $code = $twoFactor->rescueCode($user);
        if ($code === null) {
            $this->error('Aucune connexion en attente : saisissez d\'abord le mot de passe sur la page de connexion.');

            return self::FAILURE;
        }

        $this->info("Code de connexion pour {$user->name} : {$code} (valable 10 minutes)");

        return self::SUCCESS;
    }
}
