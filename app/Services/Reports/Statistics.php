<?php

namespace App\Services\Reports;

use App\Enums\LedgerEntryType;
use App\Enums\MerchantStatus;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierRemittance;
use App\Models\Live;
use App\Models\LiveCharge;
use App\Models\LiveOrder;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderExpense;
use App\Models\Zone;
use App\Services\Finance\CourierCash;
use App\Services\Finance\MerchantPayouts;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques de l'entreprise sur une période (dates dans son fuseau horaire) :
 * vue d'ensemble, qualité des livraisons, livreurs, e-commerçants, zones, finances.
 *
 * Chaque événement compte à sa date : une course est « créée » le jour de sa création,
 * « livrée » le jour de sa livraison, etc. Les calculs se font en PHP sur les seules
 * colonnes utiles, pour grouper par jour et par heure dans le fuseau de l'entreprise
 * quelle que soit la base de données.
 */
class Statistics
{
    // Courses retenues pour le calcul (au-delà, la période est trop longue)
    public const MAX_DAYS = 366;

    // E-commerçant « inactif » : plus de course depuis ce nombre de jours
    public const INACTIVE_DAYS = 30;

    // Échecs de livraison : la course quitte « En chemin » avec un motif
    private const FAILURE_STATUSES = [OrderStatus::DeliveryFailed->value, OrderStatus::Rescheduled->value];

    private Company $company;

    private string $tz;

    // Statistiques d'un seul e-commerçant (son application) ; null = toute l'entreprise
    private ?int $merchantId = null;

    // Filtres du back-office : zones de livraison (commune et ses quartiers), livreur
    private ?array $zoneIds = null;

    private ?int $courierId = null;

    public function for(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;
        $clone->tz = $company->timezone ?: config('app.timezone');
        $clone->merchantId = null;
        $clone->zoneIds = null;
        $clone->courierId = null;

        return $clone;
    }

    /**
     * Filtres du back-office : un e-commerçant, une zone (la commune compte avec ses quartiers), un livreur
     * (ramassage ou livraison).
     */
    public function filtered(?int $merchantId, ?int $zoneId, ?int $courierId): self
    {
        $clone = clone $this;
        $clone->merchantId = $merchantId;
        $clone->zoneIds = $zoneId ? Zone::forCompany($this->company->id)->where(fn ($q) => $q->whereKey($zoneId)->orWhere('parent_id', $zoneId))->pluck('id')->all() : null;
        $clone->courierId = $courierId;

        return $clone;
    }

    /**
     * Les courses d'un seul e-commerçant (vue d'ensemble, livraisons, zones).
     */
    public function forMerchant(Merchant $merchant): self
    {
        $clone = $this->for($merchant->company);
        $clone->merchantId = $merchant->id;

        return $clone;
    }

    /**
     * Période précédente de même durée (comparaison).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function previous(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = (int) CarbonImmutable::parse($from->toDateString())->diffInDays(CarbonImmutable::parse($to->toDateString())) + 1;
        $prevTo = CarbonImmutable::parse($from->toDateString())->subDay();

        return [$prevTo->subDays($days - 1), $prevTo];
    }

    /**
     * Vue d'ensemble : chiffres clés (avec la période précédente), courbe par jour,
     * heures de pointe.
     *
     * @return array<string, mixed>
     */
    public function overview(CarbonInterface $from, CarbonInterface $to, bool $withAmounts): array
    {
        [$prevFrom, $prevTo] = self::previous($from, $to);

        $created = $this->ordersBetween('created_at', $from, $to, ['id', 'created_at']);
        $delivered = $this->deliveredBetween($from, $to, ['id', 'delivered_at', 'delivery_fee', 'surcharges_total', 'collected_amount']);

        $days = $this->days($from, $to);
        $series = $days->mapWithKeys(fn (string $d) => [$d => ['date' => $d, 'created' => 0, 'delivered' => 0, 'revenue' => 0]])->all();
        foreach ($created as $o) {
            $series[$this->localDate($o->created_at)]['created']++;
        }
        foreach ($delivered as $o) {
            $day = $this->deliveryDay($o);
            if (! isset($series[$day])) {
                continue;
            }
            $series[$day]['delivered']++;
            $series[$day]['revenue'] += $o->delivery_fee + $o->surcharges_total;
        }
        if (! $withAmounts) {
            $series = array_map(fn ($row) => [...$row, 'revenue' => null], $series);
        }

        // Heures de pointe : courses créées par jour de la semaine (1 = lundi) et par heure
        $heatmap = array_fill(1, 7, array_fill(0, 24, 0));
        foreach ($created as $o) {
            $local = $o->created_at->copy()->setTimezone($this->tz);
            $heatmap[$local->dayOfWeekIso][$local->hour]++;
        }

        return [
            'period' => $this->period($from, $to),
            'previous_period' => $this->period($prevFrom, $prevTo),
            'kpis' => $this->kpis($from, $to, $withAmounts, $created->count(), $delivered),
            'previous_kpis' => $this->kpis($prevFrom, $prevTo, $withAmounts),
            'series' => array_values($series),
            'heatmap' => array_values(array_map('array_values', $heatmap)),
        ];
    }

