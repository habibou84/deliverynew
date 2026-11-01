<?php

namespace App\Services\Ai;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AiRecharge;
use App\Models\AiUsage;
use App\Models\Company;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\JibiatAiNotice;
use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Jibiat IA : abonnement à part, en plus de la formule de l'entreprise. Trois niveaux selon le nombre de courses du
 * mois (prix, volume, limites et plafond de coût réglés dans la console), essai gratuit une fois, paiement au mois ou
 * à l'année (mois offerts), arrêt en fin de mois. Chaque appel à l'IA est compté (jetons, coût réel) ; au plafond de
 * coût du mois, l'IA se met en pause et Jibiat continue avec ses règles habituelles. Facturé sur la facture de la
 * plateforme : abonnement, courses au-delà du volume, recharges.
 */
class JibiatAi
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const STOPPED = 'stopped';

    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    /** Fonctions de l'IA ; « available » : déjà en service (les autres arrivent) */
    public const FEATURES = [
        'messages' => ['label' => 'Messages WhatsApp lus en courses', 'available' => true],
        'photos_excel' => ['label' => 'Photos et fichiers Excel lus en courses', 'available' => false],
        'merchant_answers' => ['label' => 'Assistant qui répond aux e-commerçants', 'available' => false],
        'risk' => ['label' => 'Courses à risque et alertes de caisse', 'available' => false],
        'morning' => ['label' => 'Résumé du matin sur WhatsApp', 'available' => false],
        'dispatch' => ['label' => 'Assistant de dispatch', 'available' => false],
        'voice' => ['label' => 'Notes vocales', 'available' => false],
        'forecast' => ['label' => 'Prévision des volumes du lendemain', 'available' => false],
        'rules' => ['label' => 'Règles du planning suggérées', 'available' => false],
        'questions' => ['label' => 'Questions sur vos chiffres, en français', 'available' => false],
        'onboarding' => ['label' => 'Suivi personnalisé du démarrage', 'available' => true],
    ];

    private const BASE = ['messages', 'photos_excel', 'merchant_answers', 'risk', 'morning', 'dispatch', 'voice'];

    public const TIER_DEFAULTS = [
        'essentiel' => ['name' => 'IA Essentiel', 'price' => 15000, 'orders' => 1500, 'dispatch_per_day' => 10, 'voice_minutes' => 100, 'cost_cap' => 12000, 'active' => true,
            'features' => self::BASE],
        'pro' => ['name' => 'IA Pro', 'price' => 45000, 'orders' => 5000, 'dispatch_per_day' => 24, 'voice_minutes' => 300, 'cost_cap' => 35000, 'active' => true,
            'features' => [...self::BASE, 'forecast', 'rules', 'questions']],
        'entreprise' => ['name' => 'IA Entreprise', 'price' => 120000, 'orders' => 15000, 'dispatch_per_day' => 24, 'voice_minutes' => 1000, 'cost_cap' => 95000, 'active' => true,
            'features' => [...self::BASE, 'forecast', 'rules', 'questions', 'onboarding']],
    ];

    public const SETTING_DEFAULTS = ['overage_per_order' => 8, 'trial_days' => 14, 'yearly_months' => 10, 'usd_rate' => 600];

    /** Prix de Claude en dollars par million de jetons (entrée, sortie), selon la famille du modèle */
    private const MODEL_PRICES = ['haiku' => [0.10, 0.50], 'sonnet' => [2.0, 10.0], 'opus' => [4.0, 20.0], 'fable' => [10.0, 50.0], 'mythos' => [10.0, 50.0]];

    public const RECHARGES = [5000, 10000, 20000];

    /** @return array<string, array<string, mixed>> */
    public function tiers(): array
    {
        $stored = json_decode((string) PlatformSetting::get('ai.tiers'), true) ?: [];
        $tiers = [];
        foreach (self::TIER_DEFAULTS as $key => $default) {
            $tiers[$key] = [...$default, ...array_intersect_key($stored[$key] ?? [], array_flip(['price', 'orders', 'dispatch_per_day', 'voice_minutes', 'cost_cap', 'active']))];
        }

        return $tiers;
    }

    /** @param  array<string, array<string, mixed>>  $values */
    public function updateTiers(array $values): array
    {
        $current = $this->tiers();
        $store = [];
        foreach ($current as $key => $tier) {
            $new = $values[$key] ?? [];
            $store[$key] = [
                'price' => (int) ($new['price'] ?? $tier['price']),
                'orders' => (int) ($new['orders'] ?? $tier['orders']),
                'dispatch_per_day' => (int) ($new['dispatch_per_day'] ?? $tier['dispatch_per_day']),
                'voice_minutes' => (int) ($new['voice_minutes'] ?? $tier['voice_minutes']),
                'cost_cap' => (int) ($new['cost_cap'] ?? $tier['cost_cap']),
                'active' => (bool) ($new['active'] ?? $tier['active']),
            ];
        }
        PlatformSetting::put('ai.tiers', json_encode($store));

        return $this->tiers();
    }

    /** @return array{overage_per_order: int, trial_days: int, yearly_months: int, usd_rate: int} */
    public function settings(): array
    {
        $stored = json_decode((string) PlatformSetting::get('ai.settings'), true) ?: [];

        return array_map('intval', [...self::SETTING_DEFAULTS, ...array_intersect_key($stored, self::SETTING_DEFAULTS)]);
    }

    public function updateSettings(array $values): array
    {
        PlatformSetting::put('ai.settings', json_encode(array_map('intval', [...$this->settings(), ...array_intersect_key($values, self::SETTING_DEFAULTS)])));

        return $this->settings();
    }

    public function tier(Company $company): ?array
    {
        return $company->ai_tier ? ($this->tiers()[$company->ai_tier] ?? null) : null;
    }

    /** Mois en cours (fuseau de l'entreprise), au format AAAA-MM */
    public function month(Company $company, ?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->setTimezone($this->tz($company))->format('Y-m');
    }

    /** L'IA tourne pour l'entreprise : essai en cours ou abonnement, arrêt demandé pas encore atteint */
    public function isOn(Company $company): bool
    {
        if ($company->ai_stop_at !== null && $company->ai_stop_at->isPast()) {
            return false;
        }

        return match ($company->ai_status) {
            self::TRIAL => $company->ai_trial_ends_at?->isFuture() ?? false,
            self::ACTIVE => true,
            default => false,
        };
    }

    public function isPaused(Company $company): bool
    {
        return $company->ai_paused_month === $this->month($company);
    }

    /** Une fonction de l'IA peut servir maintenant (abonnement, niveau, pas de pause au plafond) */
    public function allows(Company $company, string $feature): bool
    {
        $tier = $this->tier($company);

        return $tier !== null && $this->isOn($company) && ! $this->isPaused($company) && in_array($feature, $tier['features'], true);
    }

    /**
     * Compte un appel à l'IA (jetons, coût réel en francs) ; au plafond du mois, l'IA se met en pause pour
     * l'entreprise et ses administrateurs sont prévenus.
     */
    public function record(Company $company, string $feature, string $model, int $inputTokens, int $outputTokens, int $voiceSeconds = 0): void
    {
        $month = $this->month($company);
        $cost = $this->costMillis($model, $inputTokens, $outputTokens);
        $usage = AiUsage::query()->firstOrCreate(['company_id' => $company->id, 'month' => $month, 'feature' => $feature]);
        AiUsage::query()->whereKey($usage->id)->update([
            'calls' => DB::raw('calls + 1'),
            'input_tokens' => DB::raw('input_tokens + '.max(0, $inputTokens)),
            'output_tokens' => DB::raw('output_tokens + '.max(0, $outputTokens)),
            'voice_seconds' => DB::raw('voice_seconds + '.max(0, $voiceSeconds)),
            'cost_millis' => DB::raw('cost_millis + '.$cost),
            'updated_at' => now(),
        ]);

        if ($this->isOn($company) && ! $this->isPaused($company) && $this->costThisMonth($company) >= $this->costCap($company)) {
            $company->forceFill(['ai_paused_month' => $month])->save();
            $this->alert($company, 'paused', ['tier' => $this->tier($company)['name'] ?? 'Jibiat IA']);
        }
    }

    /** Coût d'un appel en millièmes de franc CFA */
    public function costMillis(string $model, int $inputTokens, int $outputTokens): int
    {
        $prices = self::MODEL_PRICES['sonnet'];
        foreach (self::MODEL_PRICES as $family => $p) {
            if (str_contains(strtolower($model), $family)) {
                $prices = $p;
                break;
            }
        }
        $usd = ($inputTokens * $prices[0] + $outputTokens * $prices[1]) / 1_000_000;

        return (int) round($usd * $this->settings()['usd_rate'] * 1000);
    }

    /** Coût réel de l'IA ce mois-ci, en francs */
    public function costThisMonth(Company $company, ?string $month = null): int
    {
        return (int) round(AiUsage::query()->where('company_id', $company->id)->where('month', $month ?? $this->month($company))->sum('cost_millis') / 1000);
    }

    public function rechargesThisMonth(Company $company, ?string $month = null): int
    {
        return (int) AiRecharge::query()->where('company_id', $company->id)->where('month', $month ?? $this->month($company))->sum('amount');
    }

    /** Plafond de coût du mois : celui du niveau, relevé par les recharges dans la même proportion */
    public function costCap(Company $company, ?string $month = null): int
    {
        $tier = $this->tier($company);
        if ($tier === null) {
            return 0;
        }
        $ratio = $tier['price'] > 0 ? $tier['cost_cap'] / $tier['price'] : 0.8;

        return $tier['cost_cap'] + (int) round($this->rechargesThisMonth($company, $month) * $ratio);
    }

    /** Courses comprises ce mois-ci : celles du niveau, plus celles payées par les recharges */
    public function includedOrders(Company $company, ?string $month = null): int
    {
        $tier = $this->tier($company);
        if ($tier === null) {
            return 0;
        }
        $overage = max(1, $this->settings()['overage_per_order']);

        return $tier['orders'] + intdiv($this->rechargesThisMonth($company, $month), $overage);
    }

    /** Courses prises en charge par l'entreprise pendant le mois */
    public function ordersThisMonth(Company $company, ?string $month = null): int
    {
        [, $start, $end] = Billing::bounds($month ?? $this->month($company), $this->tz($company));

        return Billing::takenInCharge($company, $start, $end->addDay());
    }

    /** Niveau conseillé selon les courses du mois, ramenées au mois entier */
    public function recommended(Company $company): string
    {
        $now = CarbonImmutable::now($this->tz($company));
        $projected = (int) round($this->ordersThisMonth($company) / max(1, $now->day) * $now->daysInMonth);
        foreach ($this->tiers() as $key => $tier) {
            if ($tier['active'] && $projected <= $tier['orders']) {
                return $key;
            }
        }

        return array_key_last($this->tiers());
    }

    public function startTrial(Company $company, string $tier): Company
    {
        $this->assertTier($tier);
        if ($company->ai_trial_started_at !== null) {
            throw new BusinessRuleException('L\'essai gratuit a déjà été utilisé : choisissez un abonnement.');
        }
        if ($this->isOn($company)) {
            throw new BusinessRuleException('Jibiat IA est déjà active.');
        }
        $company->forceFill([
            'ai_tier' => $tier, 'ai_status' => self::TRIAL, 'ai_trial_started_at' => now(),
            'ai_trial_ends_at' => now()->addDays($this->settings()['trial_days']), 'ai_stop_at' => null, 'ai_paused_month' => null,
        ])->save();

        return $company;
    }

    public function subscribe(Company $company, string $tier, string $period): Company
    {
        $this->assertTier($tier);
        if (! in_array($period, [self::MONTHLY, self::YEARLY], true)) {
            throw new BusinessRuleException('Paiement au mois ou à l\'année.');
        }
        $active = $company->ai_status === self::ACTIVE && $this->isOn($company);
        if ($active && $company->ai_period === self::YEARLY && ($tier !== $company->ai_tier || $period !== self::YEARLY)) {
            throw new BusinessRuleException('Abonnement payé à l\'année : pour changer de niveau, contactez l\'équipe Jibiat.');
        }

        $start = $active ? $company->ai_started_at : now();
        $company->forceFill([
            'ai_tier' => $tier, 'ai_status' => self::ACTIVE, 'ai_period' => $period, 'ai_started_at' => $start,
            'ai_stop_at' => null, 'ai_paused_month' => null,
            'ai_paid_until' => $period === self::YEARLY ? CarbonImmutable::parse($start)->setTimezone($this->tz($company))->addYear()->subDay()->toDateString() : null,
        ])->save();

        return $company;
    }

    /** Arrêt : tout de suite pendant l'essai, en fin de mois (ou de l'année payée) pour un abonnement */
    public function stop(Company $company): Company
    {
        if (! $this->isOn($company)) {
            throw new BusinessRuleException('Jibiat IA n\'est pas active.');
        }
        if ($company->ai_status === self::TRIAL) {
            $company->forceFill(['ai_status' => self::STOPPED, 'ai_stop_at' => now()])->save();

            return $company;
        }
        $end = $company->ai_period === self::YEARLY && $company->ai_paid_until
            ? CarbonImmutable::parse($company->ai_paid_until->toDateString(), $this->tz($company))->endOfDay()
            : CarbonImmutable::now($this->tz($company))->endOfMonth();
        $company->forceFill(['ai_stop_at' => $end->utc()])->save();

        return $company;
    }

    /** Annule un arrêt demandé, tant qu'il n'est pas atteint */
    public function resume(Company $company): Company
    {
        if ($company->ai_stop_at === null || ! $this->isOn($company)) {
            throw new BusinessRuleException('Aucun arrêt à annuler.');
        }
        $company->forceFill(['ai_stop_at' => null])->save();

        return $company;
    }

    public function recharge(Company $company, int $amount, ?User $by): AiRecharge
    {
        if (! in_array($amount, self::RECHARGES, true)) {
            throw new BusinessRuleException('Recharge de 5 000, 10 000 ou 20 000 F.');
        }
        if ($company->ai_status !== self::ACTIVE || ! $this->isOn($company)) {
            throw new BusinessRuleException('Les recharges sont réservées à un abonnement Jibiat IA en cours.');
        }
        $month = $this->month($company);
        $recharge = AiRecharge::create(['company_id' => $company->id, 'month' => $month, 'amount' => $amount, 'created_by' => $by?->id]);
        if ($this->isPaused($company) && $this->costThisMonth($company) < $this->costCap($company)) {
            $company->forceFill(['ai_paused_month' => null])->save();
        }

        return $recharge;
    }

    /**
     * À facturer sur une période de la facture de la plateforme : abonnement (jours au mois, ou l'année au jour
     * anniversaire), courses au-delà du volume (facture du mois), recharges pas encore facturées.
     *
     * @return array{amount: int, lines: list<array<string, mixed>>, recharge_ids: list<int>}
     */
    public function billable(Company $company, CarbonImmutable $first, CarbonImmutable $last, string $cycle = PlatformInvoice::MONTHLY): array
    {
        $tz = $this->tz($company);
        $lines = [];
        $tier = $this->tier($company);
        if ($tier !== null && $company->ai_started_at !== null && in_array($company->ai_status, [self::ACTIVE, self::STOPPED], true)) {
            $started = $company->ai_started_at->setTimezone($tz)->startOfDay()->toImmutable();
            $stop = $company->ai_stop_at?->setTimezone($tz)->startOfDay()->toImmutable();
            if ($company->ai_period === self::YEARLY) {
                $months = $this->settings()['yearly_months'];
                for ($n = 0; $started->addYears($n) <= $last; $n++) {
                    $anniversary = $started->addYears($n);
                    if ($anniversary >= $first->startOfDay() && ($stop === null || $anniversary <= $stop)) {
                        $lines[] = ['kind' => 'subscription', 'label' => "Jibiat IA {$this->short($tier)} · à l'année ({$months} mois payés sur 12)", 'amount' => $tier['price'] * $months];
                    }
                }
            } else {
                $from = max($first->startOfDay(), $started);
                $to = $stop ? min($last->startOfDay(), $stop) : $last->startOfDay();
                $days = $to < $from ? 0 : (int) $from->diffInDays($to) + 1;
                if ($days > 0) {
                    $perDay = $cycle === PlatformInvoice::WEEKLY ? $tier['price'] * 12 / 52 / 7 : $tier['price'] / $first->daysInMonth;
                    $lines[] = ['kind' => 'subscription', 'label' => "Jibiat IA {$this->short($tier)}", 'days' => $days, 'price' => $tier['price'], 'amount' => (int) round($days * $perDay)];
                }
            }

            // Courses au-delà du volume : sur la facture du mois entier
            if ($cycle === PlatformInvoice::MONTHLY && $first->day === 1 && $last->isSameDay($last->endOfMonth())) {
                $month = $first->format('Y-m');
                $orders = $this->ordersThisMonth($company, $month);
                $over = max(0, $orders - $this->includedOrders($company, $month));
                if ($over > 0) {
                    $per = $this->settings()['overage_per_order'];
                    $lines[] = ['kind' => 'overage', 'label' => "Jibiat IA · {$over} course(s) au-delà de {$this->includedOrders($company, $month)}", 'orders' => $over, 'price' => $per, 'amount' => $over * $per];
                }
            }
        }

        $recharges = AiRecharge::query()->where('company_id', $company->id)->whereNull('platform_invoice_id')
            ->where('created_at', '<', $last->addDay()->startOfDay()->utc())->get();
        foreach ($recharges as $recharge) {
            $lines[] = ['kind' => 'recharge', 'label' => 'Recharge Jibiat IA du '.$recharge->created_at->setTimezone($tz)->format('d/m/Y'), 'amount' => $recharge->amount];
        }

        return ['amount' => (int) array_sum(array_column($lines, 'amount')), 'lines' => $lines, 'recharge_ids' => $recharges->modelKeys()];
    }

    /**
     * Revue quotidienne : fin d'essai (prévenir 3 jours avant, puis arrêter), arrêts demandés atteints, année
     * payée renouvelée, alertes de volume à 80 % et 100 %.
     *
     * @return array{trials_ended: int, stopped: int, alerts: int}
     */
    public function review(): array
    {
        $result = ['trials_ended' => 0, 'stopped' => 0, 'alerts' => 0];
        Company::query()->whereNotNull('ai_status')->whereIn('ai_status', [self::TRIAL, self::ACTIVE])->each(function (Company $company) use (&$result) {
            $tierName = $this->tier($company)['name'] ?? 'Jibiat IA';
            if ($company->ai_status === self::TRIAL) {
                if ($company->ai_trial_ends_at?->isPast()) {
                    $company->forceFill(['ai_status' => self::EXPIRED])->save();
                    $this->alert($company, 'trial_ended', [], once: false);
                    $result['trials_ended']++;

                    return;
                }
                $days = (int) ceil(now()->diffInHours($company->ai_trial_ends_at) / 24);
                if ($days <= 3 && $this->alert($company, 'trial_ending', ['days' => $days, 'tier' => $tierName])) {
                    $result['alerts']++;
                }

                return;
            }
            if ($company->ai_stop_at !== null && $company->ai_stop_at->isPast()) {
                $company->forceFill(['ai_status' => self::STOPPED])->save();
                $result['stopped']++;

                return;
            }
            if ($company->ai_period === self::YEARLY && $company->ai_paid_until?->isPast()) {
                $company->forceFill(['ai_paid_until' => $company->ai_paid_until->addYear()->toDateString()])->save();
            }
            $included = $this->includedOrders($company);
            $orders = $this->ordersThisMonth($company);
            $data = ['orders' => $orders, 'included' => $included, 'overage' => $this->settings()['overage_per_order'], 'tier' => $tierName];
            foreach ([100 => 'volume_100', 80 => 'volume_80'] as $percent => $stage) {
                if ($included > 0 && $orders * 100 >= $included * $percent) {
                    if ($this->alert($company, $stage, $data)) {
                        $result['alerts']++;
                    }
                    break;
                }
            }
        });

        return $result;
    }

    /** État pour la page Jibiat IA de l'entreprise */
    public function state(Company $company): array
    {
        $tier = $this->tier($company);
        $month = $this->month($company);
        $tiers = $this->tiers();

        return [
            'tiers' => collect($tiers)->filter(fn ($t) => $t['active'])->map(fn ($t, $key) => [
                'key' => $key, 'name' => $t['name'], 'price' => $t['price'], 'orders' => $t['orders'],
                'dispatch_per_day' => $t['dispatch_per_day'], 'voice_minutes' => $t['voice_minutes'],
                'yearly_price' => $t['price'] * $this->settings()['yearly_months'],
                'features' => collect($t['features'])->map(fn ($f) => ['key' => $f, ...self::FEATURES[$f]])->values(),
            ])->values(),
            'settings' => $this->settings(),
            'recharges' => self::RECHARGES,
            'recommended' => $this->recommended($company),
            'plan' => $company->plan ? ['name' => $company->plan->name, 'price' => $company->plan->price_monthly] : null,
            'subscription' => [
                'tier' => $company->ai_tier,
                'tier_name' => $tier['name'] ?? null,
                'status' => $company->ai_status,
                'on' => $this->isOn($company),
                'paused' => $this->isPaused($company),
                'period' => $company->ai_period,
                'trial_used' => $company->ai_trial_started_at !== null,
                'trial_ends_at' => $company->ai_trial_ends_at?->toIso8601String(),
                'trial_days_left' => $company->ai_status === self::TRIAL && $company->ai_trial_ends_at?->isFuture() ? (int) ceil(now()->diffInHours($company->ai_trial_ends_at) / 24) : null,
                'started_at' => $company->ai_started_at?->toIso8601String(),
                'stop_at' => $company->ai_stop_at?->toIso8601String(),
                'paid_until' => $company->ai_paid_until?->toDateString(),
            ],
            'usage' => [
                'month' => $month,
                'orders' => $this->ordersThisMonth($company),
                'included_orders' => $this->includedOrders($company),
                'voice_minutes' => (int) round(AiUsage::query()->where('company_id', $company->id)->where('month', $month)->sum('voice_seconds') / 60),
                'voice_included' => $tier['voice_minutes'] ?? 0,
                'recharges' => $this->rechargesThisMonth($company),
                'features' => AiUsage::query()->where('company_id', $company->id)->where('month', $month)->get(['feature', 'calls'])
                    ->map(fn (AiUsage $u) => ['feature' => $u->feature, 'label' => self::FEATURES[$u->feature]['label'] ?? $u->feature, 'calls' => $u->calls])->values(),
            ],
        ];
    }

    /**
     * Console : chaque entreprise abonnée (ou qui a utilisé l'IA ce mois-ci), ce qu'elle paie et ce que l'IA coûte.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overview(): Collection
    {
        $used = AiUsage::query()->where('month', now()->format('Y-m'))->distinct()->pluck('company_id');

        return Company::query()->where(fn ($q) => $q->whereNotNull('ai_status')->orWhereIn('id', $used))->orderBy('name')->get()
            ->map(function (Company $company) {
                $tz = $this->tz($company);
                $first = CarbonImmutable::now($tz)->startOfMonth();
                $billable = $this->billable($company, $first, $first->endOfMonth()->startOfDay());
                $paid = $billable['amount'];
                $cost = $this->costThisMonth($company);
                $included = $this->includedOrders($company);
                $orders = $this->ordersThisMonth($company);

                return [
                    'id' => $company->id, 'name' => $company->name, 'slug' => $company->slug,
                    'tier' => $company->ai_tier, 'tier_name' => $this->tier($company)['name'] ?? null,
                    'status' => $company->ai_status, 'on' => $this->isOn($company), 'paused' => $this->isPaused($company),
                    'period' => $company->ai_period, 'stop_at' => $company->ai_stop_at?->toIso8601String(),
                    'orders' => $orders, 'included_orders' => $included,
                    'volume_percent' => $included > 0 ? (int) floor($orders * 100 / $included) : null,
                    'paid' => $paid, 'cost' => $cost, 'cost_cap' => $this->costCap($company), 'margin' => $paid - $cost,
                    'next' => $this->nextTier($company),
                ];
            });
    }

    /** Niveau au-dessus, à proposer quand le volume du mois est presque atteint */
    public function nextTier(Company $company): ?string
    {
        $keys = array_keys(array_filter($this->tiers(), fn ($t) => $t['active']));
        $index = array_search($company->ai_tier, $keys, true);

        return $index === false ? null : ($keys[$index + 1] ?? null);
    }

    /** Fuseau de l'entreprise (celui d'Abidjan si une fiche toute neuve ne l'a pas encore relu) */
    private function tz(Company $company): string
    {
        return $company->timezone ?: (string) config('app.timezone', 'Africa/Abidjan');
    }

    private function short(array $tier): string
    {
        return trim(str_replace('IA', '', $tier['name']));
    }

    private function assertTier(string $tier): void
    {
        if (! ($this->tiers()[$tier]['active'] ?? false)) {
            throw new BusinessRuleException('Niveau Jibiat IA inconnu.');
        }
    }

    /** Prévient les administrateurs, une fois par mois et par étape (sauf $once = false) ; true si envoyé */
    private function alert(Company $company, string $stage, array $data = [], bool $once = true): bool
    {
        $month = $this->month($company);
        $alerts = $company->ai_alerts ?? [];
        if (($alerts['month'] ?? null) !== $month) {
            $alerts = ['month' => $month, 'sent' => []];
        }
        if ($once && in_array($stage, $alerts['sent'], true)) {
            return false;
        }
        $alerts['sent'][] = $stage;
        $company->forceFill(['ai_alerts' => $alerts])->save();

        $admins = User::query()->where('company_id', $company->id)->where('status', UserStatus::Active->value)->role(Role::Admin->value)->get();
        Notification::send($admins, new JibiatAiNotice($stage, $data));

        return true;
    }
}
