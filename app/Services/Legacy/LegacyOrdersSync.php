<?php

namespace App\Services\Legacy;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Jibiat va chercher les commandes dans l'ancienne application (module JibiatSyncController, lecture seule) :
 * simulation puis reprise (12 derniers mois, toutes les commandes en cours et celles dont l'argent n'est pas soldé),
 * puis toutes les minutes les commandes créées ou modifiées là-bas ; chaque nuit, les courses encore en cours sont
 * relues.
 */
class LegacyOrdersSync
{
    /** Mois de commandes terminées repris */
    public const MONTHS = 12;

    /** Recouvrement entre deux copies (horloges, écritures en cours) : sans risque, la reprise ne crée pas de doublon */
    private const OVERLAP_SECONDS = 120;

    private const PAGE = 200;

    public function __construct(
        private readonly LegacyOrdersImporter $importer,
        private readonly LegacyProductsImporter $products,
    ) {}

    public static function from(): string
    {
        return now()->subMonths(self::MONTHS)->startOfDay()->toDateTimeString();
    }

    /**
     * Simulation (rien n'est enregistré) ou reprise. $progress reçoit (traitées, total) après chaque paquet.
     *
     * @return array<string, mixed> rapport
     */
    public function run(Company $company, bool $dryRun, ?callable $progress = null): array
    {
        $summary = $this->get($company, 'summary', ['from' => self::from()]);
        $total = (int) ($summary['data']['commands'] ?? 0);
        $serverTime = (string) ($summary['server_time'] ?? '');
        $report = $this->emptyReport();
        $report['old'] = $summary['data'] ?? [];

        if (! $dryRun) {
            $report['products'] = $this->products->import($company, fn (array $query) => $this->get($company, 'products', $query));
        }

        $this->importer->for($company);
        $afterId = 0;
        $done = 0;
        do {
            $page = $this->get($company, 'commands', ['from' => self::from(), 'after_id' => $afterId, 'limit' => self::PAGE]);
            foreach ($page['data'] ?? [] as $command) {
                $this->collect($report, $command, $this->importer->import($command, $dryRun));
            }
            $done += count($page['data'] ?? []);
            $afterId = $page['next_after_id'] ?? null;
            if ($progress) {
                $progress($done, $total);
            }
        } while ($afterId !== null);

        if (! $dryRun) {
            $company->forceFill([
                'legacy_orders_imported_at' => now(),
                'legacy_sync_enabled' => true,
                // Copie en continu : les changements depuis le début de la reprise
                'legacy_sync_cursor' => $serverTime ?: now()->toDateTimeString(),
                'legacy_sync_last_error' => null,
            ])->save();
        }

        return $report;
    }