    /**
     * Qualité des livraisons : réussite au premier passage, motifs d'échec, délais,
     * issues des courses.
     *
     * @return array<string, mixed>
     */
    public function deliveries(CarbonInterface $from, CarbonInterface $to): array
    {
        $delivered = $this->deliveredBetween($from, $to, ['id', 'created_at', 'picked_up_at', 'delivered_at', 'attempts_count', 'first_delivery_date']);
        $count = $delivered->count();

        $failures = $this->failureEvents($from, $to)->with('incidentReason:id,label')->get(['id', 'order_id', 'incident_reason_id', 'to_status', 'from_status']);

        $reasons = $failures->groupBy(fn (OrderEvent $e) => $e->incidentReason?->label ?? 'Sans motif')
            ->map(fn (Collection $group, string $label) => ['label' => $label, 'count' => $group->count()])
            ->sortByDesc('count')->values();

        $minutes = fn (?CarbonInterface $a, ?CarbonInterface $b) => $a && $b && $b->greaterThanOrEqualTo($a) ? (int) round($a->diffInMinutes($b)) : null;

        // Le jour même : livrée le jour de sa création (hors courses prévues dès le départ pour plus tard)
        $sameDayBase = $delivered->filter(fn (Order $o) => $o->first_delivery_date === null || $o->first_delivery_date->toDateString() <= $this->localDate($o->created_at));

        return [
            'period' => $this->period($from, $to),
            'delivered' => $count,
            'first_attempt_rate' => $this->rate($delivered->where('attempts_count', 0)->count(), $count),
            'average_attempts' => $count ? round($delivered->avg(fn (Order $o) => $o->attempts_count + 1), 2) : null,
            'same_day_rate' => $this->rate($sameDayBase->filter(fn (Order $o) => $o->delivered_at && $this->localDate($o->delivered_at) === $this->localDate($o->created_at))->count(), $sameDayBase->count()),
            // Délais habituels (médianes, en minutes)
            'delays' => [
                'to_pickup' => $this->median($delivered->map(fn (Order $o) => $minutes($o->created_at, $o->picked_up_at))),
                'pickup_to_delivery' => $this->median($delivered->map(fn (Order $o) => $minutes($o->picked_up_at, $o->delivered_at))),
                'total' => $this->median($delivered->map(fn (Order $o) => $minutes($o->created_at, $o->delivered_at))),
            ],
            'failed_attempts' => $failures->count(),
            'failure_reasons' => $reasons,
            'outcomes' => [
                'delivered' => $count,
                'rescheduled' => $failures->where('to_status', OrderStatus::Rescheduled)->count(),
                'returned' => $this->ordersBetween('returned_at', $from, $to, ['id'])->count(),
                'lost' => $this->ordersBetween('lost_at', $from, $to, ['id'])->count(),
                'cancelled' => $this->ordersBetween('cancelled_at', $from, $to, ['id'])->count(),
            ],
        ];
    }

    /**
     * Performance de chaque livreur sur la période.
     *
     * @return array<string, mixed>
     */
    public function couriers(CarbonInterface $from, CarbonInterface $to, bool $withAmounts): array
    {
        $delivered = $this->deliveredBetween($from, $to, ['id', 'delivery_courier_id', 'picked_up_at', 'delivered_at', 'collected_amount'])->groupBy('delivery_courier_id');
        $pickups = $this->ordersBetween('picked_up_at', $from, $to, ['id', 'pickup_courier_id', 'picked_up_at'])->groupBy('pickup_courier_id');
        $returned = $this->ordersBetween('returned_at', $from, $to, ['id', 'return_courier_id'])->groupBy('return_courier_id');
        $failures = $this->failureEvents($from, $to)
            ->join('order_assignments', 'order_assignments.id', '=', 'order_events.assignment_id')
            ->get(['order_events.id', 'order_assignments.courier_id'])
            ->groupBy('courier_id');

        [$start, $end] = $this->utcRange($from, $to);
        $earnings = $withAmounts ? CourierEarning::forCompany($this->company->id)->whereBetween('created_at', [$start, $end])
            ->groupBy('courier_id')->selectRaw('courier_id, SUM(amount) AS total')->pluck('total', 'courier_id') : collect();
        $gaps = $withAmounts ? CourierRemittance::forCompany($this->company->id)->whereBetween('received_at', [$start, $end])->where('difference', '<', 0)
            ->groupBy('courier_id')->selectRaw('courier_id, SUM(difference) AS total')->pluck('total', 'courier_id') : collect();

        // Période précédente : tendance de chaque livreur
        [$prevFrom, $prevTo] = self::previous($from, $to);
        $prevDelivered = $this->deliveredBetween($prevFrom, $prevTo, ['id', 'delivery_courier_id'])->countBy('delivery_courier_id');
        $prevFailed = $this->failureEvents($prevFrom, $prevTo)
            ->join('order_assignments', 'order_assignments.id', '=', 'order_events.assignment_id')
            ->get(['order_events.id', 'order_assignments.courier_id'])->countBy('courier_id');

        $rows = Courier::forCompany($this->company->id)->with('user:id,name,status')
            ->when($this->courierId !== null, fn ($q) => $q->whereKey($this->courierId))->get()
            ->map(function (Courier $courier) use ($delivered, $pickups, $returned, $failures, $earnings, $gaps, $withAmounts, $prevDelivered, $prevFailed) {
                $own = $delivered->get($courier->id, collect());
                $picked = $pickups->get($courier->id, collect());
                $failed = $failures->get($courier->id, collect())->count();

                $days = $own->map(fn (Order $o) => $this->deliveryDay($o))
                    ->merge($picked->map(fn (Order $o) => $this->localDate($o->picked_up_at)))
                    ->unique()->count();

                return [
                    'id' => $courier->id,
                    'name' => $courier->user?->name ?? "Livreur n° {$courier->id}",
                    'active' => $courier->user?->status?->value === 'active',
                    'delivered' => $own->count(),
                    'pickups' => $picked->count(),
                    'failed' => $failed,
                    'returned' => $returned->get($courier->id, collect())->count(),
                    'success_rate' => $this->rate($own->count(), $own->count() + $failed),
                    'previous_delivered' => (int) ($prevDelivered[$courier->id] ?? 0),
                    'previous_success_rate' => $this->rate((int) ($prevDelivered[$courier->id] ?? 0), (int) ($prevDelivered[$courier->id] ?? 0) + (int) ($prevFailed[$courier->id] ?? 0)),
                    // Délai habituel entre le ramassage et la livraison (minutes)
                    'delivery_minutes' => $this->median($own->map(fn (Order $o) => $o->picked_up_at && $o->delivered_at && $o->delivered_at->greaterThanOrEqualTo($o->picked_up_at) ? (int) round($o->picked_up_at->diffInMinutes($o->delivered_at)) : null)),
                    'days_worked' => $days,
                    'per_day' => $days ? round($own->count() / $days, 1) : null,
                    'collected' => $withAmounts ? (int) $own->sum('collected_amount') : null,
                    'cash_gap' => $withAmounts ? (int) ($gaps[$courier->id] ?? 0) : null,
                    'earnings' => $withAmounts ? (int) ($earnings[$courier->id] ?? 0) : null,
                ];
            })
            // Les livreurs inactifs sans activité sur la période ne sont pas listés
            ->filter(fn (array $r) => $r['active'] || $r['delivered'] + $r['pickups'] + $r['failed'] + $r['returned'] > 0)
            ->sortBy([['delivered', 'desc'], ['name', 'asc']])
            ->values();

        // Charge de travail : livraisons par jour de la semaine (1 = lundi) et par heure
        $workload = array_fill(1, 7, array_fill(0, 24, 0));
        foreach ($delivered->flatten(1) as $o) {
            if ($o->delivered_at) {
                $local = $o->delivered_at->copy()->setTimezone($this->tz);
                $workload[$local->dayOfWeekIso][$local->hour]++;
            }
        }

        return [
            'period' => $this->period($from, $to),
            'couriers' => $rows,
            'workload' => array_values(array_map('array_values', $workload)),
            'totals' => [
                'delivered' => $rows->sum('delivered'),
                'failed' => $rows->sum('failed'),
                'success_rate' => $this->rate($rows->sum('delivered'), $rows->sum('delivered') + $rows->sum('failed')),
                'active_couriers' => $rows->filter(fn (array $r) => $r['days_worked'] > 0)->count(),
            ],
        ];
    }

