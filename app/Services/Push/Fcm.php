<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (API v1) : messages « data » prioritaires vers l'application Android du livreur, qui
 * décide elle-même de sonner. Le compte de service (JSON) est un fichier du serveur, indiqué par FCM_CREDENTIALS.
 */
class Fcm
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * @param  array<string, string>  $data
     * @return bool|null true : envoyé ; false : jeton périmé (à oublier) ; null : échec passager
     */
    public function send(string $token, array $data): ?bool
    {
        $credentials = self::credentials();
        $access = $credentials ? $this->accessToken($credentials) : null;
        if ($access === null) {
            return null;
        }

        $response = Http::withToken($access)->acceptJson()->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", ['message' => [
                'token' => $token,
                'data' => array_map('strval', $data),
                'android' => ['priority' => 'high', 'ttl' => '120s'],
            ]]);

        if ($response->successful()) {
            return true;
        }
        if (in_array($response->status(), [400, 404], true) && str_contains($response->body(), 'UNREGISTERED')) {
            return false;
        }
        Log::warning('FCM : envoi refusé', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

        return null;
    }

    /** @param  array{client_email: string, private_key: string, project_id: string}  $credentials */
    private function accessToken(array $credentials): ?string
    {
        return Cache::remember('fcm:access:'.md5($credentials['client_email']), now()->addMinutes(50), function () use ($credentials) {
            $now = time();
            $segments = [
                self::base64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::base64(json_encode(['iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600])),
            ];
            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                return null;
            }
            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', [...$segments, self::base64($signature)]),
            ]);

            return $response->json('access_token');
        });
    }

    /** @return array{client_email: string, private_key: string, project_id: string}|null */
    private static function credentials(): ?array
    {
        $path = config('services.fcm.credentials');
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) && isset($json['client_email'], $json['private_key'], $json['project_id']) ? $json : null;
    }

    private static function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
