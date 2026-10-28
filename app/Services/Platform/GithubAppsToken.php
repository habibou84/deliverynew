<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Jeton GitHub qui lance la fabrication des applications à leur nom (workflow branded-app.yml) : dans le fichier .env
 * du serveur (GITHUB_APPS_TOKEN, prioritaire) ou saisi par le Propriétaire dans la console. Celui de la console est
 * gardé chiffré avec la clé du serveur (APP_KEY), n'est jamais renvoyé au navigateur (seulement ses 4 derniers
 * caractères) ; la vérification auprès de GitHub note sa date d'expiration pour prévenir un mois avant.
 */
class GithubAppsToken
{
    /** Jetons personnels de GitHub : « github_pat_… » (fine-grained) ou « ghp_… » (classique) */
    public const PATTERN = '/^(github_pat_|ghp_)[A-Za-z0-9_]{20,255}$/';

    public const WARN_DAYS = 30;

    private const KEY = 'github_apps.token';

    public function token(): ?string
    {
        $env = config('services.github_apps.token');
        if (filled($env)) {
            return (string) $env;
        }
        $stored = PlatformSetting::get(self::KEY);
        if (blank($stored)) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null; // clé du serveur changée : à saisir de nouveau
        }
    }

    /** Provenance du jeton utilisé : env, console, ou null */
    public function source(): ?string
    {
        return match (true) {
            filled(config('services.github_apps.token')) => 'env',
            filled(PlatformSetting::get(self::KEY)) => 'console',
            default => null,
        };
    }

    public function save(string $token): void
    {
        PlatformSetting::put(self::KEY, Crypt::encryptString(trim($token)));
        foreach (['expires_at', 'checked_at', 'error'] as $field) {
            PlatformSetting::put("github_apps.{$field}", null);
        }
    }

    public function remove(): void
    {
        foreach (['token', 'expires_at', 'checked_at', 'error'] as $field) {
            PlatformSetting::put("github_apps.{$field}", null);
        }
    }

    /**
     * Vérifie le jeton auprès de GitHub : accès au workflow de fabrication du dépôt, et date d'expiration (en-tête
     * github-authentication-token-expiration). Le droit d'écriture ne se voit qu'au lancement d'une fabrication.
     *
     * @return string|null message d'erreur, null si le jeton marche
     */
    public function check(): ?string
    {
        $token = $this->token();
        if ($token === null) {
            return $this->remember(null, 'Aucun jeton GitHub enregistré.');
        }
        $config = config('services.github_apps');
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(15)
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->get("https://api.github.com/repos/{$config['repo']}/actions/workflows/{$config['workflow']}");
        } catch (Throwable $e) {
            return $this->remember(null, 'GitHub injoignable : '.Str::limit($e->getMessage(), 160));
        }

        $expires = null;
        $header = $response->header('github-authentication-token-expiration');
        if ($header !== '') {
            try {
                $expires = CarbonImmutable::parse($header);
            } catch (Throwable) {
                $expires = null;
            }
        }

        return $this->remember($expires, match (true) {
            $response->successful() => null,
            $response->status() === 401 => 'Jeton refusé par GitHub (faux ou expiré).',
            in_array($response->status(), [403, 404], true) => "Ce jeton n'a pas accès au workflow {$config['workflow']} du dépôt {$config['repo']} (droit Actions).",
            default => 'Réponse inattendue de GitHub (HTTP '.$response->status().').',
        });
    }

    private function remember(?CarbonImmutable $expires, ?string $error): ?string
    {
        PlatformSetting::put('github_apps.expires_at', $expires?->toIso8601String());
        PlatformSetting::put('github_apps.checked_at', now()->toIso8601String());
        PlatformSetting::put('github_apps.error', $error);

        return $error;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = PlatformSetting::get('github_apps.expires_at');

        return filled($value) ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Ce que la console voit du jeton (jamais le jeton lui-même).
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $token = $this->token();
        $expires = $this->expiresAt();

        return [
            'ready' => $token !== null,
            'source' => $this->source(),
            'last4' => $token !== null ? substr($token, -4) : null,
            'expires_at' => $expires?->toDateString(),
            'expires_soon' => $expires !== null && $expires->lte(now()->addDays(self::WARN_DAYS)),
            'expired' => $expires !== null && $expires->isPast(),
            'checked_at' => PlatformSetting::get('github_apps.checked_at'),
            'error' => PlatformSetting::get('github_apps.error'),
        ];
    }
}
