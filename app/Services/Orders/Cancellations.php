<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\CancellationRequest;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Jibiat\Socle\Contract\Contract;

/**
 * Garde-fous des annulations : motif obligatoire (liste de l'e-commerçant ou de l'entreprise), limite du jour pour
 * l'équipe au-delà de laquelle un administrateur valide, alertes (beaucoup d'annulations en une heure par une même
 * personne, e-commerçant qui annule trop), point des annulations par e-commerçant, par personne et par motif.
 */
class Cancellations
{
    /** Règles par défaut de l'entreprise (Courses › Annulations › Règles) ; null = coupé */
    public const DEFAULT_RULES = [
        // Annulations ou refus par jour pour une personne de l'équipe (hors administrateurs), ensuite validation
        'staff_daily_limit' => 5,
        // Alerte : plus de N annulations par une même personne en une heure
        'hourly_alert' => 10,
        // Alerte : e-commerçant à plus de N % d'annulations sur 7 jours (au moins MIN_ORDERS courses)
        'merchant_rate_alert' => 15,
        'weekly_report' => true,
    ];

    /** En dessous, le taux d'annulation d'un e-commerçant ne veut rien dire */
    public const MIN_ORDERS = 10;

    public function __construct(private readonly OrderWorkflow $workflow) {}

    /** @return array<string, string> */
    public static function reasons(bool $merchant): array
    {
        return $merchant ? Contract::CANCEL_REASONS_MERCHANT : Contract::CANCEL_REASONS_COMPANY;
    }

    public static function label(?string $code, ?string $type): ?string
    {
        return $code === null ? null : (self::reasons($type === 'merchant')[$code] ?? $code);
    }

    /** @return array{staff_daily_limit: ?int, hourly_alert: ?int, merchant_rate_alert: ?int, weekly_report: bool} */
    public static function rules(Company $company): array
    {
        return [...self::DEFAULT_RULES, ...($company->cancel_rules ?? [])];
    }

    public static function isAdmin(User $user): bool
    {
        return $user->hasRole(Role::Admin->value) || $user->hasRole(Role::SuperAdmin->value);
    }

    /**
     * Motif obligatoire : un code de la liste du demandeur ; « Autre » demande une précision. Un texte seul
     * (ancienne version de Jibiat Marchand) vaut « Autre ».
     *
     * @return array{code: string, note: ?string}
     */
    public static function reason(User $actor, ?string $code, ?string $note): array
    {
        $merchant = $actor->merchant_id !== null;
        $note = filled($note) ? trim((string) $note) : null;
        $code = filled($code) ? $code : ($note !== null ? 'other' : null);

        if ($code === null) {
            throw new BusinessRuleException('Indiquez pourquoi vous annulez la course.', 'cancel_reason_code');
        }
        if (! array_key_exists($code, self::reasons($merchant))) {
            throw new BusinessRuleException('Motif d\'annulation inconnu.', 'cancel_reason_code');
        }
        if ($code === 'other' && $note === null) {
            throw new BusinessRuleException('Précisez le motif de l\'annulation.', 'cancel_reason');
        }

        return ['code' => $code, 'note' => $note];
    }

    /** Annulations et refus faits aujourd'hui par cette personne de l'équipe */
    public function todayCount(User $user): int
    {
        return Order::query()->where('cancelled_by_id', $user->id)->where('cancelled_by_type', 'company')
            ->where('cancelled_at', '>=', today())->count();
    }

    /** @return array{count: int, limit: ?int, needs_approval: bool} */
    public function quota(User $user): array
    {
        $limit = self::isAdmin($user) || $user->merchant_id !== null ? null : self::rules($user->company)['staff_daily_limit'];
        $count = $user->merchant_id !== null ? 0 : $this->todayCount($user);

        return ['count' => $count, 'limit' => $limit, 'needs_approval' => $limit !== null && $count >= $limit];
    }

    /**
     * Annulation ou refus demandé par une personne : motif vérifié, puis soit fait tout de suite, soit (équipe
     * au-delà de sa limite) mis en demande à valider. Renvoie la course annulée ou la demande créée.
     *
     * @param  array<string, mixed>  $context
     */
    public function cancel(User $actor, Order $order, OrderStatus $to, array $context): Order|CancellationRequest
    {
        // Ni droit d'annuler, ni course encore annulable : l'erreur habituelle du workflow, avant de parler de motif
        $mayCancel = $actor->merchant_id !== null ? $actor->merchant_id === $order->merchant_id : $actor->can(Permission::OrdersDispatch->value);
        if (! $mayCancel || ! $order->status->canTransitionTo($to)) {
            return $this->workflow->transition($actor, $order, $to, $context);
        }

        $reason = self::reason($actor, $context['cancel_reason_code'] ?? null, $context['cancel_reason'] ?? $context['note'] ?? null);

        if ($actor->merchant_id === null && $this->quota($actor)['needs_approval']) {
            return $this->request($actor, $order, $to, $reason);
        }

        $order = $this->workflow->transition($actor, $order, $to, [
            ...$context,
            'cancel_reason_code' => $reason['code'],
            'cancel_reason' => $reason['note'],
            'note' => self::note($actor, $reason['code'], $reason['note']),
        ]);
        $this->afterCancel($actor);

        return $order;
    }

