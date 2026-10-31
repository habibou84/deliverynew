<?php

namespace App\Services\Couriers;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderEvent;
use App\Services\Finance\CashAlerts;
use App\Services\Finance\CourierCash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Activité des livreurs, suivi du jour : pour chaque livreur, ses livraisons et ramassages du jour (faits sur
 * prévus), ses échecs, ses colis et son argent en main, et ce qui demande l'attention (remontée ouverte, colis gardé
 * trop longtemps, argent à surveiller, pas de position alors qu'il a des missions).
 */
class CourierActivity
{
    /** Sans position depuis plus longtemps, avec des missions en cours : signalé */
    public const SILENT_MINUTES = 20;

    public function __construct(private CourierCash $cash, private CashAlerts $alerts) {}

    /**
     * @return array{data: list<array<string, mixed>>, totals: array<string, int|null>}
     */
    public function today(Company $company): array
    {
        $since = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->startOfDay()->setTimezone(config('app.timezone'));

        $couriers = Courier::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->with(['user:id,name,phone', 'zones:id,name'])
            ->get();
        $ids = $couriers->modelKeys();

        // Missions du jour : en cours, ou terminées / en échec depuis ce matin
        $assignments = OrderAssignment::query()->whereIn('courier_id', $ids)
            ->where(fn ($q) => $q->whereIn('status', AssignmentStatus::activeValues())
                ->orWhere(fn ($q) => $q->whereIn('status', [AssignmentStatus::Completed->value, AssignmentStatus::Failed->value])->where('completed_at', '>=', $since)))
            ->get(['id', 'courier_id', 'order_id', 'type', 'status', 'completed_at', 'unreachable_at', 'late_alerted_at'])
            ->groupBy('courier_id');

        $held = Order::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('held_by_courier_id', $ids)
            ->get(['id', 'held_by_courier_id', 'held_since'])->groupBy('held_by_courier_id');
        $holdLimit = $company->parcel_hold_alert_hours > 0 ? now()->subHours($company->parcel_hold_alert_hours) : null;

        $userIds = $couriers->pluck('user_id', 'id');
        $reports = OrderEvent::query()->fieldReports()->whereDoesntHave('review')
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $company->id))
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('actor_id', $userIds->values())
            ->selectRaw('actor_id, COUNT(*) AS n')->groupBy('actor_id')->pluck('n', 'actor_id');

        $balances = $this->cash->balances($company->id);

        $rows = $couriers->map(function (Courier $courier) use ($assignments, $held, $holdLimit, $reports, $balances, $company) {
            /** @var Collection<int, OrderAssignment> $mine */
            $mine = $assignments->get($courier->id, collect());
            $count = fn (AssignmentType $type, ?array $statuses = null) => $mine
                ->filter(fn (OrderAssignment $a) => $a->type === $type && ($statuses === null || in_array($a->status, $statuses, true)))->count();

            $active = $mine->filter(fn (OrderAssignment $a) => in_array($a->status, AssignmentStatus::active(), true));
            $deliveries = ['done' => $count(AssignmentType::Delivery, [AssignmentStatus::Completed]), 'total' => $count(AssignmentType::Delivery)];
            $pickups = ['done' => $count(AssignmentType::Pickup, [AssignmentStatus::Completed]), 'total' => $count(AssignmentType::Pickup)];
            $failed = $mine->where('status', AssignmentStatus::Failed)->count();

            $parcels = $held->get($courier->id, collect());
            $overdue = $holdLimit ? $parcels->filter(fn ($o) => $o->held_since !== null && Carbon::parse($o->held_since)->lte($holdLimit))->count() : 0;
            $balance = $balances[$courier->id] ?? null;
            $silentFor = $courier->last_location_at ? (int) $courier->last_location_at->diffInMinutes(now()) : null;

            $state = match (true) {
                $active->isNotEmpty() => 'mission',
                $courier->is_available => 'available',
                default => 'offline',
            };

            $flags = array_values(array_filter([
                ($n = (int) ($reports[$courier->user_id] ?? 0)) > 0 ? ($n > 1 ? "{$n} remontées ouvertes" : '1 remontée ouverte') : null,
                $overdue > 0 ? ($overdue > 1 ? "{$overdue} colis gardés trop longtemps" : '1 colis gardé trop longtemps') : null,
                $balance ? $this->alerts->reason($company, $balance) : null,
                $active->contains(fn (OrderAssignment $a) => $a->unreachable_at !== null || $a->late_alerted_at !== null) ? 'Mission en retard' : null,
                $state === 'mission' && ($silentFor === null || $silentFor > self::SILENT_MINUTES) ? 'Pas de position'.($silentFor !== null ? ' depuis '.self::duration($silentFor) : '') : null,
            ]));

            $planned = $deliveries['total'] + $pickups['total'];

            return [
                'id' => $courier->id,
                'name' => $courier->user->name,
                'phone' => $courier->user->phone,
                'vehicle_type' => $courier->vehicle_type,
                'zones' => $courier->zones->pluck('name')->values(),
                'state' => $state,
                'is_available' => $courier->is_available,
                'last_location_at' => $courier->last_location_at,
                'lat' => $courier->current_lat,
                'lng' => $courier->current_lng,
                'deliveries' => $deliveries,
                'pickups' => $pickups,
                'failed' => $failed,
                'remaining' => $active->count(),
                'progress' => $planned > 0 ? (int) round(100 * ($deliveries['done'] + $pickups['done']) / $planned) : null,
                'parcels_in_hand' => $parcels->count(),
                'cash_in_hand' => (int) ($balance['due'] ?? 0),
                'flags' => $flags,
            ];
        });

        // Ceux qui demandent l'attention d'abord, puis ceux qui ont le plus à faire
        $rows = $rows->sortBy(fn (array $r) => [-count($r['flags']), $r['state'] === 'offline' ? 1 : 0, -$r['remaining'], $r['name']])->values();

        $deliveries = $rows->sum(fn ($r) => $r['deliveries']['total']);
        $done = $rows->sum(fn ($r) => $r['deliveries']['done']);
        $planned = $deliveries + $rows->sum(fn ($r) => $r['pickups']['total']);
        $achieved = $done + $rows->sum(fn ($r) => $r['pickups']['done']);

        return [
            'data' => $rows->all(),
            'totals' => [
                'couriers' => $rows->count(),
                'active' => $rows->where('state', '!=', 'offline')->count(),
                'in_hand' => (int) $rows->sum('parcels_in_hand'),
                'delivered' => $done,
                'deliveries' => $deliveries,
                'failed' => (int) $rows->sum('failed'),
                'watched' => $rows->filter(fn ($r) => $r['flags'] !== [])->count(),
                'progress' => $planned > 0 ? (int) round(100 * $achieved / $planned) : null,
            ],
        ];
    }

    /**
     * Détail d'un livreur pour le panneau : ses missions du jour.
     *
     * @return list<array<string, mixed>>
     */
    public function missions(Courier $courier): array
    {
        $company = Company::findOrFail($courier->company_id);
        $since = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->startOfDay()->setTimezone(config('app.timezone'));

        return OrderAssignment::query()->where('courier_id', $courier->id)
            ->where(fn ($q) => $q->whereIn('status', AssignmentStatus::activeValues())
                ->orWhere(fn ($q) => $q->whereIn('status', [AssignmentStatus::Completed->value, AssignmentStatus::Failed->value])->where('completed_at', '>=', $since)))
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->with(['merchant:id,business_name', 'deliveryZone:id,name', 'pickupZone:id,name'])])
            ->orderByRaw('completed_at IS NULL')->orderBy('completed_at')->orderBy('id')
            ->get()
            ->map(fn (OrderAssignment $a) => [
                'id' => $a->id,
                'order_id' => $a->order_id,
                'tracking_code' => $a->order?->tracking_code,
                'merchant' => $a->order?->merchant?->business_name,
                'zone' => $a->type === AssignmentType::Delivery ? $a->order?->deliveryZone?->name : $a->order?->pickupZone?->name,
                'type' => $a->type->value,
                'type_label' => $a->type->label(),
                'status' => $a->status->value,
                'completed_at' => $a->completed_at,
                'cod_amount' => $a->type === AssignmentType::Delivery ? (int) $a->order?->cod_amount : null,
            ])->all();
    }

    /** « 25 min », « 3 h », « 2 jours » */
    public static function duration(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => $minutes.' min',
            $minutes < 48 * 60 => intdiv($minutes, 60).' h',
            default => intdiv($minutes, 1440).' jours',
        };
    }
}
