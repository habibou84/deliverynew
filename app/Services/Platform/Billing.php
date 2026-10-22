<?php

namespace App\Services\Platform;

use App\Enums\CompanyStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Notifications\PlatformBillingNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Facturation des entreprises par la plateforme : facture du mois ou de la semaine, au choix de l'entreprise
 * (forfait au prorata des jours hors essai + courses prises en charge en plus), paiements, impayés progressifs et rappels.
 *
 * Impayés : à l'échéance, rappel ; à J+7, bandeau d'alerte (« warning ») ; à J+15, plus de
 * nouvelles courses (« restricted »), tout le reste restant consultable. Tout redevient
 * normal dès que la facture est payée.
 */
class Billing
{
    public const OK = 'ok';

    public const DUE = 'due';

    public const WARNING = 'warning';

    public const RESTRICTED = 'restricted';

    /**
     * Factures de la période de toutes les entreprises actives qui ont une formule : un mois (AAAA-MM) pour celles
     * facturées au mois, une semaine (AAAA-Wss, du lundi au dimanche) pour celles facturées à la semaine.
     *
     * @return Collection<int, PlatformInvoice> factures créées
     */
    public function generate(string $period): Collection
    {
        return Company::query()->whereNotNull('plan_id')->where('status', CompanyStatus::Active->value)->with('plan')->orderBy('id')->get()
            ->map(fn (Company $company) => $this->invoiceFor($company, $period))
            ->filter(fn (?PlatformInvoice $invoice) => $invoice?->wasRecentlyCreated)
            ->values();
    }

    /**
     * Facture du mois (AAAA-MM) d'une entreprise.
     */
    public function invoiceMonth(Company $company, string $period): ?PlatformInvoice
    {
        return $this->invoiceFor($company, $period);
    }

    /**
     * Facture d'une période (mois AAAA-MM ou semaine AAAA-Wss) d'une entreprise ; existante si déjà faite, null si
     * rien à facturer (pas de formule, période facturée sur l'autre rythme, en essai ou avant la création, montant nul).
     */
    public function invoiceFor(Company $company, string $period): ?PlatformInvoice
    {
        if ($existing = PlatformInvoice::query()->where('company_id', $company->id)->where('period', $period)->first()) {
            return $existing;
        }

        $plan = $company->loadMissing('plan')->plan;
        if ($plan === null) {
            return null;
        }

        $tz = $company->timezone;
        [$cycle, $periodStart, $periodEnd] = self::bounds($period, $tz);
        $range = $this->billedDays($company, $cycle, $periodStart, $periodEnd);
        if ($range === null) {
            return null;
        }
        [$start, $end] = $range;

        $days = (int) $start->diffInDays($end) + 1;
        if ($cycle === PlatformInvoice::WEEKLY) {
            $share = $days / 7;
            $base = (int) round($plan->weeklyPrice() * $share);
            $included = $plan->weeklyIncludedOrders() === null ? null : (int) floor($plan->weeklyIncludedOrders() * $share);
        } else {
            $share = $days / $periodStart->daysInMonth;
            $base = (int) round($plan->price_monthly * $share);
            $included = $plan->included_orders === null ? null : (int) floor($plan->included_orders * $share);
        }

        $orders = self::takenInCharge($company, $start, $end->addDay());
        $overage = $included === null ? 0 : max(0, $orders - $included);
        $overageAmount = $overage * $plan->overage_per_order;
        $total = $base + $overageAmount;

        if ($total <= 0) {
            return null;
        }

        $invoice = DB::transaction(function () use ($company, $period, $cycle, $start, $end, $plan, $base, $included, $orders, $overage, $overageAmount, $total, $tz) {
            $today = CarbonImmutable::now($tz);
            $dueDays = (int) config($cycle === PlatformInvoice::WEEKLY ? 'platform.billing.weekly_due_days' : 'platform.billing.due_days');

            return PlatformInvoice::create([
                'company_id' => $company->id,
                'number' => $this->nextNumber($period),
                'period' => $period,
                'cycle' => $cycle,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'plan_name' => $plan->name,
                'base_amount' => $base,
                'included_orders' => $included,
                'orders_count' => $orders,
                'overage_orders' => $overage,
                'overage_per_order' => $plan->overage_per_order,
                'overage_amount' => $overageAmount,
                'total' => $total,
                'issued_at' => $today->toDateString(),
                'due_at' => $today->addDays($dueDays)->toDateString(),
                'last_reminder' => 'issued',
            ]);
        });

        $this->notify($company, $invoice, 'issued');

        return $invoice;
    }

