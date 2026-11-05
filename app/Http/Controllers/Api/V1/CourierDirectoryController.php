<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Models\Courier;
use App\Services\Couriers\CourierActivity;
use App\Services\Couriers\CourierRatings;
use App\Services\Reports\Statistics;
use App\Services\Vehicles\VehicleFleet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Page Livreurs : tableau paginé (note, chiffres de la période, filtres) et fiche complète d'un livreur.
 * La liste simple GET /couriers reste celle des listes déroulantes.
 */
class CourierDirectoryController extends Controller
{
    public const PERIODS = [7, 30, 90];

    private const SORTS = ['name', 'score', 'delivered', 'success_rate', 'delivery_minutes', 'missions', 'failed'];

    public function __construct(private readonly CourierRatings $ratings, private readonly Statistics $statistics) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'zone_id' => ['nullable', 'integer'],
            'available' => ['nullable', 'boolean'],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'rating' => ['nullable', Rule::in(['below', 'good', 'unrated', 'reported'])],
            'days' => ['nullable', 'integer', Rule::in(self::PERIODS)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);
        $company = $request->user()->company;
        $days = (int) ($data['days'] ?? 30);
        $status = $data['status'] ?? 'active';
        $perPage = (int) ($data['per_page'] ?? 25);

        $search = mb_strtolower(trim($data['search'] ?? ''));
        $digits = preg_replace('/\D+/', '', $search);
        $couriers = Courier::query()
            ->with(['user', 'zones', 'vehicle.documents', 'documents'])
            ->withCount('activeAssignments')
            ->whereHas('user', fn ($q) => $q
                ->when($status === 'active', fn ($q) => $q->where('status', 'active'))
                ->when($status === 'inactive', fn ($q) => $q->where('status', '!=', 'active'))
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->when($digits !== '', fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%")))))
            ->when(isset($data['available']), fn ($q) => $q->where('is_available', (bool) $data['available']))
            ->when(isset($data['vehicle_type']), fn ($q) => $q->where('vehicle_type', $data['vehicle_type']))
            ->when(isset($data['zone_id']), fn ($q) => $q->whereHas('zones', fn ($q) => $q->where('zones.id', $data['zone_id'])))
            ->get();

        $to = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        $from = $to->subDays($days - 1);
        $stats = collect($this->statistics->for($company)->couriers($from, $to, false)['couriers'])->keyBy('id');
        $scores = $this->ratings->ranking($company)->keyBy('id');

        $rows = $couriers->map(function (Courier $c) use ($stats, $scores) {
            $s = $stats->get($c->id, []);
            $r = $scores->get($c->id, []);

            return [
                'id' => $c->id,
                'name' => $c->user?->name ?? "Livreur n° {$c->id}",
                'phone' => $c->user?->phone,
                'active' => $c->user?->status?->value === 'active',
                'is_available' => (bool) $c->is_available,
                'vehicle_type' => $c->vehicle_type,
                'vehicle' => $c->vehicle ? ['id' => $c->vehicle->id, 'label' => $c->vehicle->label()] : null,
                'vehicle_plate' => $c->vehicle_plate,
                'vehicle_warnings' => app(VehicleFleet::class)->warnings($c),
                'zones' => $c->zones->reject(fn ($z) => (bool) $z->pivot?->is_backup)->pluck('name')->values(),
                'missions' => (int) $c->active_assignments_count,
                'last_location_at' => $c->last_location_at,
                'score' => $r['score'] ?? null,
                'ratings_count' => (int) ($r['count'] ?? 0),
                'trend' => $r['trend'] ?? null,
                'to_handle' => (int) ($r['to_handle'] ?? 0),
                'below_threshold' => (bool) ($r['below_threshold'] ?? false),
                'delivered' => (int) ($s['delivered'] ?? 0),
                'pickups' => (int) ($s['pickups'] ?? 0),
                'failed' => (int) ($s['failed'] ?? 0),
                'success_rate' => $s['success_rate'] ?? null,
                'delivery_minutes' => $s['delivery_minutes'] ?? null,
                'days_worked' => (int) ($s['days_worked'] ?? 0),
                'per_day' => $s['per_day'] ?? null,
            ];
        });

        // Résumé avant le filtre sur la note : le nombre de livreurs concernés par chaque filtre
        $summary = [
            'couriers' => $rows->count(),
            'available' => $rows->where('is_available', true)->count(),
            'average_score' => $rows->whereNotNull('score')->isEmpty() ? null : round($rows->whereNotNull('score')->avg('score'), 1),
            'below' => $rows->where('below_threshold', true)->count(),
            'reported' => $rows->where('to_handle', '>', 0)->count(),
            'to_handle' => (int) $rows->sum('to_handle'),
            'delivered' => (int) $rows->sum('delivered'),
        ];

        $rows = match ($data['rating'] ?? null) {
            'below' => $rows->where('below_threshold', true),
            'good' => $rows->filter(fn ($r) => $r['score'] !== null && $r['score'] >= 4),
            'unrated' => $rows->where('ratings_count', 0),
            'reported' => $rows->where('to_handle', '>', 0),
            default => $rows,
        };

        $sort = $data['sort'] ?? 'name';
        $desc = ($data['dir'] ?? ($sort === 'name' ? 'asc' : 'desc')) === 'desc';
        $key = $sort === 'missions' ? 'missions' : $sort;
        $rows = $rows->sort(function (array $a, array $b) use ($key, $desc) {
            if ($key === 'name') {
                return ($desc ? -1 : 1) * strcasecmp($a['name'], $b['name']);
            }
            // Sans valeur (pas de note, pas de livraison) : toujours en bas
            if ($a[$key] === null || $b[$key] === null) {
                return ($a[$key] === null) <=> ($b[$key] === null) ?: strcasecmp($a['name'], $b['name']);
            }

            return ($desc ? -1 : 1) * ($a[$key] <=> $b[$key]) ?: strcasecmp($a['name'], $b['name']);
        })->values();

        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($data['page'] ?? 1), $lastPage);

        return response()->json([
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => [
                'total' => $total, 'current_page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage, 'days' => $days,
                'from' => $total ? ($page - 1) * $perPage + 1 : 0, 'to' => min($total, $page * $perPage),
            ],
            'summary' => $summary,
            'threshold' => (float) $company->rating_alert_threshold,
        ]);
    }

