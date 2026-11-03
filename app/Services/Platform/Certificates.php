<?php

namespace App\Services\Platform;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Certificats HTTPS de la plateforme (adresse principale, console) et des domaines personnalisés des entreprises :
 * date d'expiration relevée chaque nuit (platform:certificates), alerte avant qu'un certificat n'expire.
 */
class Certificates
{
    public const CACHE = 'platform:certificates';

    /** En dessous : à surveiller (le renouvellement automatique a lieu 30 jours avant) */
    public const WARNING_DAYS = 14;

    /** En dessous : panne imminente */
    public const ERROR_DAYS = 7;

    /** @return list<string> */
    public function hosts(): array
    {
        $hosts = [parse_url((string) config('app.url'), PHP_URL_HOST)];
        if ($domain = config('platform.domain')) {
            $hosts[] = $domain;
            $hosts[] = config('platform.admin_subdomain').'.'.$domain;
        }
        $custom = Company::query()->withoutGlobalScopes()->where('custom_domain_status', 'active')->whereNotNull('custom_domain')->pluck('custom_domain');

        return array_values(array_unique(array_filter([...$hosts, ...$custom->all()], fn ($h) => is_string($h) && $h !== '' && $h !== 'localhost')));
    }

    /**
     * Relève chaque certificat et garde le résultat pour la console.
     *
     * @return array<string, array{expires_at: ?string, error: ?string}>
     */
    public function refresh(): array
    {
        $results = [];
        foreach ($this->hosts() as $host) {
            try {
                $results[$host] = ['expires_at' => $this->expiresAt($host)->toIso8601String(), 'error' => null];
            } catch (Throwable $e) {
                $results[$host] = ['expires_at' => null, 'error' => mb_substr($e->getMessage(), 0, 120)];
            }
        }
        Cache::forever(self::CACHE, ['checked_at' => now()->toIso8601String(), 'hosts' => $results]);

        return $results;
    }

    /** Date d'expiration du certificat présenté par le serveur sur le port 443 */
    public function expiresAt(string $host): CarbonImmutable
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host, 'verify_peer' => false, 'verify_peer_name' => false]]);
        $client = @stream_socket_client('ssl://'.$host.':443', $code, $message, 10, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            throw new \RuntimeException('connexion impossible ('.($message ?: $code).')');
        }
        $cert = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (! $info || ! isset($info['validTo_time_t'])) {
            throw new \RuntimeException('certificat illisible');
        }

        return CarbonImmutable::createFromTimestamp($info['validTo_time_t']);
    }

    /** @return array{0: string, 1: string} */
    public function check(): array
    {
        $data = Cache::get(self::CACHE);
        if (! is_array($data) || ! isset($data['hosts'])) {
            return app()->isProduction()
                ? ['warning', 'Pas encore vérifiés : la vérification a lieu chaque nuit.']
                : ['ok', 'Vérifiés chaque nuit en production.'];
        }
        $errors = $warnings = [];
        $soonest = null;
        foreach ($data['hosts'] as $host => $result) {
            if ($result['expires_at'] === null) {
                $warnings[] = $host.' : '.$result['error'];

                continue;
            }
            $days = (int) floor(now()->diffInHours(CarbonImmutable::parse($result['expires_at']), false) / 24);
            $soonest = $soonest === null ? $days : min($soonest, $days);
            if ($days < self::ERROR_DAYS) {
                $errors[] = $host.($days < 0 ? ' : expiré' : ' : expire dans '.$days.' jour(s)');
            } elseif ($days < self::WARNING_DAYS) {
                $warnings[] = $host.' : expire dans '.$days.' jours';
            }
        }
        $renew = ' Le renouvellement automatique (Let\'s Encrypt) ne s\'est pas fait : certbot renew sur le serveur.';
        if ($errors !== []) {
            return ['error', implode(' ; ', [...$errors, ...$warnings]).'.'.$renew];
        }
        if ($warnings !== []) {
            return ['warning', implode(' ; ', $warnings).'.'];
        }

        return ['ok', count($data['hosts']).' adresse(s) vérifiée(s)'.($soonest !== null ? ', la plus proche expire dans '.$soonest.' jours.' : '.')];
    }
}