    /**
     * Courses prises en charge entre deux instants : ramassées par un livreur, ou préparées à l'entrepôt. Une course
     * annulée ou refusée avant (seul moment où c'est possible), ou jamais traitée, n'est pas comptée.
     */
    public static function takenInCharge(Company $company, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereRaw('COALESCE(picked_up_at, prepared_at) >= ?', [$from->utc()])
            ->whereRaw('COALESCE(picked_up_at, prepared_at) < ?', [$to->utc()])
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->count();
    }

    /**
     * Rythme et bornes (premier et dernier jour) d'une période : mois AAAA-MM ou semaine ISO AAAA-Wss.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    public static function bounds(string $period, string $tz): array
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $period, $m)) {
            $start = CarbonImmutable::now($tz)->setISODate((int) $m[1], (int) $m[2], 1)->startOfDay();

            return [PlatformInvoice::WEEKLY, $start, $start->addDays(6)];
        }
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$period}-01", $tz)->startOfDay();

        return [PlatformInvoice::MONTHLY, $start, $start->endOfMonth()->startOfDay()];
    }

    /**
     * Période en cours d'une entreprise selon son rythme, et son identifiant.
     *
     * @return array{period: string, cycle: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    public static function currentPeriod(Company $company): array
    {
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        if (self::cycleOn($company, $today) === PlatformInvoice::WEEKLY) {
            $start = $today->startOfWeek(CarbonImmutable::MONDAY);

            return ['period' => $start->isoFormat('GGGG-[W]WW'), 'cycle' => PlatformInvoice::WEEKLY, 'start' => $start, 'end' => $start->addDays(6)];
        }

        return ['period' => $today->format('Y-m'), 'cycle' => PlatformInvoice::MONTHLY, 'start' => $today->startOfMonth(), 'end' => $today->endOfMonth()->startOfDay()];
    }

    /**
     * Rythme de facturation d'un jour : le rythme actuel depuis sa date de début, l'autre avant.
     */
    public static function cycleOn(Company $company, CarbonImmutable $day): string
    {
        $cycle = $company->billing_cycle ?: PlatformInvoice::MONTHLY;
        $from = $company->billing_cycle_from;
        if ($from === null || $day->toDateString() >= $from->toDateString()) {
            return $cycle;
        }

        return $cycle === PlatformInvoice::WEEKLY ? PlatformInvoice::MONTHLY : PlatformInvoice::WEEKLY;
    }

    /**
     * Changement de rythme, à partir du lundi suivant : la période en cours finit sur l'ancien rythme, la suivante
     * commence sur le nouveau, sans jour facturé deux fois. Un changement pas encore commencé peut être annulé.
     */
    public function setCycle(Company $company, string $cycle): Company
    {
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $from = $company->billing_cycle_from;
        $pending = $from !== null && $from->toDateString() > $today->toDateString();

        if ($cycle === ($company->billing_cycle ?: PlatformInvoice::MONTHLY)) {
            return $company;
        }
        if ($pending) {
            // Retour au rythme d'avant, avant que le changement commence
            $company->forceFill(['billing_cycle' => $cycle, 'billing_cycle_from' => null])->save();

            return $company;
        }
        // Un seul changement à la fois : les jours avant le précédent doivent avoir été facturés
        if ($from !== null && $from->toDateString() > $today->subDays(35)->toDateString()) {
            throw ValidationException::withMessages(['billing_cycle' => 'Le rythme a changé il y a moins de cinq semaines. Réessayez à partir du '.$from->addDays(36)->format('d/m/Y').'.']);
        }

        $company->forceFill(['billing_cycle' => $cycle, 'billing_cycle_from' => $today->next(CarbonImmutable::MONDAY)->toDateString()])->save();

        return $company;
    }

