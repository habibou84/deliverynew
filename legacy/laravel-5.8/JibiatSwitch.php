<?php

/*
 * Bascule vers Jibiat — module pour l'ancienne application (Laravel 5.8, PHP 7.1 ou plus, extension curl). Version 2.
 *
 * Avant la date de bascule (réglé dans Jibiat › Réglages › Reprise › Bascule), un bandeau peut s'afficher en bas des
 * pages de l'ancienne application :
 *   - pour l'équipe : « Ouvrir Jibiat », pour travailler sur les deux applications en même temps ;
 *   - pour les e-commerçants et les livreurs : « Ouvrir la nouvelle application », qui les connecte déjà à leur
 *     espace Jibiat dans un nouvel onglet, sans les sortir de l'ancienne application.
 * Chaque public s'active séparément dans Jibiat ; le bandeau se ferme d'un clic (il revient à la visite suivante).
 *
 * À partir de la date de bascule, un e-commerçant ou un livreur connecté à l'ancienne application est envoyé sur
 * Jibiat, déjà connecté à son espace : pas de nouveau compte, pas de mot de passe à retaper. Les comptes de l'équipe
 * ne sont jamais envoyés (ils gardent le bandeau). Un compte créé ici après la reprise est repris par Jibiat au moment
 * de la bascule, avec sa fiche. Rien n'est modifié ni supprimé dans l'ancienne base.
 *
 * Installation (ou mise à jour : remplacer simplement le fichier) :
 *   1. Copier ce fichier dans app/Http/Middleware/JibiatSwitch.php
 *   2. Dans app/Http/Kernel.php, ajouter à la fin du groupe « web » :
 *
 *        \App\Http\Middleware\JibiatSwitch::class,
 *
 *   3. Dans le fichier .env de l'ancienne application, ajouter l'adresse de l'espace Jibiat et la clé de bascule
 *      (Jibiat › Réglages › Reprise de l'ancienne application › Brancher) :
 *
 *        JIBIAT_URL=https://votre-entreprise.jibiat.com
 *        JIBIAT_SWITCH_KEY=jbs_…
 *
 *      puis lancer « php artisan config:clear ». Si la configuration est mise en cache (config:cache), ajouter aussi
 *      dans config/services.php :
 *
 *        'jibiat' => ['url' => env('JIBIAT_URL'), 'switch_key' => env('JIBIAT_SWITCH_KEY')],
 *
 * Les réglages du bandeau et la date sont relus au plus toutes les 5 minutes et tous les quarts d'heure. Les appels de
 * l'application mobile (réponses JSON) ne sont ni redirigés ni modifiés. En cas de souci (serveur Jibiat injoignable,
 * compte suspendu), l'ancienne application continue de fonctionner normalement, sans bandeau.
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

    const VERSION = 2;

    /** Réglages du bandeau, relus au plus toutes les 5 minutes */
    const CONFIG_SECONDS = 300;

    const CONFIG_KEY = 'jibiat_switch_config';

    /** Paramètre ajouté à l'adresse par le bouton du bandeau */
    const OPEN_PARAM = 'jibiat_ouvrir';

    public function handle($request, Closure $next)
    {
        $user = auth()->user();
        $url = rtrim((string) config('services.jibiat.url', env('JIBIAT_URL')), '/');
        $key = (string) config('services.jibiat.switch_key', env('JIBIAT_SWITCH_KEY'));

        if ($user === null || $url === '' || $key === '' || $request->expectsJson() || ! $request->isMethod('GET')) {
            return $next($request);
        }
        $type = strtolower(trim((string) (isset($user->usertype) ? $user->usertype : '')));
        $endpoint = $url.'/api/legacy-switch';

        if (isset(self::TYPES[$type])) {
            // Bouton du bandeau : ouvre l'espace Jibiat déjà connecté (nouvel onglet), sans quitter l'ancienne application
            if ($request->query(self::OPEN_PARAM)) {
                $response = $this->post($endpoint, $key, array_merge($this->payload($user, $type), ['mode' => 'open']));
                if (isset($response['status'], $response['url']) && $response['status'] === 'switch') {
                    return redirect()->away($response['url']);
                }

                return redirect()->to($request->url());
            }

            // Bascule commencée : envoyé sur Jibiat, déjà connecté
            if (! Cache::get(self::WAIT_KEY)) {
                $response = $this->post($endpoint, $key, $this->payload($user, $type));
                if (isset($response['status']) && $response['status'] === 'scheduled') {
                    $wait = self::WAIT_SECONDS;
                    if (! empty($response['switch_at'])) {
                        $wait = max(30, min($wait, strtotime($response['switch_at']) - time()));
                    }
                    Cache::put(self::WAIT_KEY, 1, $wait);
                } elseif (isset($response['status'], $response['url']) && $response['status'] === 'switch') {
                    auth()->logout();
                    $request->session()->invalidate();

                    return redirect()->away($response['url']);
                }
            }
        }

        $page = $next($request);

        try {
            return $this->withBanner($request, $page, $type, $this->config($endpoint, $key), $url);
        } catch (\Throwable $e) {
            return $page;
        }
    }

    /** Réglages du bandeau (à qui, quel message), gardés quelques minutes ; vides si Jibiat ne répond pas */
    private function config($endpoint, $key)
    {
        $config = Cache::get(self::CONFIG_KEY);
        if (is_array($config)) {
            return $config;
        }
        $response = $this->post($endpoint, $key, ['mode' => 'config', 'module_version' => self::VERSION]);
        $config = isset($response['status'], $response['banner']) && $response['status'] === 'config' ? $response : [];
        Cache::put(self::CONFIG_KEY, $config, self::CONFIG_SECONDS);

        return $config;
    }

    /** Bandeau en bas des pages HTML, selon le public du compte ouvert */
    private function withBanner($request, $page, $type, array $config, $url)
    {
        if (empty($config['banner']) || ! $page instanceof \Illuminate\Http\Response || $page->getStatusCode() !== 200) {
            return $page;
        }
        $contentType = (string) $page->headers->get('Content-Type');
        if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
            return $page;
        }
        $html = $page->getContent();
        $at = is_string($html) ? strripos($html, '</body>') : false;
        if ($at === false) {
            return $page;
        }

        $banner = $config['banner'];
        $date = ! empty($config['switch_at']) && empty($config['started'])
            ? ' À partir du '.date('d/m/Y à H:i', strtotime($config['switch_at'])).', vous serez envoyé directement sur la nouvelle application.'
            : '';
        if (isset(self::TYPES[$type])) {
            $audience = $type === 'livreur' ? 'couriers' : 'merchants';
            if (empty($banner[$audience])) {
                return $page;
            }
            $default = $type === 'livreur'
                ? 'Votre nouvelle application livreur est prête : missions, encaissements et gains au même endroit.'
                : 'Découvrez notre nouvelle application : vos courses, votre argent et vos notifications au même endroit.';
            $text = (! empty($banner['message']) ? $banner['message'] : $default).' Vous y êtes connecté directement, sans mot de passe.'.$date;
            $glue = strpos($request->fullUrl(), '?') === false ? '?' : '&';
            $link = $request->fullUrl().$glue.self::OPEN_PARAM.'=1';
            $button = 'Ouvrir la nouvelle application';
        } else {
            if (empty($banner['team'])) {
                return $page;
            }
            $text = 'La nouvelle application Jibiat est ouverte : vous pouvez l\'utiliser en même temps que celle-ci.'.$date;
            $link = ! empty($config['url']) ? $config['url'] : $url.'/admin';
            $button = 'Ouvrir Jibiat';
        }

        $page->setContent(substr($html, 0, $at).$this->bannerHtml($text, $link, $button).substr($html, $at));

        return $page;
    }

    private function bannerHtml($text, $link, $button)
    {
        $e = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        return '<div id="jibiat-banner" role="region" aria-label="Nouvelle application" style="position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483000;'
            .'max-width:720px;margin:0 auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;border-radius:14px;'
            .'background:#065f46;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.25);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif">'
            .'<span style="font-size:20px" aria-hidden="true">🚀</span>'
            .'<span style="flex:1 1 220px">'.$e($text).'</span>'
            .'<a href="'.$e($link).'" target="_blank" rel="noopener" style="background:#fff;color:#065f46;font-weight:700;padding:8px 14px;border-radius:10px;text-decoration:none;white-space:nowrap">'.$e($button).'</a>'
            .'<button type="button" aria-label="Fermer" onclick="try{sessionStorage.setItem(\'jibiatBanner\',\'1\')}catch(e){}this.parentNode.remove()" '
            .'style="background:transparent;border:0;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:0 4px">×</button>'
            .'</div><script>try{if(sessionStorage.getItem(\'jibiatBanner\')){var b=document.getElementById(\'jibiat-banner\');b&&b.remove()}}catch(e){}</script>';
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
            'module_version' => self::VERSION,
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