    /** Note du journal de la course : « Motif · précision » */
    public static function note(User $actor, string $code, ?string $note): string
    {
        $label = self::reasons($actor->merchant_id !== null)[$code] ?? $code;

        return $note !== null && $code !== 'other' ? "{$label} · {$note}" : ($note ?? $label);
    }

    /** @param  array{code: string, note: ?string}  $reason */
    private function request(User $actor, Order $order, OrderStatus $to, array $reason): CancellationRequest
    {
        // Mêmes droits qu'une annulation directe : on ne demande pas ce qu'on ne pourrait pas faire
        if (! $actor->can(Permission::OrdersDispatch->value) || ! $order->status->canTransitionTo($to)) {
            throw new BusinessRuleException('Cette course ne peut plus être annulée.', 'status');
        }
        if (CancellationRequest::query()->where('order_id', $order->id)->where('status', CancellationRequest::PENDING)->exists()) {
            throw new BusinessRuleException('Une demande d\'annulation attend déjà la décision d\'un administrateur.', 'status');
        }

        $request = CancellationRequest::create([
            'company_id' => $order->company_id, 'order_id' => $order->id, 'to_status' => $to, 'requested_by' => $actor->id,
            'reason_code' => $reason['code'], 'note' => $reason['note'],
        ]);

        $this->alertAdmins($order->company_id, $order, 'cancellation_request',
            "⏳ Annulation à valider · {$order->tracking_code}",
            "{$actor->name} demande d'annuler la course : ".self::note($actor, $reason['code'], $reason['note']).'.',
            ['href' => '/admin/courses/annulations']);

        return $request;
    }

