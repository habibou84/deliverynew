<?php

namespace App\Services\Reports;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierRemittance;
use App\Models\Order;
use App\Models\OrderEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Statistiques de l'entreprise sur une période (dates dans son fuseau horaire) :
 * vue d'ensemble, qualité des livraisons, performance des livreurs.
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

    // Échecs de livraison : la course quitte « En chemin » avec un motif
    private const FAILURE_STATUSES = [OrderStatus::DeliveryFailed->value, OrderStatus::Rescheduled->value];

    private Company $company;

    private string $tz;

    public function for(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;
        $clone->tz = $company->timezone ?: config('app.timezone');

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
        $delivered = $this->ordersBetween('delivered_at', $from, $to, ['id', 'delivered_at', 'delivery_fee', 'surcharges_total', 'collected_amount']);

        $days = $this->days($from, $to);
        $series = $days->mapWithKeys(fn (string $d) => [$d => ['date' => $d, 'created' => 0, 'delivered' => 0, 'revenue' => 0]])->all();
        foreach ($created as $o) {
            $series[$this->localDate($o->created_at)]['created']++;
        }
        foreach ($delivered as $o) {
            $day = $this->localDate($o->delivered_at);
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
        $delivered = $this->ordersBetween('delivered_at', $from, $to, ['id', 'created_at', 'picked_up_at', 'delivered_at', 'attempts_count', 'delivery_scheduled_date']);
        $count = $delivered->count();

        $failures = $this->failureEvents($from, $to)->with('incidentReason:id,label')->get(['id', 'order_id', 'incident_reason_id', 'to_status', 'from_status']);

        $reasons = $failures->groupBy(fn (OrderEvent $e) => $e->incidentReason?->label ?? 'Sans motif')
            ->map(fn (Collection $group, string $label) => ['label' => $label, 'count' => $group->count()])
            ->sortByDesc('count')->values();

        $minutes = fn (?CarbonInterface $a, ?CarbonInterface $b) => $a && $b && $b->greaterThanOrEqualTo($a) ? (int) round($a->diffInMinutes($b)) : null;

        // Le jour même : livrée le jour de sa création (hors courses programmées pour plus tard)
        $sameDayBase = $delivered->filter(fn (Order $o) => $o->delivery_scheduled_date === null || $o->delivery_scheduled_date->toDateString() <= $this->localDate($o->created_at));

        return [
            'period' => $this->period($from, $to),
            'delivered' => $count,
            'first_attempt_rate' => $this->rate($delivered->where('attempts_count', 0)->count(), $count),
            'average_attempts' => $count ? round($delivered->avg(fn (Order $o) => $o->attempts_count + 1), 2) : null,
            'same_day_rate' => $this->rate($sameDayBase->filter(fn (Order $o) => $this->localDate($o->delivered_at) === $this->localDate($o->created_at))->count(), $sameDayBase->count()),
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
        $delivered = $this->ordersBetween('delivered_at', $from, $to, ['id', 'delivery_courier_id', 'picked_up_at', 'delivered_at', 'collected_amount'])->groupBy('delivery_courier_id');
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

        $rows = Courier::forCompany($this->company->id)->with('user:id,name,status')->get()
            ->map(function (Courier $courier) use ($delivered, $pickups, $returned, $failures, $earnings, $gaps, $withAmounts) {
                $own = $delivered->get($courier->id, collect());
                $picked = $pickups->get($courier->id, collect());
                $failed = $failures->get($courier->id, collect())->count();

                $days = $own->map(fn (Order $o) => $this->localDate($o->delivered_at))
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
                    // Délai habituel entre le ramassage et la livraison (minutes)
                    'delivery_minutes' => $this->median($own->map(fn (Order $o) => $o->picked_up_at && $o->delivered_at->greaterThanOrEqualTo($o->picked_up_at) ? (int) round($o->picked_up_at->diffInMinutes($o->delivered_at)) : null)),
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

        return [
            'period' => $this->period($from, $to),
            'couriers' => $rows,
            'totals' => [
                'delivered' => $rows->sum('delivered'),
                'failed' => $rows->sum('failed'),
                'success_rate' => $this->rate($rows->sum('delivered'), $rows->sum('delivered') + $rows->sum('failed')),
                'active_couriers' => $rows->filter(fn (array $r) => $r['days_worked'] > 0)->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Order>|null  $delivered
     * @return array<string, mixed>
     */
    private function kpis(CarbonInterface $from, CarbonInterface $to, bool $withAmounts, ?int $created = null, ?Collection $delivered = null): array
    {
        $created ??= $this->ordersBetween('created_at', $from, $to, ['id'])->count();
        $delivered ??= $this->ordersBetween('delivered_at', $from, $to, ['id', 'delivery_fee', 'surcharges_total', 'collected_amount']);
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
        return Order::forCompany($this->company->id)
            ->whereBetween($column, $this->utcRange($from, $to))
            ->get($columns);
    }

    /**
     * Échecs de livraison enregistrés sur la période (avec motif, depuis « En chemin »).
     */
    private function failureEvents(CarbonInterface $from, CarbonInterface $to)
    {
        return OrderEvent::query()
            ->whereIn('order_events.order_id', Order::forCompany($this->company->id)->select('id'))
            ->where('order_events.type', OrderEventType::Incident->value)
            ->where('order_events.from_status', OrderStatus::OutForDelivery->value)
            ->whereIn('order_events.to_status', self::FAILURE_STATUSES)
            ->whereBetween('order_events.created_at', $this->utcRange($from, $to));
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
