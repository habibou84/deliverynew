<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Envoi des e-mails de la plateforme par un compte Gmail, sans mot de passe : connexion Google (OAuth) avec la seule
 * autorisation « envoyer des e-mails » (gmail.send ; Jibiat ne peut pas lire la boîte). L'autorisation de longue
 * durée (refresh token) est chiffrée dans les réglages de la plateforme ; l'accès de courte durée est gardé une heure
 * en cache. Projet Google à créer une fois : docs/GMAIL.md.
 */
class GmailConnection
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private const SCOPES = 'openid email https://www.googleapis.com/auth/gmail.send';

    private const ACCESS_CACHE = 'platform:gmail-access-token';

    /**
     * Application Google (client OAuth) : saisie dans la console comme dans Jibiat Marchand (le même client peut
     * servir aux deux), sinon posée sur le serveur (GOOGLE_MAIL_CLIENT_ID et _SECRET). Le secret est chiffré en base.
     */
    public function clientId(): ?string
    {
        return PlatformSetting::get('gmail.client_id') ?: (config('services.google_mail.client_id') ?: null);
    }

    private function clientSecret(): ?string
    {
        $secret = PlatformSetting::get('gmail.client_secret');

        return filled($secret) ? Crypt::decryptString($secret) : (config('services.google_mail.client_secret') ?: null);
    }

    public function saveClient(?string $clientId, ?string $clientSecret): void
    {
        if ($clientId !== null) {
            PlatformSetting::put('gmail.client_id', trim($clientId) ?: null);
        }
        if (filled($clientSecret)) {
            PlatformSetting::put('gmail.client_secret', Crypt::encryptString(trim($clientSecret)));
        }
    }

    /** Application Google réglée : « Connecter Gmail » peut être proposé */
    public function available(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function connected(): bool
    {
        return $this->available() && filled(PlatformSetting::get('gmail.refresh_token'));
    }

    public function email(): ?string
    {
        return PlatformSetting::get('gmail.email') ?: null;
    }

    /**
     * @return array{available: bool, client_id: ?string, redirect_uri: string, connected: bool, email: ?string, connected_at: ?string, error: ?string}
     */
    public function state(): array
    {
        return [
            'available' => $this->available(),
            'client_id' => $this->clientId(),
            'redirect_uri' => self::redirectUri(),
            'connected' => $this->connected(),
            'email' => $this->email(),
            'connected_at' => PlatformSetting::get('gmail.connected_at') ?: null,
            'error' => PlatformSetting::get('gmail.error') ?: null,
        ];
    }

    /** Adresse de retour à déclarer dans le projet Google */
    public static function redirectUri(): string
    {
        return WavePayments::consoleUrl('/gmail/callback');
    }

    /**
     * Lien vers la page Google « Autoriser Jibiat à envoyer des e-mails », lié à ce super administrateur.
     */
    public function authorizationUrl(User $user): string
    {
        if (! $this->available()) {
            throw new RuntimeException('Réglez d\'abord l\'application Google (identifiant et code secret).');
        }
        $state = Str::random(40);
        Cache::put('gmail-oauth:'.$state, $user->id, now()->addMinutes(15));

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            // Toujours redemander l'accord : Google ne renvoie l'autorisation de longue durée qu'à ce moment-là
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ]);
    }

    /**
     * Retour de Google : le code est échangé contre l'autorisation. Renvoie l'adresse Gmail reliée.
     */
    public function complete(string $code, string $state): string
    {
        $userId = Cache::pull('gmail-oauth:'.$state);
        if ($userId === null || ! User::query()->find($userId)?->isSuperAdmin()) {
            throw new RuntimeException('Lien expiré : recommencez « Connecter Gmail » depuis la console.');
        }

        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->successful() || blank($response->json('refresh_token'))) {
            throw new RuntimeException('Google a refusé la connexion'.($response->json('error_description') ? ' : '.$response->json('error_description') : '.'));
        }
        if (! str_contains((string) $response->json('scope'), 'gmail.send')) {
            throw new RuntimeException('L\'autorisation d\'envoyer des e-mails n\'a pas été cochée : recommencez et cochez-la.');
        }

        $email = $this->emailFromIdToken((string) $response->json('id_token'));
        PlatformSetting::put('gmail.refresh_token', Crypt::encryptString((string) $response->json('refresh_token')));
        PlatformSetting::put('gmail.email', $email);
        PlatformSetting::put('gmail.connected_at', now()->toIso8601String());
        PlatformSetting::put('gmail.error', null);
        Cache::put(self::ACCESS_CACHE, Crypt::encryptString((string) $response->json('access_token')), now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 120)));

        return $email ?? '';
    }

    /**
     * Accès de courte durée pour envoyer, renouvelé avec l'autorisation de longue durée.
     */
    public function accessToken(): string
    {
        if (! $this->connected()) {
            throw new RuntimeException('Gmail n\'est pas connecté.');
        }
        if (($cached = Cache::get(self::ACCESS_CACHE)) !== null) {
            return Crypt::decryptString($cached);
        }

        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => Crypt::decryptString((string) PlatformSetting::get('gmail.refresh_token')),
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful()) {
            // Autorisation retirée dans le compte Google, mot de passe Google changé…
            if ($response->json('error') === 'invalid_grant') {
                PlatformSetting::put('gmail.refresh_token', null);
                PlatformSetting::put('gmail.error', 'Google a retiré l\'autorisation d\'envoi : reconnectez Gmail dans la console.');
            }

            throw new RuntimeException('Gmail : accès refusé par Google ('.($response->json('error') ?? $response->status()).').');
        }

        $token = (string) $response->json('access_token');
        Cache::put(self::ACCESS_CACHE, Crypt::encryptString($token), now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 120)));

        return $token;
    }

    /** Envoi refusé par Gmail : noté pour la console et la page Santé */
    public function recordError(string $error): void
    {
        PlatformSetting::put('gmail.error', mb_substr($error, 0, 250));
    }

    public function clearError(): void
    {
        if (PlatformSetting::get('gmail.error') !== null) {
            PlatformSetting::put('gmail.error', null);
        }
    }

    /** Déconnexion : l'autorisation est aussi retirée chez Google */
    public function disconnect(): void
    {
        $refresh = PlatformSetting::get('gmail.refresh_token');
        if ($refresh) {
            try {
                Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => Crypt::decryptString($refresh)]);
            } catch (Throwable) {
                // Retirée côté Jibiat quoi qu'il arrive
            }
        }
        foreach (['gmail.refresh_token', 'gmail.email', 'gmail.connected_at', 'gmail.error'] as $key) {
            PlatformSetting::put($key, null);
        }
        Cache::forget(self::ACCESS_CACHE);
    }

    /** Adresse du compte, lue dans le jeton d'identité renvoyé par Google (reçu directement de Google en HTTPS) */
    private function emailFromIdToken(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($payload) && filter_var($payload['email'] ?? null, FILTER_VALIDATE_EMAIL) ? $payload['email'] : null;
    }
}