    /**
     * Jour par jour pour un livreur : livraisons faites et échecs (fiche du livreur).
     *
     * @return list<array{day: string, delivered: int, failed: int}>
     */
    public function courierDays(int $courierId, CarbonInterface $from, CarbonInterface $to): array
    {
        $delivered = $this->deliveredBetween($from, $to, ['id', 'delivery_courier_id'])
            ->where('delivery_courier_id', $courierId)
            ->countBy(fn (Order $o) => $this->deliveryDay($o));
        $failed = $this->failureEvents($from, $to)
            ->join('order_assignments', 'order_assignments.id', '=', 'order_events.assignment_id')
            ->where('order_assignments.courier_id', $courierId)
            ->get(['order_events.id', 'order_events.created_at'])
            ->countBy(fn (OrderEvent $e) => $this->localDate($e->created_at));

        $days = [];
        for ($d = CarbonImmutable::parse($from->toDateString()); $d->lte(CarbonImmutable::parse($to->toDateString())); $d = $d->addDay()) {
            $key = $d->toDateString();
            $days[] = ['day' => $key, 'delivered' => (int) ($delivered[$key] ?? 0), 'failed' => (int) ($failed[$key] ?? 0)];
        }

        return $days;
    }

    /**
     * E-commerçants : volume, réussite et chiffre d'affaires de chacun ; nouveaux,
     * actifs, et ceux qui ne commandent plus (à relancer).
     *
     * @return array<string, mixed>
     */
    public function merchants(CarbonInterface $from, CarbonInterface $to, bool $withAmounts): array
    {
        $created = $this->ordersBetween('created_at', $from, $to, ['id', 'merchant_id'])->countBy('merchant_id');
        $delivered = $this->deliveredBetween($from, $to, ['id', 'merchant_id', 'delivery_fee', 'surcharges_total', 'collected_amount'])->groupBy('merchant_id');
        $returned = $this->ordersBetween('returned_at', $from, $to, ['id', 'merchant_id'])->countBy('merchant_id');
        $lost = $this->ordersBetween('lost_at', $from, $to, ['id', 'merchant_id'])->countBy('merchant_id');
        $cancelled = $this->ordersBetween('cancelled_at', $from, $to, ['id', 'merchant_id'])->countBy('merchant_id');
        $lastOrder = Order::forCompany($this->company->id)->groupBy('merchant_id')->selectRaw('merchant_id, MAX(created_at) AS last_at')->pluck('last_at', 'merchant_id');
        $balances = $withAmounts ? app(MerchantPayouts::class)->summaries() : [];

        [$start, $end] = $this->utcRange($from, $to);
        $inactiveSince = now()->subDays(self::INACTIVE_DAYS);

        $merchants = Merchant::forCompany($this->company->id)->when($this->merchantId !== null, fn ($q) => $q->whereKey($this->merchantId))
            ->get(['id', 'business_name', 'contact_name', 'phone', 'status', 'created_at']);

        // Période précédente : qui monte, qui baisse ; ce que chacun rapporte à l'agence
        [$prevFrom, $prevTo] = self::previous($from, $to);
        $prevCreated = $this->ordersBetween('created_at', $prevFrom, $prevTo, ['id', 'merchant_id'])->countBy('merchant_id');
        $margins = $withAmounts ? $this->margins($from, $to, $delivered->flatten(1), 'merchant_id') : [];

        $rows = $merchants->map(function (Merchant $m) use ($created, $delivered, $returned, $lost, $cancelled, $lastOrder, $balances, $withAmounts, $prevCreated, $margins) {
            $own = $delivered->get($m->id, collect());
            $now = (int) ($created[$m->id] ?? 0);
            $before = (int) ($prevCreated[$m->id] ?? 0);
            $finished = $own->count() + ($returned[$m->id] ?? 0) + ($lost[$m->id] ?? 0);

            return [
                'id' => $m->id,
                'name' => $m->business_name,
                'phone' => $m->phone,
                'created' => $created[$m->id] ?? 0,
                'delivered' => $own->count(),
                'returned' => $returned[$m->id] ?? 0,
                'cancelled' => $cancelled[$m->id] ?? 0,
                'success_rate' => $this->rate($own->count(), $finished),
                'previous_created' => $before,
                'evolution' => $before > 0 ? (int) round(($now - $before) / $before * 100) : null,
                // up : +20 % ou plus ; down : −20 % ou moins (sur au moins 5 courses) ; new : rien avant
                'trend' => $this->trend($now, $before),
                'revenue' => $withAmounts ? (int) $own->sum(fn (Order $o) => $o->delivery_fee + $o->surcharges_total) : null,
                'margin' => $withAmounts ? ($margins[$m->id]['margin'] ?? 0) : null,
                'margin_per_order' => $withAmounts ? ($margins[$m->id]['per_order'] ?? null) : null,
                'collected' => $withAmounts ? (int) $own->sum('collected_amount') : null,
                // Reste à lui reverser (négatif : il doit de l'argent à l'entreprise)
                'to_pay' => $withAmounts ? (int) ($balances[$m->id]['unpaid_total'] ?? 0) : null,
                'last_order_at' => isset($lastOrder[$m->id]) ? CarbonImmutable::parse($lastOrder[$m->id], 'UTC')->toIso8601String() : null,
            ];
        });

        $active = $rows->filter(fn (array $r) => $r['created'] + $r['delivered'] + $r['previous_created'] > 0)
            ->sortBy([['created', 'desc'], ['delivered', 'desc'], ['name', 'asc']])->values();

        // À relancer : actifs, qui ont déjà commandé, mais plus depuis INACTIVE_DAYS jours
        $inactive = $merchants
            ->filter(fn (Merchant $m) => $m->status === MerchantStatus::Active && isset($lastOrder[$m->id])
                && CarbonImmutable::parse($lastOrder[$m->id], 'UTC')->lessThan($inactiveSince))
            ->map(fn (Merchant $m) => [
                'id' => $m->id,
                'name' => $m->business_name,
                'contact_name' => $m->contact_name,
                'phone' => $m->phone,
                'last_order_at' => CarbonImmutable::parse($lastOrder[$m->id], 'UTC')->toIso8601String(),
                'days' => (int) CarbonImmutable::parse($lastOrder[$m->id], 'UTC')->diffInDays(now()),
            ])
            ->sortBy('days')->values();

        return [
            'period' => $this->period($from, $to),
            'merchants' => $active,
            'inactive' => $inactive,
            'inactive_days' => self::INACTIVE_DAYS,
            'totals' => [
                'active' => $active->filter(fn (array $r) => $r['created'] > 0)->count(),
                'new' => $merchants->filter(fn (Merchant $m) => $m->created_at->betweenIncluded($start, $end))->count(),
                'inactive' => $inactive->count(),
                'total' => $merchants->where('status', MerchantStatus::Active)->count(),
                // Part des courses des 5 premiers : dépendance à quelques gros clients
                'top5_share' => $this->rate($active->sortByDesc('created')->take(5)->sum('created'), $active->sum('created')),
                'growing' => $active->where('trend', 'up')->count(),
                'declining' => $active->where('trend', 'down')->count(),
                'losing' => $withAmounts ? $active->filter(fn (array $r) => $r['margin'] < 0)->count() : null,
                'to_pay' => $withAmounts ? (int) collect($balances)->sum(fn ($b) => max(0, $b['unpaid_total'])) : null,
            ],
        ];
    }