    /** Décision d'un administrateur sur une demande */
    public function decide(User $admin, CancellationRequest $request, bool $approve, ?string $note = null): CancellationRequest
    {
        if (! self::isAdmin($admin)) {
            throw new BusinessRuleException('Seul un administrateur peut valider une annulation.', 'status');
        }
        if ($request->status !== CancellationRequest::PENDING) {
            throw new BusinessRuleException('Cette demande a déjà été traitée.', 'status');
        }

        return DB::transaction(function () use ($admin, $request, $approve, $note) {
            $order = $request->order()->lockForUpdate()->firstOrFail();
            $status = ! $approve ? CancellationRequest::REFUSED
                : ($order->status->canTransitionTo($request->to_status) ? CancellationRequest::APPROVED : CancellationRequest::EXPIRED);

            if ($status === CancellationRequest::APPROVED) {
                $requester = $request->requester;
                // Faite au nom de la personne qui l'a demandée, validée par l'administrateur (journal)
                $this->workflow->transition($requester, $order, $request->to_status, [
                    'cancel_reason_code' => $request->reason_code,
                    'cancel_reason' => $request->note,
                    'note' => self::note($requester, $request->reason_code, $request->note)." (validée par {$admin->name})",
                ]);
            }

            $request->forceFill(['status' => $status, 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            $requester = $request->requester;
            if ($requester !== null && $requester->id !== $admin->id) {
                $requester->notify(new OrderAlert($order, 'cancellation_decided', match ($status) {
                    CancellationRequest::APPROVED => "✅ Annulation validée · {$order->tracking_code}",
                    CancellationRequest::REFUSED => "❌ Annulation refusée · {$order->tracking_code}",
                    default => "Annulation sans objet · {$order->tracking_code}",
                }, match ($status) {
                    CancellationRequest::APPROVED => "{$admin->name} a validé l'annulation.",
                    CancellationRequest::REFUSED => "{$admin->name} a refusé : la course continue.".($note ? " « {$note} »" : ''),
                    default => 'La course a avancé entre-temps : elle ne peut plus être annulée.',
                }, ['href' => "/admin/courses/{$order->id}"]));
            }

            return $request->fresh(['order', 'requester', 'decider']);
        });
    }

    /** Après une annulation par l'équipe : alerte si la même personne en fait beaucoup en une heure (une fois par heure) */
    private function afterCancel(User $actor): void
    {
        if ($actor->merchant_id !== null || $actor->company === null) {
            return;
        }
        $threshold = self::rules($actor->company)['hourly_alert'];
        if (! $threshold) {
            return;
        }
        $count = Order::query()->where('cancelled_by_id', $actor->id)->where('cancelled_by_type', 'company')
            ->where('cancelled_at', '>=', now()->subHour())->count();
        if ($count <= $threshold || ! Cache::add("cancellations:hourly:{$actor->id}", 1, now()->addHour())) {
            return;
        }
        $last = Order::query()->where('cancelled_by_id', $actor->id)->latest('cancelled_at')->first();
        $this->alertAdmins($actor->company_id, $last, 'cancellations_burst',
            "🚨 {$count} annulations en une heure",
            "{$actor->name} a annulé ou refusé {$count} courses depuis une heure. Vérifiez dans Courses › Annulations.",
            ['href' => '/admin/courses/annulations', 'severity' => 'alert'], except: $actor->id);
    }

    /**
     * Chaque jour : e-commerçants dont le taux d'annulation sur 7 jours dépasse le seuil (une alerte par jour).
     *
     * @return int nombre d'e-commerçants signalés
     */
    public function alertMerchantRates(Company $company): int
    {
        $threshold = self::rules($company)['merchant_rate_alert'];
        if (! $threshold) {
            return 0;
        }
        $flagged = collect($this->byMerchant($company, now()->subDays(7), now()))
            ->filter(fn ($row) => $row['created'] >= self::MIN_ORDERS && $row['rate'] > $threshold);
        if ($flagged->isEmpty() || ! Cache::add("cancellations:merchants:{$company->id}:".today()->toDateString(), 1, now()->addDay())) {
            return 0;
        }
        $order = Order::forCompany($company->id)->whereIn('merchant_id', $flagged->pluck('merchant_id'))->latest('id')->first();
        $this->alertAdmins($company->id, $order, 'cancellations_merchants',
            '⚠️ E-commerçant'.($flagged->count() > 1 ? 's' : '').' qui annulent beaucoup',
            $flagged->take(3)->map(fn ($r) => "{$r['name']} : ".self::percent($r['rate']).' ('.$r['cancelled'].' sur '.$r['created'].')')->implode(' · ').' sur 7 jours.',
            ['href' => '/admin/courses/annulations']);

        return $flagged->count();
    }

    /** @param  array<string, mixed>  $extra */
    private function alertAdmins(int $companyId, ?Order $order, string $kind, string $title, string $body, array $extra, ?int $except = null): void
    {
        if ($order === null) {
            return;
        }
        $admins = User::forCompany($companyId)->whereNull('merchant_id')->where('status', 'active')
            ->role(Role::Admin->value)->when($except, fn ($q) => $q->whereKeyNot($except))->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new OrderAlert($order, $kind, $title, $body, $extra));
        }
    }

