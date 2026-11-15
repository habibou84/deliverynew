<?php

namespace App\Services\Ai;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AiRecharge;
use App\Models\AiUsage;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\JibiatAiNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Jibiat IA en points, compris dans la formule.
 *
 * Chaque formule donne des points d'IA par mois (ce que l'entreprise voit) et un plafond de coût réel (repère interne
 * de Jibiat, jamais montré). Chaque action de l'IA brûle des points : commande WhatsApp écrite (1 point par défaut),
 * photo d'une commande (2 points par défaut). Avant chaque appel, on vérifie : IA allumée (console), entreprise pas coupée,
 * points restants, plafond de coût pas atteint. Sinon, pas d'IA : Jibiat continue avec ses règles habituelles. Les
 * points repartent à zéro chaque mois. Alertes à 80 % et 100 % des points, et à la pause au plafond.
 *
 * Ancien abonnement à part (avant les points) : plus possible d'en prendre ; ceux en cours sont encore facturés jusqu'à
 * leur arrêt et donnent au moins le volume de leur ancien niveau en points.
 */
class JibiatAi
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const STOPPED = 'stopped';

    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    /** Fonctions de l'IA ; « points » : réglage du prix en points ; « available » : déjà en service */
    public const FEATURES = [
        'messages' => ['label' => 'Commandes WhatsApp écrites', 'points' => 'points_message', 'available' => true],
        'photos' => ['label' => 'Photos de commande sur WhatsApp', 'points' => 'points_photo', 'available' => true],
    ];

    /** Anciens niveaux de l'abonnement à part (facturation des abonnements encore en cours) */
    public const TIER_DEFAULTS = [
        'essentiel' => ['name' => 'IA Essentiel', 'price' => 15000, 'orders' => 1500, 'cost_cap' => 12000],
        'pro' => ['name' => 'IA Pro', 'price' => 45000, 'orders' => 5000, 'cost_cap' => 35000],
        'entreprise' => ['name' => 'IA Entreprise', 'price' => 120000, 'orders' => 15000, 'cost_cap' => 95000],
    ];

    public const SETTING_DEFAULTS = ['enabled' => 1, 'points_message' => 1, 'points_photo' => 2, 'usd_rate' => 600, 'yearly_months' => 10, 'pack_points' => 500, 'pack_price' => 5000];

    /** Prix de Claude en dollars par million de jetons (entrée, sortie), selon la famille du modèle */
    private const MODEL_PRICES = ['haiku' => [0.10, 0.50], 'sonnet' => [2.0, 10.0], 'opus' => [4.0, 20.0], 'fable' => [10.0, 50.0], 'mythos' => [10.0, 50.0]];

    /**
     * Repli côté serveur quand le modèle décline pour raison de politique (arguments nommés de l'appel à Claude) :
     * pas proposé sur Haiku.
     *
     * @return array<string, mixed>
     */
    public static function fallbackArgs(string $model): array
    {
        return str_contains($model, 'haiku') ? [] : ['betas' => ['server-side-fallback-2026-07-01'], 'fallbacks' => 'default'];
    }

    /** @return array{enabled: int, points_message: int, points_photo: int, usd_rate: int, yearly_months: int, pack_points: int, pack_price: int} */
    public function settings(): array
    {
        $stored = json_decode((string) PlatformSetting::get('ai.settings'), true) ?: [];

        return array_map('intval', [...self::SETTING_DEFAULTS, ...array_intersect_key($stored, self::SETTING_DEFAULTS)]);
    }

    public function updateSettings(array $values): array
    {
        $values = array_map(fn ($v) => is_bool($v) ? (int) $v : $v, $values);
        PlatformSetting::put('ai.settings', json_encode(array_map('intval', [...$this->settings(), ...array_intersect_key($values, self::SETTING_DEFAULTS)])));

        return $this->settings();
    }

    /** Interrupteur général de la console */
    public function enabled(): bool
    {
        return (bool) $this->settings()['enabled'];
    }

    /** Mois en cours (fuseau de l'entreprise), au format AAAA-MM */
    public function month(Company $company, ?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->setTimezone($this->tz($company))->format('Y-m');
    }

    /** Points d'une action */
    public function pointsFor(string $feature): int
    {
        $key = self::FEATURES[$feature]['points'] ?? null;

        return $key ? max(0, $this->settings()[$key]) : 0;
    }

    /**
     * Prix de chaque action en points (affichage)
     *
     * @return list<array{key: string, label: string, points: int, available: bool}>
     */
    public function prices(): array
    {
        return collect(self::FEATURES)->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label'], 'points' => $this->pointsFor($key), 'available' => $f['available']])->values()->all();
    }

    /** Pack de points vendu en plus (prépayé) */
    public function pack(): array
    {
        $s = $this->settings();

        return ['points' => max(0, $s['pack_points']), 'price' => max(0, $s['pack_price'])];
    }

    /**
     * Points du mois : ceux de la formule (au moins le volume d'un ancien abonnement à part en cours), plus les packs
     * achetés et payés ce mois-ci.
     */
    public function monthlyPoints(Company $company): int
    {
        $points = (int) ($company->loadMissing('plan')->plan?->ai_points ?? 0);
        $legacy = $this->legacyTier($company);
        $points = $legacy !== null ? max($points, $legacy['orders']) : $points;

        return $points + $this->packPoints($company);
    }

    /** Points des packs payés pour le mois en cours */
    public function packPoints(Company $company): int
    {
        return (int) PlatformInvoice::query()->paidPurchases(PlatformInvoice::AI_POINTS)->where('company_id', $company->id)
            ->where('period', $this->month($company))->get()->sum(fn (PlatformInvoice $i) => (int) ($i->purchase['quantity'] ?? 0));
    }

    public function pointsUsed(Company $company, ?string $month = null): int
    {
        return (int) AiUsage::query()->where('company_id', $company->id)->where('month', $month ?? $this->month($company))->sum('points');
    }

    public function pointsLeft(Company $company): int
    {
        return max(0, $this->monthlyPoints($company) - $this->pointsUsed($company));
    }

    /** Plafond de coût réel du mois (F), repère interne ; null : pas de plafond */
    public function costCap(Company $company): ?int
    {
        $cap = $company->loadMissing('plan')->plan?->ai_cost_cap;
        $legacy = $this->legacyTier($company);
        if ($legacy !== null) {
            return $cap === null ? $legacy['cost_cap'] : max((int) $cap, $legacy['cost_cap']);
        }

        return $cap === null ? null : (int) $cap;
    }

    /** L'IA est en service pour l'entreprise : allumée, pas coupée, des points dans sa formule */
    public function isOn(Company $company): bool
    {
        return $this->enabled() && ! $company->ai_disabled && $this->monthlyPoints($company) > 0;
    }

    /** Plafond de coût atteint ce mois-ci : pause jusqu'au mois suivant */
    public function isPaused(Company $company): bool
    {
        return $company->ai_paused_month === $this->month($company);
    }

    /** Une action de l'IA peut servir maintenant (à vérifier avant chaque appel) */
    public function allows(Company $company, string $feature): bool
    {
        return isset(self::FEATURES[$feature]) && $this->isOn($company) && ! $this->isPaused($company)
            && $this->pointsLeft($company) >= $this->pointsFor($feature);
    }

    /**
     * Compte un appel à l'IA (jetons, coût réel en francs, points) ; alertes à 80 % et 100 % des points ; au plafond
     * de coût, pause jusqu'au mois suivant.
     */
    public function record(Company $company, string $feature, string $model, int $inputTokens, int $outputTokens, int $voiceSeconds = 0): void
    {
        $month = $this->month($company);
        $cost = $this->costMillis($model, $inputTokens, $outputTokens);
        $usage = AiUsage::query()->firstOrCreate(['company_id' => $company->id, 'month' => $month, 'feature' => $feature]);
        AiUsage::query()->whereKey($usage->id)->update([
            'calls' => DB::raw('calls + 1'),
            'points' => DB::raw('points + '.$this->pointsFor($feature)),
            'input_tokens' => DB::raw('input_tokens + '.max(0, $inputTokens)),
            'output_tokens' => DB::raw('output_tokens + '.max(0, $outputTokens)),
            'voice_seconds' => DB::raw('voice_seconds + '.max(0, $voiceSeconds)),
            'cost_millis' => DB::raw('cost_millis + '.$cost),
            'updated_at' => now(),
        ]);

        $cap = $this->costCap($company);
        if ($cap !== null && ! $this->isPaused($company) && $this->costThisMonth($company) >= $cap) {
            $company->forceFill(['ai_paused_month' => $month])->save();
            $this->alert($company, 'paused');

            return;
        }

        $points = $this->monthlyPoints($company);
        $used = $this->pointsUsed($company);
        foreach ([100 => 'points_100', 80 => 'points_80'] as $percent => $stage) {
            if ($points > 0 && $used * 100 >= $points * $percent) {
                $this->alert($company, $stage, ['used' => $used, 'points' => $points]);
                break;
            }
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

    /** Une ligne pour décrire la formule (site, inscription), null sans points */
    public function planLine(?Plan $plan): ?string
    {
        $points = (int) ($plan?->ai_points ?? 0);

        return $points > 0 ? 'Jibiat IA : '.number_format($points, 0, ',', ' ').' points par mois' : null;
    }

    /** Ancien abonnement à part : on ne peut plus en prendre (Jibiat IA vient en points avec la formule) */
    public function startTrial(Company $company, string $tier): Company
    {
        throw new BusinessRuleException('Jibiat IA est comprise dans les formules, en points : choisissez une formule avec des points d\'IA.');
    }

    public function subscribe(Company $company, string $tier, string $period): Company
    {
        return $this->startTrial($company, $tier);
    }

    /** Arrêt d'un ancien abonnement à part : en fin de mois (ou de l'année payée) */
    public function stop(Company $company): Company
    {
        if ($this->legacyTier($company) === null) {
            throw new BusinessRuleException('Jibiat IA est comprise dans votre formule.');
        }
        $end = $company->ai_period === self::YEARLY && $company->ai_paid_until
            ? CarbonImmutable::parse($company->ai_paid_until->toDateString(), $this->tz($company))->endOfDay()
            : CarbonImmutable::now($this->tz($company))->endOfMonth();
        $company->forceFill(['ai_stop_at' => $end->utc()])->save();

        return $company;
    }

    public function resume(Company $company): Company
    {
        if ($company->ai_stop_at === null || $this->legacyTier($company) === null) {
            throw new BusinessRuleException('Aucun arrêt à annuler.');
        }
        $company->forceFill(['ai_stop_at' => null])->save();

        return $company;
    }

    /**
     * Sur la facture de la plateforme : seulement les anciens abonnements à part encore en cours, et les anciennes
     * recharges pas encore facturées. Les points sont dans le prix de la formule.
     *
     * @return array{amount: int, lines: list<array<string, mixed>>, recharge_ids: list<int>}
     */
    public function billable(Company $company, CarbonImmutable $first, CarbonImmutable $last, string $cycle = PlatformInvoice::MONTHLY): array
    {
        $tz = $this->tz($company);
        $lines = [];
        $tier = $company->ai_tier ? (self::TIER_DEFAULTS[$company->ai_tier] ?? null) : null;
        if ($tier !== null && $company->ai_started_at !== null && in_array($company->ai_status, [self::ACTIVE, self::STOPPED], true)) {
            $tier = [...$tier, 'price' => $this->legacyPrice($company->ai_tier)];
            $started = $company->ai_started_at->setTimezone($tz)->startOfDay()->toImmutable();
            $stop = $company->ai_stop_at?->setTimezone($tz)->startOfDay()->toImmutable();
            if ($company->ai_period === self::YEARLY) {
                $months = $this->settings()['yearly_months'];
                for ($n = 0; $started->addYears($n) <= $last; $n++) {
                    $anniversary = $started->addYears($n);
                    if ($anniversary >= $first->startOfDay() && ($stop === null || $anniversary <= $stop)) {
                        $lines[] = ['kind' => 'subscription', 'label' => "{$tier['name']} · à l'année ({$months} mois payés sur 12)", 'amount' => $tier['price'] * $months];
                    }
                }
            } else {
                $from = max($first->startOfDay(), $started);
                $to = $stop ? min($last->startOfDay(), $stop) : $last->startOfDay();
                $days = $to < $from ? 0 : (int) $from->diffInDays($to) + 1;
                if ($days > 0) {
                    $perDay = $cycle === PlatformInvoice::WEEKLY ? $tier['price'] * 12 / 52 / 7 : $tier['price'] / $first->daysInMonth;
                    $lines[] = ['kind' => 'subscription', 'label' => $tier['name'], 'days' => $days, 'price' => $tier['price'], 'amount' => (int) round($days * $perDay)];
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
     * Revue quotidienne des anciens abonnements à part : essais terminés, arrêts demandés atteints, année payée
     * renouvelée.
     *
     * @return array{trials_ended: int, stopped: int, alerts: int}
     */
    public function review(): array
    {
        $result = ['trials_ended' => 0, 'stopped' => 0, 'alerts' => 0];
        Company::query()->whereIn('ai_status', [self::TRIAL, self::ACTIVE])->each(function (Company $company) use (&$result) {
            if ($company->ai_status === self::TRIAL) {
                if ($company->ai_trial_ends_at === null || $company->ai_trial_ends_at->isPast()) {
                    $company->forceFill(['ai_status' => self::EXPIRED])->save();
                    $result['trials_ended']++;
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
        });

        return $result;
    }

    /** État pour le bloc Jibiat IA de « Mon abonnement » */
    public function state(Company $company): array
    {
        $month = $this->month($company);
        $points = $this->monthlyPoints($company);
        $used = $this->pointsUsed($company, $month);
        $legacy = $this->legacyTier($company);

        return [
            'enabled' => $this->enabled(),
            'on' => $this->isOn($company),
            'disabled' => (bool) $company->ai_disabled,
            'paused' => $this->isPaused($company),
            'month' => $month,
            'points' => ['monthly' => $points, 'used' => $used, 'left' => max(0, $points - $used)],
            'prices' => $this->prices(),
            'usage' => AiUsage::query()->where('company_id', $company->id)->where('month', $month)->get(['feature', 'calls', 'points'])
                ->map(fn (AiUsage $u) => ['feature' => $u->feature, 'label' => self::FEATURES[$u->feature]['label'] ?? $u->feature, 'calls' => $u->calls, 'points' => $u->points])->values(),
            'plan' => $company->plan ? ['name' => $company->plan->name] : null,
            // Formules proposées avec des points d'IA (pour une entreprise qui n'en a pas)
            'plans_with_ai' => Plan::query()->where('is_active', true)->where('ai_points', '>', 0)->orderBy('position')->get()
                ->map(fn (Plan $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price_monthly, 'points' => $p->ai_points])->values(),
            // Ancien abonnement à part encore en cours
            'legacy' => $legacy ? [
                'name' => $legacy['name'], 'status' => $company->ai_status, 'period' => $company->ai_period,
                'stop_at' => $company->ai_stop_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Console : chaque entreprise avec des points (ou qui a utilisé l'IA ce mois-ci) : points, coût réel, plafond.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overview(): Collection
    {
        $used = AiUsage::query()->where('month', now()->format('Y-m'))->distinct()->pluck('company_id');
        $withAi = Plan::query()->where('ai_points', '>', 0)->pluck('id');

        return Company::query()->with('plan')
            ->where(fn ($q) => $q->whereIn('id', $used)->orWhereIn('plan_id', $withAi)->orWhereIn('ai_status', [self::TRIAL, self::ACTIVE]))
            ->orderBy('name')->get()
            ->map(function (Company $company) {
                $points = $this->monthlyPoints($company);
                $usedPoints = $this->pointsUsed($company);

                return [
                    'id' => $company->id, 'name' => $company->name, 'slug' => $company->slug,
                    'plan' => $company->plan?->name,
                    'on' => $this->isOn($company), 'disabled' => (bool) $company->ai_disabled, 'paused' => $this->isPaused($company),
                    'points' => $points, 'used' => $usedPoints,
                    'percent' => $points > 0 ? (int) floor($usedPoints * 100 / $points) : null,
                    'cost' => $this->costThisMonth($company), 'cost_cap' => $this->costCap($company),
                    'legacy' => $this->legacyTier($company)['name'] ?? null,
                ];
            });
    }

    /** Couper ou rallumer l'IA pour une entreprise (console) */
    public function setDisabled(Company $company, bool $disabled): Company
    {
        $company->forceFill(['ai_disabled' => $disabled])->save();

        return $company;
    }

    /** Ancien abonnement à part en cours (essai ou abonnement, arrêt pas atteint), ou null */
    private function legacyTier(Company $company): ?array
    {
        $tier = $company->ai_tier ? (self::TIER_DEFAULTS[$company->ai_tier] ?? null) : null;
        if ($tier === null || ($company->ai_stop_at !== null && $company->ai_stop_at->isPast())) {
            return null;
        }
        $running = match ($company->ai_status) {
            self::TRIAL => $company->ai_trial_ends_at?->isFuture() ?? false,
            self::ACTIVE => true,
            default => false,
        };

        return $running ? $tier : null;
    }

    /** Prix d'un ancien niveau (réglé autrefois dans la console) */
    private function legacyPrice(string $tier): int
    {
        $stored = json_decode((string) PlatformSetting::get('ai.tiers'), true) ?: [];

        return (int) ($stored[$tier]['price'] ?? self::TIER_DEFAULTS[$tier]['price']);
    }

    /** Fuseau de l'entreprise (celui d'Abidjan si une fiche toute neuve ne l'a pas encore relu) */
    private function tz(Company $company): string
    {
        return $company->timezone ?: (string) config('app.timezone', 'Africa/Abidjan');
    }

    /** Prévient les administrateurs, une fois par mois et par étape ; true si envoyé */
    private function alert(Company $company, string $stage, array $data = []): bool
    {
        $month = $this->month($company);
        $alerts = $company->ai_alerts ?? [];
        if (($alerts['month'] ?? null) !== $month) {
            $alerts = ['month' => $month, 'sent' => []];
        }
        if (in_array($stage, $alerts['sent'], true)) {
            return false;
        }
        $alerts['sent'][] = $stage;
        $company->forceFill(['ai_alerts' => $alerts])->save();

        $admins = User::query()->where('company_id', $company->id)->where('status', UserStatus::Active->value)->role(Role::Admin->value)->get();
        Notification::send($admins, new JibiatAiNotice($stage, $data));

        return true;
    }
}