    /**
     * Zones de livraison, regroupées par commune (le quartier compte dans sa commune).
     *
     * @return array<string, mixed>
     */
    public function zones(CarbonInterface $from, CarbonInterface $to, bool $withAmounts): array
    {
        $zones = Zone::forCompany($this->company->id)->get(['id', 'name', 'parent_id', 'is_shipping']);
        $communeOf = $zones->mapWithKeys(fn (Zone $z) => [$z->id => $z->parent_id ?? $z->id]);
        $commune = fn (?int $zoneId) => $zoneId === null ? null : ($communeOf[$zoneId] ?? $zoneId);

        $created = $this->ordersBetween('created_at', $from, $to, ['id', 'delivery_zone_id'])->countBy(fn (Order $o) => $commune($o->delivery_zone_id));
        $delivered = $this->deliveredBetween($from, $to, ['id', 'delivery_zone_id', 'picked_up_at', 'delivered_at', 'delivery_fee', 'surcharges_total'])
            ->groupBy(fn (Order $o) => $commune($o->delivery_zone_id));
        $returned = $this->ordersBetween('returned_at', $from, $to, ['id', 'delivery_zone_id'])->countBy(fn (Order $o) => $commune($o->delivery_zone_id));
        $lost = $this->ordersBetween('lost_at', $from, $to, ['id', 'delivery_zone_id'])->countBy(fn (Order $o) => $commune($o->delivery_zone_id));
        $failed = $this->failureEvents($from, $to)
            ->join('orders', 'orders.id', '=', 'order_events.order_id')
            ->get(['order_events.id', 'orders.delivery_zone_id'])
            ->countBy(fn ($e) => $commune($e->delivery_zone_id));

        $margins = $withAmounts ? $this->margins($from, $to, $delivered->flatten(1), 'delivery_zone_id', $commune) : [];

        $rows = $zones->whereNull('parent_id')->map(function (Zone $z) use ($created, $delivered, $returned, $lost, $failed, $withAmounts, $margins) {
            $own = $delivered->get($z->id, collect());
            $revenue = (int) $own->sum(fn (Order $o) => $o->delivery_fee + $o->surcharges_total);

            return [
                'id' => $z->id,
                'name' => $z->name,
                'is_shipping' => (bool) $z->is_shipping,
                'created' => $created[$z->id] ?? 0,
                'delivered' => $own->count(),
                'failed' => $failed[$z->id] ?? 0,
                'returned' => $returned[$z->id] ?? 0,
                // Réussite des passages chez les destinataires de la commune
                'success_rate' => $this->rate($own->count(), $own->count() + ($failed[$z->id] ?? 0)),
                'lost' => $lost[$z->id] ?? 0,
                'delivery_minutes' => $this->median($own->map(fn (Order $o) => $o->picked_up_at && $o->delivered_at && $o->delivered_at->greaterThanOrEqualTo($o->picked_up_at) ? (int) round($o->picked_up_at->diffInMinutes($o->delivered_at)) : null)),
                'revenue' => $withAmounts ? $revenue : null,
                'average_fee' => $withAmounts && $own->count() ? (int) round($revenue / $own->count()) : null,
                'margin' => $withAmounts ? ($margins[$z->id]['margin'] ?? 0) : null,
                'margin_per_order' => $withAmounts ? ($margins[$z->id]['per_order'] ?? null) : null,
            ];
        })
            ->filter(fn (array $r) => $r['created'] + $r['delivered'] + $r['failed'] + $r['returned'] > 0)
            ->sortBy([['delivered', 'desc'], ['created', 'desc'], ['name', 'asc']])
            ->values();

        $shipping = $rows->where('is_shipping', true);

        return [
            'period' => $this->period($from, $to),
            'zones' => $rows,
            'totals' => [
                'zones' => $rows->count(),
                'delivered' => $rows->sum('delivered'),
                // Part des courses livrées par expédition (gares, transporteurs vers l'intérieur)
                'shipping_share' => $this->rate($shipping->sum('delivered'), $rows->sum('delivered')),
            ],
        ];
    }

