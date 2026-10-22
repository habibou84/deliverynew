<?php

/*
 * Bascule vers Jibiat — module pour l'ancienne application (Laravel 5.8, PHP 7.1 ou plus, extension curl).
 *
 * À partir de la date de bascule choisie dans Jibiat, un e-commerçant ou un livreur connecté à l'ancienne application
 * est envoyé sur Jibiat, déjà connecté à son espace : pas de nouveau compte, pas de mot de passe à retaper. Avant la
 * date, rien ne change. Les comptes de l'équipe ne sont jamais envoyés. Un compte créé ici après la reprise est repris
 * par Jibiat au moment de la bascule, avec sa fiche. Rien n'est modifié ni supprimé dans l'ancienne base.
 *
 * Installation :
 *   1. Copier ce fichier dans app/Http/Middleware/JibiatSwitch.php
 *   2. Dans app/Http/Kernel.php, ajouter à la fin du groupe « web » :
 *
 *        \App\Http\Middleware\JibiatSwitch::class,
 *
 *   3. Dans le fichier .env de l'ancienne application, ajouter l'adresse de l'espace Jibiat et la clé de bascule
 *      (Jibiat › Réglages › Intégrations › Reprise de l'ancienne application › Bascule) :
 *
 *        JIBIAT_URL=https://votre-entreprise.jibiat.com
 *        JIBIAT_SWITCH_KEY=jbs_…
 *
 *      puis lancer « php artisan config:clear ». Si la configuration est mise en cache (config:cache), ajouter aussi
 *      dans config/services.php :
 *
 *        'jibiat' => ['url' => env('JIBIAT_URL'), 'switch_key' => env('JIBIAT_SWITCH_KEY')],
 *
 * La date se règle et se change dans Jibiat : l'ancienne application la redemande au plus tous les quarts d'heure.
 * Les appels de l'application mobile (réponses JSON) ne sont pas redirigés. En cas de souci (serveur Jibiat injoignable,
 * compte suspendu), l'ancienne application continue de fonctionner normalement.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class JibiatSwitch
{
    /** Types de compte (« users.usertype ») envoyés sur Jibiat, et la table de leur fiche */
    const TYPES = ['client' => 'clients', 'livreur' => 'livreurs'];

    /** Avant la date : on redemande au plus tous les quarts d'heure */
    const WAIT_SECONDS = 900;

    const WAIT_KEY = 'jibiat_switch_wait';

    public function handle($request, Closure $next)
    {
        $user = auth()->user();
        $url = rtrim((string) config('services.jibiat.url', env('JIBIAT_URL')), '/');
        $key = (string) config('services.jibiat.switch_key', env('JIBIAT_SWITCH_KEY'));

        if ($user === null || $url === '' || $key === '' || $request->expectsJson() || ! $request->isMethod('GET')) {
            return $next($request);
        }
        $type = strtolower(trim((string) (isset($user->usertype) ? $user->usertype : '')));
        if (! isset(self::TYPES[$type]) || Cache::get(self::WAIT_KEY)) {
            return $next($request);
        }

        $response = $this->post($url.'/api/legacy-switch', $key, $this->payload($user, $type));
        if ($response === null) {
            return $next($request);
        }

        if (isset($response['status']) && $response['status'] === 'scheduled') {
            $wait = self::WAIT_SECONDS;
            if (! empty($response['switch_at'])) {
                $wait = max(30, min($wait, strtotime($response['switch_at']) - time()));
            }
            Cache::put(self::WAIT_KEY, 1, $wait);

            return $next($request);
        }

        if (isset($response['status'], $response['url']) && $response['status'] === 'switch') {
            auth()->logout();
            $request->session()->invalidate();

            return redirect()->away($response['url']);
        }

        return $next($request);
    }

    /** Compte ouvert, avec sa fiche : Jibiat le reprend s'il ne l'a pas encore */
    private function payload($user, $type)
    {
        $table = self::TYPES[$type];
        $link = $type === 'client' ? 'client_id' : 'livreur_id';
        $sheet = null;

        if (Schema::hasTable($table)) {
            $query = DB::table($table);
            if (! empty($user->{$link})) {
                $sheet = $query->where('id', $user->{$link})->first();
            } elseif (Schema::hasColumn($table, 'user_id')) {
                $sheet = $query->where('user_id', $user->id)->first();
            }
        }

        $account = DB::table('users')->where('id', $user->id)->first();

        return [
            'phone' => (string) $user->phone,
            'usertype' => $type,
            'users' => [$this->values($account)],
            $table => $sheet ? [$this->values($sheet)] : [],
        ];
    }

    /** Ligne en tableau, textes en UTF-8 (certaines tables anciennes sont en latin1) */
    private function values($row)
    {
        $values = [];
        foreach ((array) $row as $column => $value) {
            $values[$column] = is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? utf8_encode($value) : $value;
        }

        return $values;
    }

    private function post($endpoint, $key, array $payload)
    {
        if (! function_exists('curl_init')) {
            return null;
        }
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer '.$key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $data = is_string($body) ? json_decode($body, true) : null;
        if (! is_array($data)) {
            Log::warning('Bascule Jibiat : serveur injoignable ou réponse illisible (code '.$status.').');

            return null;
        }
        if ($status >= 400 && isset($data['message'])) {
            Log::warning('Bascule Jibiat : '.$data['message']);
        }

        return $data;
    }
}
