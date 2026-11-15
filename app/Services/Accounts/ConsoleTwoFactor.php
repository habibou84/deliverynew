<?php

namespace App\Services\Accounts;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connexion à la console en deux étapes : après le bon mot de passe, un super administrateur saisit le code à 6
 * chiffres de son application d'authentification (Google Authenticator, Microsoft Authenticator, Authy…), ou un de
 * ses codes de secours. 5 essais par demande, demande valable 10 minutes, appareil retenu 30 jours si demandé.
 *
 * Première connexion sans application configurée : la clé est présentée (QR code) et n'est enregistrée qu'une fois
 * le premier code juste saisi ; les codes de secours s'affichent alors une seule fois. Application perdue et plus de
 * code de secours : la personne qui a accès au serveur retire l'application avec `php artisan console:2fa-reset`.
 */
class ConsoleTwoFactor
{
    private const CHALLENGE_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public const TRUST_DAYS = 30;

    public const RECOVERY_CODES = 8;

    public const ISSUER = 'Jibiat Console';

    /** Compte concerné : les super administrateurs de la plateforme */
    public function applies(User $user): bool
    {
        return $user->isPlatformStaff();
    }

    /** Application d'authentification configurée */
    public function enabled(User $user): bool
    {
        return filled($user->two_factor_secret) && $user->two_factor_confirmed_at !== null;
    }

    /** Appareil retenu (jeton gardé par le navigateur) encore valable pour ce compte */
    public function trusted(User $user, ?string $token): bool
    {
        if (blank($token)) {
            return false;
        }
        $device = TrustedDevice::query()->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())->first();
        $device?->forceFill(['last_used_at' => now()])->save();