    /**
     * Finances : revenus, dépenses et marge de la période ; argent en circulation
     * aujourd'hui (chez les livreurs, à reverser aux marchands).
     *
     * @return array<string, mixed>
     */
    public function finances(CarbonInterface $from, CarbonInterface $to): array
    {
        [$start, $end] = $this->utcRange($from, $to);

        $ledger = MerchantLedgerEntry::forCompany($this->company->id)->whereBetween('created_at', [$start, $end])
            ->groupBy('type')->selectRaw('type, SUM(amount) AS total')->pluck('total', 'type');
        $entry = fn (LedgerEntryType $type) => (int) ($ledger[$type->value] ?? 0);

        $revenue = [
            'delivery' => -$entry(LedgerEntryType::DeliveryFee),
            'returns' => -$entry(LedgerEntryType::ReturnFee),
            'storage' => -$entry(LedgerEntryType::StorageFee),
        ];
        $costs = [
            // Paie des livreurs enregistrée sur la période (retenues déduites)
            'courier_pay' => (int) CourierEarning::forCompany($this->company->id)->whereBetween('created_at', [$start, $end])->sum('amount'),
            // Frais de course pris en charge par l'entreprise (non refacturés)
            'expenses' => (int) OrderExpense::forCompany($this->company->id)->active()->where('billed_to', 'company')->whereBetween('created_at', [$start, $end])->sum('amount'),
            'lost_compensations' => $entry(LedgerEntryType::LostCompensation),
        ];
        $revenueTotal = array_sum($revenue);
        $costTotal = array_sum($costs);
        $delivered = $this->deliveredBetween($from, $to, ['id'])->count();

        // Argent en circulation, à cet instant
        $courierCash = collect(app(CourierCash::class)->balances());
        $merchantBalances = collect(app(MerchantPayouts::class)->summaries());

        return [
            'period' => $this->period($from, $to),
            'revenue' => [...$revenue, 'total' => $revenueTotal],
            'costs' => [...$costs, 'total' => $costTotal],
            'margin' => $revenueTotal - $costTotal,
            'margin_rate' => $revenueTotal > 0 ? round(($revenueTotal - $costTotal) / $revenueTotal * 100, 1) : null,
            'margin_per_order' => $delivered ? (int) round(($revenueTotal - $costTotal) / $delivered) : null,
            'delivered' => $delivered,
            // Frais avancés puis refacturés aux marchands : ni revenu ni dépense
            'rebilled' => -$entry(LedgerEntryType::ShippingFee) - $entry(LedgerEntryType::OtherFee),
            'months' => $this->monthly($from, $to),
            'circulation' => [
                'with_couriers' => (int) $courierCash->sum(fn ($b) => max(0, $b['due'])),
                'to_merchants' => (int) $merchantBalances->sum(fn ($b) => max(0, $b['unpaid_total'])),
                'to_merchants_available' => (int) $merchantBalances->sum(fn ($b) => max(0, $b['available'])),
                'owed_by_merchants' => (int) -$merchantBalances->sum(fn ($b) => min(0, $b['unpaid_total'])),
            ],
        ];
    }

