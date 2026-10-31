<?php

namespace App\Services\Accounts;

use App\Enums\PlatformTemplate;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\ConsoleLoginCode;
use App\Services\Platform\PlatformWhatsApp;
use App\Services\Platform\WaveSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Code de connexion à la console : après le bon mot de passe, un super administrateur reçoit un code à 6 chiffres
 * par e-mail (WhatsApp si l'e-mail ne part pas), valable 10 minutes, 5 essais. L'appareil peut être retenu 30 jours.
 * Sans aucun moyen d'envoi, la connexion n'est pas bloquée (le seul compte de la plateforme resterait dehors) : la
 * page Santé le signale.
 */
class ConsoleTwoFactor
{
    private const CODE_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public const TRUST_DAYS = 30;

    public function __construct(private readonly WaveSettings $mail, private readonly PlatformWhatsApp $whatsapp) {}

    /** Compte concerné : les super administrateurs de la plateforme */
    public function applies(User $user): bool
    {
        return $user->isPlatformStaff();
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
     * Envoie le code. Renvoie la demande à présenter (jeton et destination masquée), ou null si aucun moyen
     * d'envoi ne marche.
     *
     * @return array{challenge: string, channel: string, to: string, expires_in: int}|null
     */
    public function start(User $user, string $device, string $ip): ?array
    {
        $code = (string) random_int(100000, 999999);
        [$channel, $to] = $this->send($user, $code, $ip);
        if ($channel === null) {
            Log::warning('Code de connexion console non envoyé : aucun e-mail ni WhatsApp disponible', ['user' => $user->id]);

            return null;
        }

        $challenge = Str::random(48);
        Cache::put(self::key($challenge), ['user_id' => $user->id, 'code_hash' => Hash::make($code), 'attempts' => 0, 'device' => $device],
            now()->addMinutes(self::CODE_MINUTES));

        return ['challenge' => $challenge, 'channel' => $channel, 'to' => $to, 'expires_in' => self::CODE_MINUTES * 60];
    }

    /**
     * Vérifie le code ; renvoie le compte, le nom de l'appareil et, s'il est retenu, son jeton (affiché une seule fois).
     *
     * @return array{user: User, device: string, trusted_device: ?string}
     */
    public function verify(string $challenge, string $code, bool $remember, ?string $ip): array
    {
        $key = self::key($challenge);
        $pending = Cache::get($key);
        if (! is_array($pending)) {
            throw ValidationException::withMessages(['code' => 'Code expiré : reconnectez-vous pour en recevoir un nouveau.']);
        }
        // Essai compté d'un seul coup dans le cache : des requêtes simultanées ne passent pas toutes la limite
        Cache::add($key.':tries', 0, now()->addMinutes(self::CODE_MINUTES));
        $tries = (int) Cache::increment($key.':tries');
        if ($tries > self::MAX_ATTEMPTS) {
            Cache::forget($key);

            throw ValidationException::withMessages(['code' => 'Trop de codes faux : reconnectez-vous pour en recevoir un nouveau.']);
        }
        if (! Hash::check(preg_replace('/\D/', '', $code), $pending['code_hash'])) {
            if ($tries >= self::MAX_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages(['code' => 'Trop de codes faux : reconnectez-vous pour en recevoir un nouveau.']);
            }

            throw ValidationException::withMessages(['code' => 'Code incorrect. Il reste '.(self::MAX_ATTEMPTS - $tries).' essai(s).']);
        }
        Cache::forget($key);
        Cache::forget($key.':tries');

        $user = User::query()->findOrFail($pending['user_id']);
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

        return ['user' => $user, 'device' => $pending['device'], 'trusted_device' => $token];
    }

    /** Oublie tous les appareils retenus (nouveau mot de passe, déconnexion de partout) */
    public function forgetDevices(User $user): void
    {
        TrustedDevice::query()->where('user_id', $user->id)->delete();
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function send(User $user, string $code, string $ip): array
    {
        $mailer = $this->mail->mailer();
        if (filled($user->email) && $mailer !== null) {
            try {
                Notification::route('mail', $user->email)->notifyNow(new ConsoleLoginCode($code, $ip, $mailer['mailer'], $mailer['from']));

                return ['mail', self::maskEmail($user->email)];
            } catch (Throwable $e) {
                Log::warning('Code de connexion console : e-mail non envoyé, essai par WhatsApp', ['error' => $e->getMessage()]);
            }
        }
        if (filled($user->phone) && $this->whatsapp->connected()) {
            try {
                $this->whatsapp->notify($user->phone, "Jibiat · Code de connexion à la console : {$code}\nValable 10 minutes. Ne le donnez à personne.", PlatformTemplate::ConsoleCode, [$code]);

                return ['whatsapp', '…'.substr((string) $user->phone, -4)];
            } catch (Throwable $e) {
                Log::warning('Code de connexion console : WhatsApp non envoyé', ['error' => $e->getMessage()]);
            }
        }

        return [null, ''];
    }

    private static function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain;
    }

    private static function key(string $challenge): string
    {
        return 'console-2fa:'.hash('sha256', $challenge);
    }
}