    /**
     * Jours d'une période facturés sur ce rythme : après la création, après l'essai, après la dernière facture,
     * et du bon côté d'un changement de rythme.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function billedDays(Company $company, string $cycle, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $tz = $company->timezone;
        $created = CarbonImmutable::parse($company->created_at)->setTimezone($tz)->startOfDay();
        if ($created->gt($start)) {
            $start = $created;
        }
        if ($company->trial_ends_at) {
            $afterTrial = CarbonImmutable::parse($company->trial_ends_at->toDateString(), $tz)->addDay()->startOfDay();
            if ($afterTrial->gt($start)) {
                $start = $afterTrial;
            }
        }
        // Changement de rythme dans la période : seulement les jours de ce rythme
        if ($company->billing_cycle_from !== null) {
            $switch = CarbonImmutable::parse($company->billing_cycle_from->toDateString(), $tz)->startOfDay();
            if ($cycle === ($company->billing_cycle ?: PlatformInvoice::MONTHLY)) {
                $start = $switch->gt($start) ? $switch : $start;
            } elseif ($switch->lte($end)) {
                $end = $switch->subDay();
            }
        } elseif ($cycle !== ($company->billing_cycle ?: PlatformInvoice::MONTHLY)) {
            return null;
        }
        // Jamais deux fois le même jour sur ce rythme (les deux rythmes se partagent les jours ci-dessus)
        $last = PlatformInvoice::query()->where('company_id', $company->id)->where('cycle', $cycle)
            ->where('status', '!=', PlatformInvoice::VOID)->where('period_end', '<=', $end->toDateString())->max('period_end');
        if ($last !== null) {
            $afterLast = CarbonImmutable::parse($last, $tz)->addDay()->startOfDay();
            if ($afterLast->gt($start)) {
                $start = $afterLast;
            }
        }

        return $start->gt($end) ? null : [$start, $end];
    }

    /**
     * Paiement (total ou partiel) enregistré par le super administrateur.
     *
     * @param  array{amount: int, method: string, reference?: ?string, paid_on: string, notes?: ?string}  $data
     */
    public function recordPayment(PlatformInvoice $invoice, array $data, ?User $actor): PlatformPayment
    {
        if ($invoice->status !== PlatformInvoice::OPEN) {
            throw ValidationException::withMessages(['amount' => 'Cette facture n\'est plus à payer.']);
        }
        if ($data['amount'] > $invoice->balance()) {
            throw ValidationException::withMessages(['amount' => 'Le montant dépasse le reste à payer ('.number_format($invoice->balance(), 0, ',', ' ').' F).']);
        }

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $payment = PlatformPayment::create([
                'platform_invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'],
                'recorded_by' => $actor?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->amount_paid += $data['amount'];
            if ($invoice->amount_paid >= $invoice->total) {
                $invoice->status = PlatformInvoice::PAID;
                $invoice->paid_at = now();
            }
            $invoice->save();

            return $payment;
        });
    }

    public function void(PlatformInvoice $invoice, ?string $reason): void
    {
        if ($invoice->status !== PlatformInvoice::OPEN || $invoice->amount_paid > 0) {
            throw ValidationException::withMessages(['invoice' => 'Seule une facture à payer, sans paiement, peut être annulée.']);
        }

        $invoice->update(['status' => PlatformInvoice::VOID, 'notes' => $reason]);
    }

    /**
     * Situation de paiement de l'entreprise : à jour, échéance passée, alerte, restreinte.
     *
     * @return array{state: string, amount_due: int, overdue_amount: int, days_late: int, oldest_due_at: ?string}
     */
    public function state(Company $company): array
    {
        $today = now($company->timezone)->toDateString();
        $open = PlatformInvoice::query()->where('company_id', $company->id)->unpaid()->orderBy('due_at')->get();
        $overdue = $open->filter(fn (PlatformInvoice $i) => $i->due_at->toDateString() < $today);
        $daysLate = $overdue->isEmpty() ? 0 : (int) $overdue->first()->due_at->diffInDays($today);

        return [
            'state' => $this->stateFor($daysLate, $overdue->isNotEmpty()),
            'amount_due' => (int) $open->sum(fn (PlatformInvoice $i) => $i->balance()),
            'overdue_amount' => (int) $overdue->sum(fn (PlatformInvoice $i) => $i->balance()),
            'days_late' => $daysLate,
            'oldest_due_at' => $overdue->first()?->due_at->toDateString(),
        ];
    }

    /**
     * Plus de nouvelles courses : une facture impayée depuis le délai de restriction.
     */
    public function isRestricted(Company $company): bool
    {
        $limit = now($company->timezone)->subDays((int) config('platform.billing.restrict_after_days'))->toDateString();

        return PlatformInvoice::query()->where('company_id', $company->id)->unpaid()->where('due_at', '<=', $limit)->exists();
    }

    /**
     * Rappels du jour (échéance, alerte, restriction), une seule fois par étape et par facture.
     *
     * @return int rappels envoyés
     */
    public function remind(): int
    {
        $sent = 0;

        PlatformInvoice::query()->unpaid()->with('company')->orderBy('id')->each(function (PlatformInvoice $invoice) use (&$sent) {
            $today = now($invoice->company->timezone)->toDateString();
            if ($invoice->due_at->toDateString() > $today) {
                return;
            }

            $late = (int) $invoice->due_at->diffInDays($today);
            $stage = match (true) {
                $late >= (int) config('platform.billing.restrict_after_days') => self::RESTRICTED,
                $late >= (int) config('platform.billing.warning_after_days') => self::WARNING,
                default => self::DUE,
            };
            $order = ['issued' => 0, self::DUE => 1, self::WARNING => 2, self::RESTRICTED => 3];

            if (($order[$invoice->last_reminder] ?? 0) < $order[$stage]) {
                $invoice->update(['last_reminder' => $stage]);
                $this->notify($invoice->company, $invoice, $stage);
                $sent++;
            }
        });

        return $sent;
    }

    /**
     * Chiffres de la console : revenu mensuel récurrent, facturé, encaissé, reste dû.
     *
     * @return array<string, int>
     */
    public function totals(string $period): array
    {
        $companies = Company::query()->whereNotNull('plan_id')->where('status', CompanyStatus::Active->value)->with('plan')->get();
        $invoices = PlatformInvoice::query()->where('status', '!=', PlatformInvoice::VOID);
        [$y, $m] = explode('-', $period);

        return [
            // Abonnements en cours hors essai
            'mrr' => (int) $companies->reject(fn (Company $c) => $c->onTrial())->sum(fn (Company $c) => $c->plan->price_monthly),
            'subscribed' => $companies->count(),
            'on_trial' => $companies->filter(fn (Company $c) => $c->onTrial())->count(),
            // Factures du mois et des semaines qui y commencent
            'invoiced' => (int) (clone $invoices)->whereYear('period_start', $y)->whereMonth('period_start', $m)->sum('total'),
            'collected' => (int) PlatformPayment::query()->whereYear('paid_on', $y)->whereMonth('paid_on', $m)->sum('amount'),
            'outstanding' => (int) PlatformInvoice::query()->unpaid()->sum(DB::raw('total - amount_paid')),
            'overdue_invoices' => PlatformInvoice::query()->unpaid()->where('due_at', '<', now()->toDateString())->count(),
        ];
    }

    private function stateFor(int $daysLate, bool $overdue): string
    {
        return match (true) {
            ! $overdue => self::OK,
            $daysLate >= (int) config('platform.billing.restrict_after_days') => self::RESTRICTED,
            $daysLate >= (int) config('platform.billing.warning_after_days') => self::WARNING,
            default => self::DUE,
        };
    }

    // Numéro lisible et unique : FAC-202611-0001
    private function nextNumber(string $period): string
    {
        $prefix = config('platform.billing.invoice_prefix').'-'.str_replace('-', '', $period).'-';
        $last = PlatformInvoice::query()->where('number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('number')->value('number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function notify(Company $company, PlatformInvoice $invoice, string $stage): void
    {
        $admins = User::query()->where('company_id', $company->id)->where('status', UserStatus::Active->value)
            ->role(Role::Admin->value)->get();

        Notification::send($admins, new PlatformBillingNotice($invoice, $stage));
    }
}