    /**
     * Échecs et annulations : combien, pourquoi, où, chez quels e-commerçants, ce que ça coûte, et les clients
     * qui refusent souvent.
     *
     * @return array<string, mixed>
     */
    public function failures(CarbonInterface $from, CarbonInterface $to, bool $withAmounts): array
    {
        $zones = Zone::forCompany($this->company->id)->get(['id', 'name', 'parent_id']);
        $communeOf = $zones->mapWithKeys(fn (Zone $z) => [$z->id => $z->parent_id ?? $z->id]);
        $commune = fn (?int $zoneId) => $zoneId === null ? null : ($communeOf[$zoneId] ?? $zoneId);
        $names = $zones->pluck('name', 'id');

        $events = $this->failureEvents($from, $to)->join('orders', 'orders.id', '=', 'order_events.order_id')
            ->get(['order_events.id', 'orders.merchant_id', 'orders.delivery_zone_id']);
        $delivered = $this->deliveredBetween($from, $to, ['id', 'merchant_id', 'delivery_zone_id']);
        $cancelled = $this->ordersBetween('cancelled_at', $from, $to, ['id', 'merchant_id'])->countBy('merchant_id');
        $returned = $this->ordersBetween('returned_at', $from, $to, ['id', 'delivery_fee', 'surcharges_total']);

        $table = function (Collection $failed, Collection $done, callable $name, int $min) {
            return $failed->keys()->merge($done->keys())->unique()
                ->map(fn ($key) => [
                    'id' => $key,
                    'name' => $name($key),
                    'delivered' => (int) ($done[$key] ?? 0),
                    'failed' => (int) ($failed[$key] ?? 0),
                    'failure_rate' => $this->rate((int) ($failed[$key] ?? 0), (int) ($failed[$key] ?? 0) + (int) ($done[$key] ?? 0)),
                ])
                ->filter(fn ($r) => $min <= $r['delivered'] + $r['failed'] && $r['failed'] > 0)
                ->sortByDesc('failure_rate')->take(10)->values();
        };
        $merchantNames = Merchant::forCompany($this->company->id)->pluck('business_name', 'id');
        $byMerchant = $table($events->countBy('merchant_id'), $delivered->countBy('merchant_id'), fn ($id) => $merchantNames[$id] ?? '—', 5)
            ->map(fn ($r) => [...$r, 'cancelled' => (int) ($cancelled[$r['id']] ?? 0)]);
        $byZone = $table($events->countBy(fn ($e) => $commune($e->delivery_zone_id)), $delivered->countBy(fn ($o) => $commune($o->delivery_zone_id)), fn ($id) => $names[$id] ?? 'Sans zone', 5);

        // Clients qui refusent souvent : colis revenus (toutes dates), parmi les destinataires de la période
        $phones = $this->orders()->whereBetween('created_at', $this->utcRange($from, $to))->select('recipient_phone');
        $customers = $this->orders()->whereNotNull('returned_at')->whereIn('recipient_phone', $phones)
            ->groupBy('recipient_phone')->selectRaw('recipient_phone, MAX(recipient_name) AS name, COUNT(*) AS refusals')
            ->havingRaw('COUNT(*) >= 3')->orderByDesc('refusals')->limit(10)->get()
            ->map(fn ($r) => ['phone' => $r->recipient_phone, 'name' => $r->name, 'refusals' => (int) $r->refusals])->values();

        $failed = $events->count();
        $kpis = fn (int $failedCount, int $deliveredCount, int $cancelledCount, int $returnedCount) => [
            'failed_attempts' => $failedCount,
            'failure_rate' => $this->rate($failedCount, $failedCount + $deliveredCount),
            'cancelled' => $cancelledCount,
            'returned' => $returnedCount,
        ];
        [$prevFrom, $prevTo] = self::previous($from, $to);

        return [
            'period' => $this->period($from, $to),
            'kpis' => [
                ...$kpis($failed, $delivered->count(), $cancelled->sum(), $returned->count()),
                // Frais de livraison non gagnés : colis revenus chez l'e-commerçant
                'lost_fees' => $withAmounts ? (int) $returned->sum(fn ($o) => $o->delivery_fee + $o->surcharges_total) : null,
            ],
            'previous_kpis' => $kpis(
                $this->failureEvents($prevFrom, $prevTo)->count(),
                $this->deliveredBetween($prevFrom, $prevTo, ['id'])->count(),
                $this->ordersBetween('cancelled_at', $prevFrom, $prevTo, ['id'])->count(),
                $this->ordersBetween('returned_at', $prevFrom, $prevTo, ['id'])->count(),
            ),
            'reasons' => $this->deliveries($from, $to)['failure_reasons'],
            'by_zone' => $byZone,
            'by_merchant' => $byMerchant,
            'customers' => $customers,
        ];
    }

    /**
     * Lives des e-commerçants : nombre, commandes prises, livraisons, part des courses, meilleurs moments.
     *
     * @return array<string, mixed>
     */
    public function live(CarbonInterface $from, CarbonInterface $to, bool $withAmounts): array
    {
        $range = $this->utcRange($from, $to);
        $lives = Live::forCompany($this->company->id)->whereBetween('started_at', $range)
            ->when($this->merchantId !== null, fn ($q) => $q->where('merchant_id', $this->merchantId))
            ->get(['id', 'merchant_id', 'started_at']);
        $liveOrders = LiveOrder::query()->whereIn('live_id', $lives->pluck('id'))->where('status', LiveOrder::ORDERED)->get(['live_id', 'order_id', 'created_at']);
        $statuses = $this->orders()->whereIn('id', $liveOrders->pluck('order_id')->filter())->pluck('status', 'id');
        $liveOrders = $liveOrders->filter(fn ($o) => $o->order_id && $statuses->has($o->order_id));
        $isDelivered = fn ($o) => $statuses[$o->order_id] === OrderStatus::Delivered;

        $created = $this->ordersBetween('created_at', $from, $to, ['id', 'source']);
        $byLive = $liveOrders->groupBy('live_id');
        $names = Merchant::forCompany($this->company->id)->whereIn('id', $lives->pluck('merchant_id'))->pluck('business_name', 'id');

        // Commandes par tranche de 5 minutes depuis le début du live (au-delà d'une heure : dernière tranche)
        $startOf = $lives->pluck('started_at', 'id');
        $minutes = array_fill(0, 13, 0);
        foreach ($liveOrders as $o) {
            $start = $startOf[$o->live_id] ?? null;
            if ($start) {
                $minutes[min(12, intdiv(max(0, (int) $start->diffInMinutes($o->created_at)), 5))]++;
            }
        }

        $merchants = $lives->groupBy('merchant_id')->map(function (Collection $own, $merchantId) use ($byLive, $isDelivered, $names) {
            $orders = $own->flatMap(fn ($l) => $byLive->get($l->id, collect()));

            return [
                'id' => $merchantId,
                'name' => $names[$merchantId] ?? '—',
                'lives' => $own->count(),
                'orders' => $orders->count(),
                'delivered_rate' => $this->rate($orders->filter($isDelivered)->count(), $orders->count()),
            ];
        })->sortByDesc('orders')->values();

        [$prevFrom, $prevTo] = self::previous($from, $to);
        $prevRange = $this->utcRange($prevFrom, $prevTo);
        $prevLives = Live::forCompany($this->company->id)->whereBetween('started_at', $prevRange)
            ->when($this->merchantId !== null, fn ($q) => $q->where('merchant_id', $this->merchantId))->pluck('id');
        $prevCreated = $this->ordersBetween('created_at', $prevFrom, $prevTo, ['id', 'source']);

        return [
            'period' => $this->period($from, $to),
            'kpis' => [
                'lives' => $lives->count(),
                'merchants' => $lives->pluck('merchant_id')->unique()->count(),
                'orders' => $liveOrders->count(),
                'delivered_rate' => $this->rate($liveOrders->filter($isDelivered)->count(), $liveOrders->count()),
                'share' => $this->rate($created->where('source', 'live')->count(), $created->count()),
                // Ce que l'agence garde sur les frais du live (part laissée par Jibiat)
                'agency_amount' => $withAmounts ? (int) LiveCharge::forCompany($this->company->id)->whereBetween('created_at', $range)
                    ->when($this->merchantId !== null, fn ($q) => $q->where('merchant_id', $this->merchantId))
                    ->sum(DB::raw('amount - platform_amount')) : null,
            ],
            'previous_kpis' => [
                'lives' => $prevLives->count(),
                'orders' => LiveOrder::query()->whereIn('live_id', $prevLives)->where('status', LiveOrder::ORDERED)->count(),
                'share' => $this->rate($prevCreated->where('source', 'live')->count(), $prevCreated->count()),
            ],
            'merchants' => $merchants,
            'minutes' => $minutes,
        ];
    }

