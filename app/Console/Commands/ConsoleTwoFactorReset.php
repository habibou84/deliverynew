<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Accounts\ConsoleTwoFactor;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Procédure de secours : un super administrateur a perdu son application d'authentification et ses codes de secours.
 * La personne qui a accès au serveur retire l'application ; à la prochaine connexion, il en configure une nouvelle.
 */
class ConsoleTwoFactorReset extends Command
{
    protected $signature = 'console:2fa-reset {login : Téléphone ou e-mail du super administrateur} {--force : Sans confirmation}';

    protected $description = 'Retire l\'application d\'authentification d\'un compte de la console (téléphone perdu)';

    public function handle(ConsoleTwoFactor $twoFactor): int
    {
        $login = trim((string) $this->argument('login'));
        $phone = PhoneNumber::normalize($login);
        $user = User::query()->whereNull('company_id')
            ->where(fn ($q) => $q->where('email', mb_strtolower($login))->when($phone, fn ($q) => $q->orWhere('phone', $phone)))
            ->first();
        if ($user === null || ! $twoFactor->applies($user)) {
            $this->error("Aucun compte de la console « {$login} ».");

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Retirer l'application d'authentification de {$user->name} ? Il en configurera une nouvelle à sa prochaine connexion.")) {
            return self::FAILURE;
        }

        $twoFactor->disable($user);
        $this->info("Application retirée pour {$user->name} : une nouvelle sera configurée à la prochaine connexion (mot de passe puis QR code).");

        return self::SUCCESS;
    }
}