    /**
     * Fiche d'un livreur : profil, chiffres de la période, jour par jour, aujourd'hui.
     */
    public function show(Request $request, Courier $courier, CourierActivity $activity): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', Rule::in(self::PERIODS)]]);
        $company = $request->user()->company;
        $days = (int) ($data['days'] ?? 30);
        $withAmounts = $request->user()->can(Permission::FinanceView->value);

        $to = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        $from = $to->subDays($days - 1);
        $stats = $this->statistics->for($company);
        $figures = collect($stats->couriers($from, $to, $withAmounts)['couriers'])->firstWhere('id', $courier->id);
        $today = collect($activity->today($company)['data'] ?? [])->firstWhere('id', $courier->id);

        $courier->load(['user', 'zones', 'payPlan', 'vehicle.documents', 'documents'])->loadCount('activeAssignments');

        return response()->json(['data' => [
            'courier' => CourierResource::make($courier)->resolve($request),
            'created_at' => $courier->created_at,
            'days' => $days,
            'figures' => $figures ?? [
                'delivered' => 0, 'pickups' => 0, 'failed' => 0, 'returned' => 0, 'success_rate' => null, 'delivery_minutes' => null,
                'days_worked' => 0, 'per_day' => null, 'collected' => $withAmounts ? 0 : null, 'cash_gap' => $withAmounts ? 0 : null, 'earnings' => $withAmounts ? 0 : null,
            ],
            'series' => $stats->courierDays($courier->id, $from, $to),
            'today' => $today ? collect($today)->only(['state', 'deliveries', 'pickups', 'failed', 'remaining', 'progress', 'estimated_end', 'parcels_in_hand', 'cash_in_hand', 'flags'])->all() : null,
            'with_amounts' => $withAmounts,
        ]]);
    }
}