    /**
     * Revenus et dépenses mois par mois sur la période.
     *
     * @return list<array{month: string, revenue: int, costs: int, margin: int}>
     */
    private function monthly(CarbonInterface $from, CarbonInterface $to): array
    {
        [$start, $end] = $this->utcRange($from, $to);
        $month = fn ($moment) => CarbonImmutable::parse($moment, 'UTC')->setTimezone($this->tz)->format('Y-m');

        $months = [];
        for ($m = CarbonImmutable::parse($from->toDateString())->startOfMonth(); $m->lessThanOrEqualTo(CarbonImmutable::parse($to->toDateString())); $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'revenue' => 0, 'costs' => 0, 'margin' => 0];
        }
        $add = function (string $key, string $field, int $amount) use (&$months) {
            if (isset($months[$key])) {
                $months[$key][$field] += $amount;
            }
        };

        MerchantLedgerEntry::forCompany($this->company->id)->whereBetween('created_at', [$start, $end])
            ->whereIn('type', [LedgerEntryType::DeliveryFee->value, LedgerEntryType::ReturnFee->value, LedgerEntryType::StorageFee->value, LedgerEntryType::LostCompensation->value])
            ->get(['type', 'amount', 'created_at'])
            ->each(fn ($e) => $e->type === LedgerEntryType::LostCompensation
                ? $add($month($e->created_at), 'costs', (int) $e->amount)
                : $add($month($e->created_at), 'revenue', -(int) $e->amount));
        CourierEarning::forCompany($this->company->id)->whereBetween('created_at', [$start, $end])->get(['amount', 'created_at'])
            ->each(fn ($e) => $add($month($e->created_at), 'costs', (int) $e->amount));
        OrderExpense::forCompany($this->company->id)->active()->where('billed_to', 'company')->whereBetween('created_at', [$start, $end])->get(['amount', 'created_at'])
            ->each(fn ($e) => $add($month($e->created_at), 'costs', (int) $e->amount));

