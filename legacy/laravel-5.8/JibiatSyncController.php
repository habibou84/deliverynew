<?php

/*
 * Copie des commandes vers Jibiat — module pour l'ancienne application (Laravel 5.8, PHP 7.1 ou plus).
 *
 * Jibiat vient lire ici, en lecture seule, les commandes (avec leurs articles, notes, historique, frais, relances
 * et paiement) et les produits. D'abord les 12 derniers mois et toutes les commandes en cours, puis toutes les
 * minutes celles créées ou modifiées, par les e-commerçants comme par l'administration. Rien n'est modifié ni
 * supprimé dans cette base.
 *
 * Installation :
 *   1. Copier ce fichier dans app/Http/Controllers/JibiatSyncController.php
 *   2. Ajouter à la fin de routes/api.php :
 *
 *        Route::get('jibiat-sync/{resource}', 'JibiatSyncController@index')
 *            ->where('resource', 'summary|commands|products');
 *
 *   3. Dans le fichier .env, ajouter la clé de lecture créée dans Jibiat (Réglages › Intégrations › Reprise de
 *      l'ancienne application) :
 *
 *        JIBIAT_SYNC_KEY=jbr_…
 *
 *      puis lancer « php artisan config:clear ». Si la configuration est mise en cache (config:cache), ajouter aussi
 *      dans config/services.php : 'jibiat_sync' => ['key' => env('JIBIAT_SYNC_KEY')],
 *   4. Conseillé (recherche rapide des changements, ne modifie aucune donnée) :
 *
 *        ALTER TABLE commands ADD INDEX commands_updated_at (updated_at);
 *
 * Sans clé dans le .env, ces adresses répondent « 404 » : rien n'est lisible.
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JibiatSyncController extends Controller
{
    /** Commandes encore en cours : reprises quelle que soit leur date */
    const ACTIVE = ['en cours', 'encours', 'récupéré', 'recupere', 'récupere', 'en chemin'];

    const MAX = 500;

    public function index(Request $request, $resource)
    {
        $key = (string) config('services.jibiat_sync.key', env('JIBIAT_SYNC_KEY'));
        if ($key === '' || strpos($key, 'jbr_') !== 0) {
            abort(404);
        }
        if (! hash_equals($key, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Clé de lecture invalide.'], 401);
        }

        $limit = max(1, min(self::MAX, (int) $request->input('limit', 200)));
        $serverTime = $this->now();

        if ($resource === 'summary') {
            return response()->json(['server_time' => $serverTime, 'data' => $this->summary((string) $request->input('from'))]);
        }

        if ($resource === 'products') {
            $rows = DB::table('products')->where('id', '>', (int) $request->input('after_id'))->orderBy('id')->limit($limit)->get();
            $variants = Schema::hasTable('product_variants') && count($rows)
                ? DB::table('product_variants')->whereIn('product_id', $rows->pluck('id')->all())->get()->groupBy('product_id') : collect();
            $clients = $this->keyed(DB::table('clients')->whereIn('id', $rows->pluck('client_id')->all() ?: [0])->get(['id', 'nom', 'phone']));
            $data = $this->arrays($rows);
            foreach ($data as $i => $product) {
                $data[$i]['variants'] = $this->arrays($variants->get($product['id'], collect()));
                $data[$i]['client'] = isset($clients[$product['client_id']]) ? $clients[$product['client_id']] : null;
            }

            return response()->json(['server_time' => $serverTime, 'data' => $data, 'next_after_id' => count($rows) === $limit ? $rows->last()->id : null]);
        }

        // Reprise initiale des plus récentes aux plus anciennes (jour de livraison, sinon jour de création)
        if ($request->input('order') === 'recent' && ! $request->filled('ids') && ! $request->filled('since')) {
            return $this->recent($request, $limit, $serverTime);
        }

        // Commandes : par numéros (vérification), changements depuis une heure donnée, ou reprise initiale
        $afterId = (int) $request->input('after_id');
        if ($request->filled('ids')) {
            $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $request->input('ids')))), 0, self::MAX);
        } elseif ($request->filled('since')) {
            $ids = $this->changedSince((string) $request->input('since'), $afterId, $limit);
        } else {
            $ids = $this->scope((string) $request->input('from'))->where('id', '>', $afterId)
                ->orderBy('id')->limit($limit)->pluck('id')->all();
        }

        return response()->json([
            'server_time' => $serverTime,
            'data' => $this->commands($ids),
            'next_after_id' => ! $request->filled('ids') && count($ids) === $limit ? max($ids) : null,
        ]);
    }

    /**
     * Reprise par jour de livraison décroissant, puis numéro décroissant. Point de reprise « after » = « jour|numéro »
     * de la dernière commande reçue ; « next_after » vaut null à la fin.
     */
    private function recent(Request $request, $limit, $serverTime)
    {
        $day = 'COALESCE(delivery_date, DATE(created_at))';
        $query = $this->scope((string) $request->input('from'));
        $after = explode('|', (string) $request->input('after'), 2);
        if (count($after) === 2 && $after[1] !== '') {
            list($afterDay, $afterId) = [$after[0], (int) $after[1]];
            $query->where(function ($q) use ($day, $afterDay, $afterId) {
                $q->where(DB::raw($day), '<', $afterDay)
                    ->orWhere(function ($q) use ($day, $afterDay, $afterId) {
                        $q->where(DB::raw($day), '=', $afterDay)->where('id', '<', $afterId);
                    });
            });
        }
        $rows = $query->orderBy(DB::raw($day), 'desc')->orderBy('id', 'desc')->limit($limit)
            ->get(['id', DB::raw($day.' AS jibiat_day')]);
        $last = $rows->last();

        return response()->json([
            'server_time' => $serverTime,
            'order' => 'recent',
            'data' => $this->commands($rows->pluck('id')->all(), true),
            'next_after' => count($rows) === $limit ? $last->jibiat_day.'|'.$last->id : null,
        ]);
    }

    /**
     * Commandes reprises : en cours (toutes dates), créées depuis $from, et toutes celles dont l'argent n'est pas
     * soldé (paiement « encours » à l'e-commerçant, ou argent encore chez le livreur).
     */
    private function scope($from)
    {
        return DB::table('commands')->where(function ($q) use ($from) {
            // En cours, récupéré, en chemin, même avec des accents mal enregistrés
            $q->whereIn(DB::raw('LOWER(TRIM(etat))'), self::ACTIVE)->orWhere('etat', 'like', 'en c%')->orWhere('etat', 'like', 'r%cup%');
            if ($from !== '') {
                $q->orWhere('created_at', '>=', $from);
            }
            if (Schema::hasTable('payments')) {
                $q->orWhereIn('id', DB::table('payments')->where(DB::raw('LOWER(TRIM(etat))'), '!=', 'termine')->select('command_id'));
            }
            $q->orWhere(function ($q) {
                $q->whereIn(DB::raw('LOWER(TRIM(etat))'), ['termine', 'terminé'])->whereNull('cashedback_at')->where('livreur_id', '>', 0);
            });
        });
    }

    /**
     * Nombre de commandes reprises par état ; soldes de l'ancienne application pour le contrôle : dû à chaque
     * e-commerçant (paiements « encours ») et argent chez chaque livreur (livrées, pas encore remises).
     */
    private function summary($from)
    {
        $scope = $this->scope($from);
        $unpaid = Schema::hasTable('payments')
            ? DB::table('payments')->where(DB::raw('LOWER(TRIM(etat))'), '!=', 'termine')
                ->select(DB::raw('client_id, SUM(montant) as total, COUNT(*) as n'))->groupBy('client_id')->get() : [];
        $cash = DB::table('commands')->whereIn(DB::raw('LOWER(TRIM(etat))'), ['termine', 'terminé'])->whereNull('cashedback_at')->where('livreur_id', '>', 0)
            ->select(DB::raw('livreur_id, SUM(GREATEST(COALESCE(montant, 0) - COALESCE(remise, 0), 0) + COALESCE(livraison, 0)) as total, COUNT(*) as n'))
            ->groupBy('livreur_id')->get();

        return [
            'commands' => (clone $scope)->count(),
            'by_etat' => (clone $scope)->select(DB::raw('etat, COUNT(*) as n'))->groupBy('etat')->pluck('n', 'etat'),
            'payments' => Schema::hasTable('payments')
                ? DB::table('payments')->select(DB::raw('etat, COUNT(*) as n, SUM(montant) as total'))->groupBy('etat')->get() : [],
            'unpaid_by_client' => $unpaid,
            'cash_by_livreur' => $cash,
            'products' => Schema::hasTable('products') ? DB::table('products')->count() : 0,
        ];
    }

    /**
     * Commandes créées ou modifiées depuis $since : la commande elle-même, ou son paiement, ses notes, son historique,
     * ses frais, ses articles ou ses relances.
     */
    private function changedSince($since, $afterId, $limit)
    {
        $ids = DB::table('commands')->where(function ($q) use ($since) {
            $q->where('updated_at', '>=', $since)->orWhere('created_at', '>=', $since);
        })->pluck('id')->all();

        foreach (['payments', 'notes', 'command_events', 'frais', 'command_product', 'relances', 'd_relances'] as $table) {
            if (Schema::hasTable($table)) {
                $ids = array_merge($ids, DB::table($table)->where(function ($q) use ($since) {
                    $q->where('updated_at', '>=', $since)->orWhere('created_at', '>=', $since);
                })->pluck('command_id')->all());
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) use ($afterId) {
            return $id > $afterId;
        })));
        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    /** Commandes complètes : e-commerçant, livreur, articles, notes, historique, frais, relances, paiement */
    private function commands(array $ids, $keepOrder = false)
    {
        if (! $ids) {
            return [];
        }

        $commands = $this->arrays(DB::table('commands')->whereIn('id', $ids)->orderBy('id')->get());
        if ($keepOrder) {
            // Dans l'ordre demandé (plus récentes d'abord)
            $position = array_flip(array_map('intval', $ids));
            usort($commands, function ($a, $b) use ($position) {
                return $position[(int) $a['id']] - $position[(int) $b['id']];
            });
        }
        $clients = $this->keyed(DB::table('clients')->whereIn('id', array_column($commands, 'client_id') ?: [0])->get(['id', 'nom', 'phone']));
        $livreurs = $this->keyed(DB::table('livreurs')->whereIn('id', array_column($commands, 'livreur_id') ?: [0])->get(['id', 'nom', 'phone']));
        $items = $this->related('command_product', $ids, function ($q) {
            $q->leftJoin('products', 'products.id', '=', 'command_product.product_id')
                ->select('command_product.*', 'products.name as product_name', 'products.code as product_code');
        });
        $notes = $this->related('notes', $ids);
        $events = $this->related('command_events', $ids);
        $frais = $this->related('frais', $ids);
        $relances = $this->related('relances', $ids);
        $dRelances = $this->related('d_relances', $ids);
        $payments = $this->related('payments', $ids);

        foreach ($commands as $i => $c) {
            $id = $c['id'];
            $commands[$i]['client'] = isset($clients[$c['client_id']]) ? $clients[$c['client_id']] : null;
            $commands[$i]['livreur'] = isset($livreurs[$c['livreur_id']]) ? $livreurs[$c['livreur_id']] : null;
            $commands[$i]['items'] = isset($items[$id]) ? $items[$id] : [];
            $commands[$i]['notes'] = isset($notes[$id]) ? $notes[$id] : [];
            $commands[$i]['events'] = isset($events[$id]) ? $events[$id] : [];
            $commands[$i]['frais'] = isset($frais[$id]) ? $frais[$id] : [];
            $commands[$i]['relances'] = array_merge(isset($relances[$id]) ? $relances[$id] : [], isset($dRelances[$id]) ? $dRelances[$id] : []);
            $commands[$i]['payment'] = isset($payments[$id]) ? $payments[$id][0] : null;
        }

        return $commands;
    }

    /** Lignes d'une table liée aux commandes, regroupées par commande */
    private function related($table, array $ids, $extend = null)
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $query = DB::table($table)->whereIn($table.'.command_id', $ids)->orderBy($table.'.id');
        if ($extend) {
            $extend($query);
        }

        $grouped = [];
        foreach ($this->arrays($query->get()) as $row) {
            $grouped[$row['command_id']][] = $row;
        }

        return $grouped;
    }

    private function keyed($rows)
    {
        $keyed = [];
        foreach ($this->arrays($rows) as $row) {
            $keyed[$row['id']] = $row;
        }

        return $keyed;
    }

    /** Heure de la base : Jibiat la reprend telle quelle pour la copie suivante */
    private function now()
    {
        $row = DB::selectOne('SELECT NOW() AS now');

        return $row ? $row->now : date('Y-m-d H:i:s');
    }

    /** Lignes en tableaux, textes en UTF-8 (certaines tables anciennes sont en latin1) */
    private function arrays($rows)
    {
        return collect($rows)->map(function ($row) {
            $values = [];
            foreach ((array) $row as $column => $value) {
                $values[$column] = is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? utf8_encode($value) : $value;
            }

            return $values;
        })->values()->all();
    }
}
