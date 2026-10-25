<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Console : tableau de bord de la plateforme (revenu, encaissements, courses, entreprises, points à surveiller,
 * entreprises les plus actives), pour le mois en cours, 30 jours ou l'année.
 */
class ConsoleDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $period = $request->validate(['period' => ['nullable', 'in:month,30d,year']])['period'] ?? 'month';
        $now = CarbonImmutable::now();
        $start = match ($period) {
            '30d' => $now->subDays(29)->startOfDay(),
            'year' => $now->startOfYear(),
            default => $now->startOfMonth(),
        };
        // Période précédente de même durée, pour l'évolution
        $previousStart = $start->sub($start->diff($now));

        $orders = fn () => Order::query()->withoutGlobalScopes();
        $ordersNow = $orders()->where('created_at', '>=', $start)->count();
        $ordersBefore = $orders()->whereBetween('created_at', [$previousStart, $start])->count();

        $invoiced = fn ($from, $to) => (int) PlatformInvoice::query()->where('status', '!=', PlatformInvoice::VOID)
            ->whereBetween('period_start', [$from->toDateString(), $to->toDateString()])->sum('total');
        $revenue = $invoiced($start, $now);
        $revenueBefore = $invoiced($previousStart, $start->subDay());

        $open = PlatformInvoice::query()->where('status', PlatformInvoice::OPEN)->get(['id', 'total', 'amount_paid', 'due_at']);
        $late = $open->filter(fn (PlatformInvoice $i) => $i->due_at !== null && $i->due_at->isPast() && ! $i->due_at->isToday());

        $companies = Company::query();

        return response()->json(['data' => [
            'period' => $period,
            'from' => $start->toDateString(),
            'kpis' => [
                'revenue' => ['value' => $revenue, 'change' => self::change($revenue, $revenueBefore)],
                'collected' => (int) PlatformPayment::query()->where('paid_on', '>=', $start->toDateString())->sum('amount'),
                'receivable' => ['value' => (int) $open->sum(fn (PlatformInvoice $i) => $i->balance()), 'invoices' => $open->count(), 'late' => $late->count()],
                'orders' => ['value' => $ordersNow, 'change' => self::change($ordersNow, $ordersBefore), 'today' => $orders()->where('created_at', '>=', $now->startOfDay())->count()],
                'companies' => [
                    'active' => (clone $companies)->where('status', CompanyStatus::Active->value)->count(),
                    'new' => (clone $companies)->where('created_at', '>=', $start)->count(),
                    'on_trial' => (clone $companies)->whereDate('trial_ends_at', '>=', $now->toDateString())->count(),
                ],
            ],
            'series' => $this->series($start, $now, $period === 'year'),
            'watch' => $this->watch($now, $late),
            'top' => $this->top($start, $previousStart),
        ]]);
    }

    /**
     * Courses par jour (par mois sur l'année).
     *
     * @return list<array{date: string, orders: int}>
     */
    private function series(CarbonImmutable $start, CarbonImmutable $now, bool $monthly): array
    {
        $byDay = Order::query()->withoutGlobalScopes()->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupBy('day')->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (int) $total]);

        $points = [];
        for ($day = $start; $day->lte($now); $day = $monthly ? $day->addMonth() : $day->addDay()) {
            $points[] = [
                'date' => $monthly ? $day->format('Y-m') : $day->toDateString(),
                'orders' => $monthly
                    ? (int) $byDay->filter(fn ($n, $d) => str_starts_with($d, $day->format('Y-m')))->sum()
                    : (int) ($byDay[$day->toDateString()] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * Points à surveiller : entreprises qui décrochent, fins d'essai, factures en retard.
     *
     * @return array<string, mixed>
     */
    private function watch(CarbonImmutable $now, $late): array
    {
        $since = $now->subDays(7);
        $dropping = Company::query()->where('status', CompanyStatus::Active->value)->where('created_at', '<', $since)
            ->whereHas('orders')->whereDoesntHave('orders', fn ($q) => $q->where('created_at', '>=', $since))
            ->withMax('orders as last_order_at', 'created_at')->orderBy('name')->limit(20)->get(['id', 'name']);
        $trials = Company::query()->whereDate('trial_ends_at', '>=', $now->toDateString())->whereDate('trial_ends_at', '<=', $now->addDays(7)->toDateString())
            ->orderBy('trial_ends_at')->get(['id', 'name', 'trial_ends_at']);

        return [
            'dropping' => $dropping->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'last_order_at' => $c->last_order_at])->values(),
            'trials_ending' => $trials->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'trial_ends_at' => $c->trial_ends_at?->toDateString()])->values(),
            'late_invoices' => ['count' => $late->count(), 'amount' => (int) $late->sum(fn (PlatformInvoice $i) => $i->balance())],
        ];
    }

    /**
     * Entreprises les plus actives de la période.
     *
     * @return list<array<string, mixed>>
     */
    private function top(CarbonImmutable $start, CarbonImmutable $previousStart): array
    {
        $companies = Company::query()->with('plan:id,name')
            ->withCount([
                'orders as orders_now' => fn ($q) => $q->where('created_at', '>=', $start),
                'orders as orders_before' => fn ($q) => $q->whereBetween('created_at', [$previousStart, $start]),
            ])
            ->orderByDesc('orders_now')->limit(10)->get()->filter(fn (Company $c) => $c->orders_now > 0)->values();
        if ($companies->isEmpty()) {
            return [];
        }

        $couriers = DB::table('order_assignments')->join('orders', 'orders.id', '=', 'order_assignments.order_id')
            ->whereIn('orders.company_id', $companies->pluck('id'))->where('order_assignments.created_at', '>=', $start)
            ->groupBy('orders.company_id')->selectRaw('orders.company_id, COUNT(DISTINCT order_assignments.courier_id) as total')
            ->pluck('total', 'company_id');
        $invoices = PlatformInvoice::query()->whereIn('company_id', $companies->pluck('id'))->where('status', '!=', PlatformInvoice::VOID)
            ->orderByDesc('period_start')->get(['company_id', 'status', 'due_at'])->unique('company_id')->keyBy('company_id');

        return $companies->map(function (Company $c) use ($couriers, $invoices) {
            $invoice = $invoices[$c->id] ?? null;

            return [
                'id' => $c->id,
                'name' => $c->name,
                'orders' => (int) $c->orders_now,
                'change' => self::change((int) $c->orders_now, (int) $c->orders_before),
                'couriers' => (int) ($couriers[$c->id] ?? 0),
                'plan' => $c->onTrial() ? 'Essai' : $c->plan?->name,
                'invoice' => match (true) {
                    $invoice === null => null,
                    $invoice->status === PlatformInvoice::PAID => 'paid',
                    $invoice->status === PlatformInvoice::OPEN && $invoice->due_at?->isPast() && ! $invoice->due_at->isToday() => 'late',
                    $invoice->status === PlatformInvoice::OPEN => 'due',
                    default => null,
                },
            ];
        })->all();
    }

    /** Évolution en pourcentage (null sans période de comparaison) */
    private static function change(int $now, int $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    }
}