        return array_values(array_map(fn ($m) => [...$m, 'margin' => $m['revenue'] - $m['costs']], $months));
    }

    /**
     * @param  Collection<int, Order>|null  $delivered
     * @return array<string, mixed>
     */
    private function kpis(CarbonInterface $from, CarbonInterface $to, bool $withAmounts, ?int $created = null, ?Collection $delivered = null): array
    {
        $created ??= $this->ordersBetween('created_at', $from, $to, ['id'])->count();
        $delivered ??= $this->deliveredBetween($from, $to, ['id', 'delivery_fee', 'surcharges_total', 'collected_amount']);
        $returned = $this->ordersBetween('returned_at', $from, $to, ['id'])->count();
        $lost = $this->ordersBetween('lost_at', $from, $to, ['id'])->count();
        $revenue = (int) $delivered->sum(fn (Order $o) => $o->delivery_fee + $o->surcharges_total);

        return [
            'created' => $created,
            'delivered' => $delivered->count(),
            'failed_attempts' => $this->failureEvents($from, $to)->count(),
            'returned' => $returned,
            'cancelled' => $this->ordersBetween('cancelled_at', $from, $to, ['id'])->count(),
            // Courses terminées : livrées, ou revenues chez le marchand / perdues
            'success_rate' => $this->rate($delivered->count(), $delivered->count() + $returned + $lost),
            'revenue' => $withAmounts ? $revenue : null,
            'collected' => $withAmounts ? (int) $delivered->sum('collected_amount') : null,
            'average_fee' => $withAmounts && $delivered->count() ? (int) round($revenue / $delivered->count()) : null,
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return Collection<int, Order>
     */
    private function ordersBetween(string $column, CarbonInterface $from, CarbonInterface $to, array $columns): Collection
    {
        return $this->orders()
            ->whereBetween($column, $this->utcRange($from, $to))
            ->get($columns);
    }

    /**
     * Courses livrées dont la date de livraison tombe dans la période : la date qui compte partout (liste, point du
     * jour, statistiques). Le jour est dans « delivery_day ».
     */
    private function deliveredBetween(CarbonInterface $from, CarbonInterface $to, array $columns): Collection
    {
        return $this->orders()
            ->where('status', OrderStatus::Delivered)
            ->forPeriod($from->toDateString(), $to->toDateString(), 'delivery')
            ->select($columns)->selectRaw(Order::DELIVERY_DAY_SQL.' AS delivery_day')
            ->get();
    }

    private function deliveryDay(Order $order): string
    {
        return substr((string) $order->getAttribute('delivery_day'), 0, 10);
    }

    /**
     * Courses de l'entreprise, ou du seul e-commerçant choisi.
     */
    private function orders()
    {
        return Order::forCompany($this->company->id)
            ->when($this->merchantId !== null, fn ($q) => $q->where('merchant_id', $this->merchantId))
            ->when($this->zoneIds !== null, fn ($q) => $q->whereIn('delivery_zone_id', $this->zoneIds ?: [0]))
            ->when($this->courierId !== null, fn ($q) => $q->where(fn ($w) => $w->where('delivery_courier_id', $this->courierId)->orWhere('pickup_courier_id', $this->courierId)));
    }

    /**
     * Échecs de livraison enregistrés sur la période (avec motif, depuis « En chemin »).
     */
    private function failureEvents(CarbonInterface $from, CarbonInterface $to)
    {
        return OrderEvent::query()
            ->whereIn('order_events.order_id', $this->orders()->select('id'))
            ->where('order_events.type', OrderEventType::Incident->value)
            ->where('order_events.from_status', OrderStatus::OutForDelivery->value)
            ->whereIn('order_events.to_status', self::FAILURE_STATUSES)
            ->whereBetween('order_events.created_at', $this->utcRange($from, $to));
    }

    /**
     * Ce que rapportent les courses livrées, regroupées (par e-commerçant, par commune) : frais de livraison,
     * moins la paie des livreurs liée à ces courses, moins les frais pris en charge par l'agence. La paie qui
     * n'est liée à aucune course (salaire, primes) est répartie selon le nombre de courses livrées.
     *
     * @param  Collection<int, Order>  $delivered
     * @return array<int|string, array{margin: int, per_order: ?int}>
     */
    private function margins(CarbonInterface $from, CarbonInterface $to, Collection $delivered, string $column, ?callable $group = null): array
    {
        $group ??= fn ($v) => $v;
        [$start, $end] = $this->utcRange($from, $to);
        $pay = CourierEarning::forCompany($this->company->id)->whereBetween('created_at', [$start, $end])->get(['order_id', 'amount']);
        $expenses = OrderExpense::forCompany($this->company->id)->active()->where('billed_to', 'company')->whereBetween('created_at', [$start, $end])->get(['order_id', 'amount']);

        // Groupe de chaque course payée ou avec frais
        $keyOf = $delivered->mapWithKeys(fn (Order $o) => [$o->id => $group($o->{$column})])->all();
        $missing = $pay->pluck('order_id')->merge($expenses->pluck('order_id'))->filter()->unique()->reject(fn ($id) => array_key_exists($id, $keyOf));
        foreach ($missing->chunk(1000) as $ids) {
            $this->orders()->whereIn('id', $ids->values())->get(['id', $column])->each(function (Order $o) use (&$keyOf, $column, $group) {
                $keyOf[$o->id] = $group($o->{$column});
            });
        }

        $rows = [];
        $add = function ($key, int $amount) use (&$rows) {
            if ($key !== null) {
                $rows[$key] = ($rows[$key] ?? 0) + $amount;
            }
        };
        $counts = $delivered->countBy(fn (Order $o) => $group($o->{$column}));
        foreach ($delivered as $o) {
            $add($group($o->{$column}), (int) $o->delivery_fee + (int) $o->surcharges_total);
        }
        $shared = 0;
        foreach ($pay as $e) {
            if ($e->order_id === null) {
                $shared += (int) $e->amount;
            } elseif (array_key_exists($e->order_id, $keyOf)) {
                $add($keyOf[$e->order_id], -(int) $e->amount);
            }
        }
        foreach ($expenses as $e) {
            if ($e->order_id !== null && array_key_exists($e->order_id, $keyOf)) {
                $add($keyOf[$e->order_id], -(int) $e->amount);
            }
        }
        $total = max(1, $delivered->count());
        foreach ($counts as $key => $n) {
            $add($key, -(int) round($shared * $n / $total));
        }

        return collect($rows)->map(fn (int $margin, $key) => [
            'margin' => $margin,
            'per_order' => ($counts[$key] ?? 0) ? (int) round($margin / $counts[$key]) : null,
        ])->all();
    }

    /** Tendance d'un volume face à la période précédente */
    private function trend(int $now, int $before): ?string
    {
        if ($before === 0) {
            return $now > 0 ? 'new' : null;
        }
        if (max($now, $before) < 5) {
            return 'flat';
        }
        $change = ($now - $before) / $before * 100;

        return $change >= 20 ? 'up' : ($change <= -20 ? 'down' : 'flat');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcRange(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            CarbonImmutable::parse($from->toDateString(), $this->tz)->startOfDay()->utc(),
            CarbonImmutable::parse($to->toDateString(), $this->tz)->endOfDay()->utc(),
        ];
    }

    private function localDate(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone($this->tz)->toDateString();
    }

    /**
     * @return Collection<int, string>
     */
    private function days(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $days = collect();
        for ($d = CarbonImmutable::parse($from->toDateString()); $d->lessThanOrEqualTo(CarbonImmutable::parse($to->toDateString())); $d = $d->addDay()) {
            $days->push($d->toDateString());
        }

        return $days;
    }

    /**
     * @return array{from: string, to: string}
     */
    private function period(CarbonInterface $from, CarbonInterface $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    /**
     * @param  Collection<int, int|null>  $values
     */
    private function median(Collection $values): ?int
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->sort()->values();
        $n = $sorted->count();
        if ($n === 0) {
            return null;
        }

        return $n % 2 ? $sorted[intdiv($n, 2)] : (int) round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
    }
}