    /**
     * Copie des commandes créées ou modifiées dans l'ancienne application depuis la dernière fois.
     *
     * @return array{created: int, updated: int, unchanged: int, skipped: int, conflict: int}
     */
    public function sync(Company $company): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'conflict' => 0];
        $since = $company->legacy_sync_cursor
            ? now()->parse($company->legacy_sync_cursor)->subSeconds(self::OVERLAP_SECONDS)->toDateTimeString()
            : self::from();

        try {
            $this->importer->for($company);
            $afterId = 0;
            $serverTime = null;
            $created = [];
            do {
                $page = $this->get($company, 'commands', ['since' => $since, 'after_id' => $afterId, 'limit' => self::PAGE]);
                $serverTime ??= $page['server_time'] ?? null;
                foreach ($page['data'] ?? [] as $command) {
                    $result = $this->importer->import($command, live: true);
                    $counts[$result['action']]++;
                    if ($result['action'] === 'created') {
                        $created[] = (string) $command['id'];
                    }
                }
                $afterId = $page['next_after_id'] ?? null;
            } while ($afterId !== null);

            $this->announce($company, $created);
            $company->forceFill([
                'legacy_sync_cursor' => $serverTime ?? $company->legacy_sync_cursor,
                'legacy_sync_last_at' => now(),
                'legacy_sync_last_error' => null,
                'legacy_sync_stats' => $this->stats($company, $counts),
            ])->save();
        } catch (RuntimeException $e) {
            $company->forceFill(['legacy_sync_last_error' => Str::limit($e->getMessage(), 500), 'legacy_sync_last_at' => now()])->save();

            throw $e;
        }

        return $counts;
    }

    /** Relit les courses reprises encore en cours (une modification sans date de mise à jour n'échappe pas) */
    public function recheck(Company $company): int
    {
        $this->importer->for($company);
        $count = 0;
        Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_id')->whereNull('legacy_conflict_at')
            ->where(fn ($q) => $q->whereNotIn('status', OrderStatus::values([OrderStatus::Delivered, OrderStatus::Cancelled, OrderStatus::Returned]))
                ->orWhereIn('id', MerchantLedgerEntry::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('payout_id')->whereNotNull('order_id')->select('order_id'))
                ->orWhereHas('cashCollection', fn ($c) => $c->inCourierHands()))
            ->orderBy('id')
            ->pluck('legacy_id')
            ->chunk(self::PAGE)
            ->each(function ($ids) use ($company, &$count) {
                foreach ($this->get($company, 'commands', ['ids' => $ids->implode(',')])['data'] ?? [] as $command) {
                    $this->importer->import($command);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Contrôle des soldes : ce que l'ancienne application doit à chaque e-commerçant (paiements « encours ») et ce
     * qu'a chaque livreur (livrées, pas encore remises), face à Jibiat après la reprise.
     *
     * @return array{merchants: list<array<string, mixed>>, couriers: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function balances(Company $company, ?array $old = null): array
    {
        $old ??= $this->get($company, 'summary', ['from' => self::from()])['data'] ?? [];

        $owed = MerchantLedgerEntry::withoutGlobalScopes()->where('merchant_ledger_entries.company_id', $company->id)
            ->whereNull('payout_id')->where('type', '!=', LedgerEntryType::Payout->value)
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->whereNotNull('legacy_id'))
            ->selectRaw('merchant_id, SUM(amount) as total')->groupBy('merchant_id')->pluck('total', 'merchant_id');
        $merchants = Merchant::forCompany($company->id)->whereNotNull('legacy_id')->get(['id', 'legacy_id', 'business_name'])->keyBy('legacy_id');
        $rows = [];
        foreach ($old['unpaid_by_client'] ?? [] as $line) {
            $merchant = $merchants->get((string) $line['client_id']);
            $rows[(string) $line['client_id']] = [
                'legacy_id' => (string) $line['client_id'],
                'name' => $merchant?->business_name ?? 'Client n° '.$line['client_id'].' (non repris)',
                'old' => (int) $line['total'],
                'jibiat' => $merchant ? (int) ($owed[$merchant->id] ?? 0) : 0,
            ];
        }
        foreach ($merchants as $legacyId => $merchant) {
            if (! isset($rows[$legacyId]) && (int) ($owed[$merchant->id] ?? 0) !== 0) {
                $rows[$legacyId] = ['legacy_id' => $legacyId, 'name' => $merchant->business_name, 'old' => 0, 'jibiat' => (int) $owed[$merchant->id]];
            }
        }

        $cash = CashCollection::withoutGlobalScopes()->where('cash_collections.company_id', $company->id)->inCourierHands()
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->whereNotNull('legacy_id'))
            ->selectRaw('courier_id, SUM(amount_collected) as total')->groupBy('courier_id')->pluck('total', 'courier_id');
        $couriers = Courier::forCompany($company->id)->whereNotNull('legacy_id')->with('user:id,name')->get()->keyBy('legacy_id');
        $courierRows = [];
        foreach ($old['cash_by_livreur'] ?? [] as $line) {
            $courier = $couriers->get((string) $line['livreur_id']);
            $courierRows[] = [
                'legacy_id' => (string) $line['livreur_id'],
                'name' => $courier?->user?->name ?? 'Livreur n° '.$line['livreur_id'].' (non repris)',
                'old' => (int) $line['total'],
                'jibiat' => $courier ? (int) ($cash[$courier->id] ?? 0) : 0,
            ];
        }

        $rows = collect($rows)->map(fn ($r) => [...$r, 'gap' => $r['jibiat'] - $r['old']])->sortByDesc(fn ($r) => abs($r['gap']) * 1e9 + $r['old'])->values();
        $courierRows = collect($courierRows)->map(fn ($r) => [...$r, 'gap' => $r['jibiat'] - $r['old']])->sortByDesc(fn ($r) => abs($r['gap']) * 1e9 + $r['old'])->values();

        return [
            'merchants' => $rows->all(),
            'couriers' => $courierRows->all(),
            'totals' => [
                'merchants_old' => (int) $rows->sum('old'), 'merchants_jibiat' => (int) $rows->sum('jibiat'),
                'couriers_old' => (int) $courierRows->sum('old'), 'couriers_jibiat' => (int) $courierRows->sum('jibiat'),
                'merchants_gaps' => $rows->where('gap', '!=', 0)->count(), 'couriers_gaps' => $courierRows->where('gap', '!=', 0)->count(),
            ],
        ];
    }

    /**
     * Appel à l'ancienne application (clé de lecture).
     *
     * @return array<string, mixed>
     */
    public function get(Company $company, string $resource, array $query = []): array
    {
        if (! $company->legacy_sync_url || ! $company->legacy_sync_key) {
            throw new RuntimeException('Indiquez d\'abord l\'adresse de l\'ancienne application et créez la clé de lecture.');
        }

        try {
            $response = Http::withToken($company->legacy_sync_key)->acceptJson()->timeout(60)->connectTimeout(10)->retry(2, 1000, throw: false)
                ->get(rtrim($company->legacy_sync_url, '/').'/api/jibiat-sync/'.$resource, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('L\'ancienne application ne répond pas : vérifiez son adresse et qu\'elle est en ligne.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException('Module de copie introuvable dans l\'ancienne application : installez JibiatSyncController.php et mettez la clé dans son .env.');
        }
        if ($response->status() === 401) {
            throw new RuntimeException('Clé de lecture refusée par l\'ancienne application : recopiez la clé dans son .env (JIBIAT_SYNC_KEY), puis « php artisan config:clear ».');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Réponse inattendue de l\'ancienne application (code '.$response->status().').');
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    private function emptyReport(): array
    {
        return ['actions' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'conflict' => 0], 'by_etat' => [], 'skipped' => [], 'warnings' => [], 'unknown_zone' => 0];
    }

    private function collect(array &$report, array $command, array $result): void
    {
        $report['actions'][$result['action']]++;
        $etat = trim((string) ($command['etat'] ?? '')) ?: '—';
        $report['by_etat'][$etat] ??= ['count' => 0, 'statuses' => []];
        $report['by_etat'][$etat]['count']++;
        if ($result['status']) {
            $report['by_etat'][$etat]['statuses'][$result['status']] = ($report['by_etat'][$etat]['statuses'][$result['status']] ?? 0) + 1;
        }
        $line = ['id' => (string) ($command['id'] ?? ''), 'name' => (string) ($command['nom_client'] ?? $command['client_name'] ?? ''), 'merchant' => (string) ($command['client']['nom'] ?? '')];
        if ($result['action'] === 'skipped' && count($report['skipped']) < 500) {
            $report['skipped'][] = [...$line, 'reason' => $result['reason'] ?? ''];
        }
        foreach ($result['warnings'] as $warning) {
            if (count($report['warnings']) < 500) {
                $report['warnings'][] = [...$line, 'reason' => $warning];
            }
        }
    }

    /** Compteurs du jour pour l'écran de suivi */
    private function stats(Company $company, array $counts): array
    {
        $stats = $company->legacy_sync_stats ?? [];
        if (($stats['day'] ?? null) !== today()->toDateString()) {
            $stats = ['day' => today()->toDateString(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'conflict' => 0];
        }
        foreach (['created', 'updated', 'skipped', 'conflict'] as $key) {
            $stats[$key] += $counts[$key];
        }

        return $stats;
    }

    /**
     * Après la bascule, une commande encore saisie dans l'ancienne application est une vraie nouvelle course pour
     * Jibiat : l'équipe est prévenue (avant, l'ancienne application fait foi et rien n'est signalé).
     */
    private function announce(Company $company, array $legacyIds): void
    {
        if ($legacyIds === [] || $company->legacy_switch_at === null || $company->legacy_switch_at->isFuture()) {
            return;
        }

        $staff = User::forCompany($company->id)->where('status', 'active')->role([Role::Admin->value, Role::Dispatcher->value])->get();
        Order::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('legacy_id', $legacyIds)->with('merchant:id,business_name')->get()
            ->each(fn (Order $order) => Notification::send($staff, new OrderAlert($order, 'new_order',
                "Nouvelle course {$order->tracking_code}", ($order->merchant?->business_name ?? '').' · saisie dans l\'ancienne application')));
    }
}