    /**
     * Point des annulations d'une période : totaux, par e-commerçant, par personne de l'équipe, par motif.
     *
     * @return array<string, mixed>
     */
    public function report(Company $company, Carbon $from, Carbon $to): array
    {
        $cancelled = Order::forCompany($company->id)
            ->whereIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        $created = Order::forCompany($company->id)->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();
        $total = (clone $cancelled)->count();
        $byType = (clone $cancelled)->selectRaw("COALESCE(cancelled_by_type, 'company') AS t, COUNT(*) AS n")->groupBy('t')->pluck('n', 't');
        $rejected = (clone $cancelled)->where('status', OrderStatus::Rejected->value)->count();

        $reasons = (clone $cancelled)->selectRaw("COALESCE(cancelled_by_type, 'company') AS t, cancel_reason_code AS code, COUNT(*) AS n")
            ->groupBy('t', 'code')->get()
            ->map(fn ($r) => ['by' => $r->t, 'code' => $r->code, 'label' => self::label($r->code, $r->t) ?? 'Sans motif', 'count' => (int) $r->n])
            ->sortByDesc('count')->values();

        $merchants = $this->byMerchant($company, $from, $to);
        $rules = self::rules($company);
        $staff = $this->byStaff($company, $from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'cancelled' => $total,
                'created' => $created,
                'rate' => $created ? round($total * 100 / $created, 1) : null,
                'by_merchants' => (int) ($byType['merchant'] ?? 0),
                'by_company' => (int) ($byType['company'] ?? 0),
                'rejected' => $rejected,
                'flagged' => collect($merchants)->where('flagged', true)->count() + collect($staff)->where('flagged', true)->count(),
            ],
            'merchants' => $merchants,
            'staff' => $staff,
            'reasons' => $reasons->all(),
            'rules' => $rules,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function byMerchant(Company $company, Carbon $from, Carbon $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $created = Order::forCompany($company->id)->whereBetween('created_at', $range)
            ->selectRaw('merchant_id, COUNT(*) AS n')->groupBy('merchant_id')->pluck('n', 'merchant_id');
        $rows = Order::forCompany($company->id)->where('cancelled_by_type', 'merchant')
            ->where('status', OrderStatus::Cancelled->value)->whereBetween('cancelled_at', $range)
            ->selectRaw('merchant_id, cancel_reason_code AS code, COUNT(*) AS n')->groupBy('merchant_id', 'code')->get()->groupBy('merchant_id');
        $names = Merchant::forCompany($company->id)->whereIn('id', $rows->keys())->pluck('business_name', 'id');
        $threshold = self::rules($company)['merchant_rate_alert'];

        return $rows->map(function (Collection $codes, $merchantId) use ($created, $names, $threshold) {
            $count = (int) $codes->sum('n');
            $base = max((int) ($created[$merchantId] ?? 0), $count);
            $rate = round($count * 100 / $base, 1);

            return [
                'merchant_id' => (int) $merchantId,
                'name' => $names[$merchantId] ?? 'E-commerçant',
                'cancelled' => $count,
                'created' => $base,
                'rate' => $rate,
                'top_reason' => self::label($codes->sortByDesc('n')->first()->code, 'merchant') ?? 'Sans motif',
                'flagged' => $threshold !== null && $base >= self::MIN_ORDERS && $rate > $threshold,
            ];
        })->sortByDesc('cancelled')->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function byStaff(Company $company, Carbon $from, Carbon $to): array
    {
        $rows = Order::forCompany($company->id)->where('cancelled_by_type', 'company')->whereNotNull('cancelled_by_id')
            ->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('cancelled_by_id, status, cancel_reason_code AS code, COUNT(*) AS n')
            ->groupBy('cancelled_by_id', 'status', 'code')->get()->groupBy('cancelled_by_id');
        $users = User::withoutGlobalScopes()->whereIn('id', $rows->keys())->get()->keyBy('id');
        $limit = self::rules($company)['staff_daily_limit'];
        $days = max(1, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->endOfDay()) + 1);

        return $rows->map(function (Collection $lines, $userId) use ($users, $limit, $days) {
            $user = $users[$userId] ?? null;
            $cancelled = (int) $lines->where('status', OrderStatus::Cancelled)->sum('n');
            $rejected = (int) $lines->where('status', OrderStatus::Rejected)->sum('n');

            return [
                'user_id' => (int) $userId,
                'name' => $user?->name ?? 'Ancien compte',
                'role' => $user?->primaryRole()?->label(),
                'cancelled' => $cancelled,
                'rejected' => $rejected,
                'top_reason' => self::label($lines->groupBy('code')->map->sum('n')->sortDesc()->keys()->first() ?: null, 'company') ?? 'Sans motif',
                // Au-dessus de sa limite du jour en moyenne sur la période
                'flagged' => $user !== null && ! self::isAdmin($user) && $limit !== null && ($cancelled + $rejected) / $days > $limit,
            ];
        })->sortByDesc(fn ($r) => $r['cancelled'] + $r['rejected'])->values()->all();
    }

    /** Ligne du rapport de la semaine : « 12 annulations (3,1 %), dont 8 par les e-commerçants » */
    public function weeklyLine(Company $company, Carbon $from, Carbon $to): ?string
    {
        if (! self::rules($company)['weekly_report']) {
            return null;
        }
        $totals = $this->report($company, $from, $to)['totals'];
        if ($totals['cancelled'] === 0) {
            return null;
        }

        return "{$totals['cancelled']} annulation(s)".($totals['rate'] !== null ? ' ('.self::percent($totals['rate']).')' : '')
            .", dont {$totals['by_merchants']} par les e-commerçants et {$totals['by_company']} par l'équipe";
    }

    public static function percent(float|int|null $rate): string
    {
        return $rate === null ? '—' : str_replace('.', ',', (string) $rate).' %';
    }

    /**
     * Annulation telle que la voient l'e-commerçant et Jibiat Marchand (contrat 1.9) : qui, rôle, motif, précision.
     *
     * @return array<string, mixed>|null
     */
    public static function describe(Order $order): ?array
    {
        if (! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Rejected], true) || $order->cancelled_at === null) {
            return null;
        }
        $type = $order->cancelled_by_type ?? 'company';
        $user = $order->cancelled_by_id ? $order->cancelledBy()->withoutGlobalScopes()->first() : null;
        $code = $order->cancel_reason_code;

        return [
            'by' => $type,
            'name' => $user ? (strtok($user->name, ' ') ?: $user->name) : null,
            'role' => $type === 'company' ? $user?->primaryRole()?->label() : null,
            'reason' => $code,
            'reason_label' => self::label($code, $type),
            'note' => $order->cancel_reason,
            'rejected' => $order->status === OrderStatus::Rejected,
            'cancelled_at' => $order->cancelled_at->toIso8601String(),
        ];
    }
}