        return $device !== null;
    }

    /**
     * Demande de deuxième étape à présenter : « totp » (code de l'application) ou « setup » (première configuration :
     * clé et lien otpauth:// pour le QR code).
     *
     * @return array<string, mixed>
     */
    public function start(User $user, string $device): array
    {
        $challenge = Str::random(48);
        $pending = ['user_id' => $user->id, 'device' => $device];
        $response = ['challenge' => $challenge, 'method' => 'totp', 'expires_in' => self::CHALLENGE_MINUTES * 60];

        if (! $this->enabled($user)) {
            $secret = Totp::secret();
            $pending['secret'] = Crypt::encryptString($secret);
            $response = [...$response, 'method' => 'setup', ...$this->describeSecret($user, $secret)];
        }
        Cache::put(self::key($challenge), $pending, now()->addMinutes(self::CHALLENGE_MINUTES));

        return $response;
    }

    /**
     * Vérifie le code ; renvoie le compte, le nom de l'appareil, le jeton de l'appareil retenu (affiché une seule
     * fois) et, à la première configuration, les codes de secours (affichés une seule fois).
     *
     * @return array{user: User, device: string, trusted_device: ?string, recovery_codes: ?list<string>, recovery_used: bool}
     */
    public function verify(string $challenge, string $code, bool $remember, ?string $ip): array
    {
        $key = self::key($challenge);
        $pending = Cache::get($key);
        if (! is_array($pending)) {
            throw ValidationException::withMessages(['code' => 'Demande expirée : reconnectez-vous avec votre mot de passe.']);
        }
        // Essai compté d'un seul coup dans le cache : des requêtes simultanées ne passent pas toutes la limite
        Cache::add($key.':tries', 0, now()->addMinutes(self::CHALLENGE_MINUTES));
        $tries = (int) Cache::increment($key.':tries');
        if ($tries > self::MAX_ATTEMPTS) {
            Cache::forget($key);

            throw ValidationException::withMessages(['code' => 'Trop de codes faux : reconnectez-vous avec votre mot de passe.']);
        }

        $user = User::query()->findOrFail($pending['user_id']);
        $recoveryCodes = null;
        $recoveryUsed = false;
        if (isset($pending['secret'])) {
            // Première configuration : la clé n'est enregistrée qu'avec un premier code juste
            $secret = Crypt::decryptString($pending['secret']);
            $step = Totp::match($secret, $code);
            if ($step !== null && ! $this->enabled($user->fresh())) {
                $recoveryCodes = $this->enable($user, $secret, $step);
            }
            $ok = $recoveryCodes !== null;
        } else {
            $ok = $this->checkTotp($user, $code) || ($recoveryUsed = $this->useRecoveryCode($user, $code));
        }

        if (! $ok) {
            if ($tries >= self::MAX_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages(['code' => 'Trop de codes faux : reconnectez-vous avec votre mot de passe.']);
            }

            throw ValidationException::withMessages(['code' => 'Code incorrect. Il reste '.(self::MAX_ATTEMPTS - $tries).' essai(s).']);
        }
        Cache::forget($key);
        Cache::forget($key.':tries');

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['code' => 'Compte suspendu.']);
        }

        $token = null;
        if ($remember) {
            $token = 'td_'.Str::random(48);
            TrustedDevice::create([
                'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'label' => Str::limit($pending['device'], 110, ''),
                'ip' => $ip, 'expires_at' => now()->addDays(self::TRUST_DAYS),
            ]);
        }

        return ['user' => $user->fresh(), 'device' => $pending['device'], 'trusted_device' => $token, 'recovery_codes' => $recoveryCodes, 'recovery_used' => $recoveryUsed];
    }

    /**
     * Page « Mon compte » : nouvelle clé à scanner pour changer d'application. L'ancienne reste valable tant que le
     * premier code de la nouvelle n'a pas été saisi.
     *
     * @return array{secret: string, uri: string, account: string}
     */
    public function beginSetup(User $user): array
    {
        $secret = Totp::secret();
        Cache::put(self::setupKey($user), Crypt::encryptString($secret), now()->addMinutes(self::CHALLENGE_MINUTES));

        return $this->describeSecret($user, $secret);
    }

    /**
     * Page « Mon compte » : premier code de la nouvelle application ; renvoie les nouveaux codes de secours.
     *
     * @return list<string>
     */
    public function confirmSetup(User $user, string $code): array
    {
        $encrypted = Cache::get(self::setupKey($user));
        if (! is_string($encrypted)) {
            throw ValidationException::withMessages(['code' => 'Clé expirée : recommencez la configuration.']);
        }
        $secret = Crypt::decryptString($encrypted);
        $step = Totp::match($secret, $code);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'Code incorrect : vérifiez l\'heure du téléphone et saisissez le code affiché.']);
        }
        Cache::forget(self::setupKey($user));

        return $this->enable($user, $secret, $step);
    }

    /**
     * Nouveaux codes de secours (les anciens ne marchent plus).
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        [$plain, $hashes] = self::newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();

        return $plain;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * Procédure de secours (commande sur le serveur) : retire l'application et oublie les appareils retenus. À la
     * prochaine connexion, une nouvelle application est configurée.
     */
    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        $this->forgetDevices($user);
        Log::notice('Application d\'authentification de la console retirée par la commande de secours', ['user' => $user->id]);
    }

    /** Oublie tous les appareils retenus (nouveau mot de passe, déconnexion de partout) */
    public function forgetDevices(User $user): void
    {
        TrustedDevice::query()->where('user_id', $user->id)->delete();
    }

    /** @return list<string> */
    private function enable(User $user, string $secret, int $step): array
    {
        [$plain, $hashes] = self::newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret, 'two_factor_recovery_codes' => $hashes,
            'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step,
        ])->save();

        return $plain;
    }

    /** Code de l'application ; chaque code ne sert qu'une fois (pas de 30 s retenu, mis à jour d'un seul coup) */
    private function checkTotp(User $user, string $code): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }
        $step = Totp::match((string) $user->two_factor_secret, $code);
        if ($step === null) {
            return false;
        }

        return User::query()->whereKey($user->id)
            ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]) === 1;
    }

    /** Code de secours (10 caractères, tiret facultatif), à usage unique */
    private function useRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($normalized) !== 10) {
            return false;
        }
        $hash = hash('sha256', $normalized);
        $codes = $user->two_factor_recovery_codes ?? [];
        $left = array_values(array_filter($codes, fn ($h) => ! hash_equals($h, $hash)));
        if (count($left) === count($codes)) {
            return false;
        }
        $user->forceFill(['two_factor_recovery_codes' => $left])->save();
        Log::notice('Code de secours utilisé pour la connexion à la console', ['user' => $user->id, 'left' => count($left)]);

        return true;
    }

    /** @return array{secret: string, uri: string, account: string} */
    private function describeSecret(User $user, string $secret): array
    {
        $account = $user->email ?: (string) $user->phone;

        return ['secret' => trim(chunk_split($secret, 4, ' ')), 'uri' => Totp::uri($secret, $account, self::ISSUER), 'account' => $account];
    }

    /** @return array{0: list<string>, 1: list<string>} codes lisibles, empreintes */
    private static function newRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return [$plain, array_map(fn ($c) => hash('sha256', str_replace('-', '', $c)), $plain)];
    }

    private static function setupKey(User $user): string
    {
        return 'console-2fa-setup:'.$user->id;
    }

    private static function key(string $challenge): string
    {
        return 'console-2fa:'.hash('sha256', $challenge);
    }
}
