<?php

namespace App\Services\Premium;

use App\Enums\MerchantStatus;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PremiumCard;
use App\Models\PremiumLevel;
use App\Services\Messaging\Messenger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Niveaux Premium d'une entreprise (Argent, Or, Platine) : l'entreprise choisit les conditions et les avantages de
 * chaque niveau, dans les limites de la console ; un bilan chaque mois place chaque marchand au plus haut niveau dont
 * il remplit les conditions. Avantages appliqués d'eux-mêmes : réduction sur les frais de livraison (tarif), frais de
 * retour réduits, heure limite plus tardive, ramassage et paiement en priorité ; formule Jibiat Marchand offerte avec
 * une carte Membre Premium.
 */
class PremiumLevels
{
    /** @var array<int, ?PremiumLevel> niveau du marchand, gardé pendant la requête */
    private array $cache = [];

    public function __construct(private readonly PremiumCards $cards, private readonly Messenger $messenger) {}

    /**
     * @return Collection<int, PremiumLevel>
     */
    public function levels(Company $company): Collection
    {
        return PremiumLevel::forCompany($company->id)->orderBy('rank')->get();
    }

    /** Niveau du marchand, ou null */
    public function levelFor(?Merchant $merchant): ?PremiumLevel
    {
        if ($merchant === null || $merchant->premium_level_id === null) {
            return null;
        }

        return $this->cache[$merchant->id] ??= PremiumLevel::withoutGlobalScopes()->find($merchant->premium_level_id);
    }

    /**
     * Enregistre les niveaux de l'entreprise (dans l'ordre, du plus bas au plus haut), dans les limites de la console.
     * Les niveaux en trop sont supprimés et leurs marchands n'ont plus de niveau.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, PremiumLevel>
     */
    public function save(Company $company, array $rows): Collection
    {
        $settings = $this->cards->settings();
        $plans = $this->cards->plans();
        if (count($rows) > $settings['max_levels']) {
            throw new BusinessRuleException("Au plus {$settings['max_levels']} niveau(x).", 'levels');
        }

        foreach ($rows as $i => $row) {
            $label = $row['name'] ?? 'Niveau '.($i + 1);
            if (($row['delivery_discount_percent'] ?? 0) > $settings['max_delivery_discount']) {
                throw new BusinessRuleException("{$label} : la réduction sur la livraison est limitée à {$settings['max_delivery_discount']} %.", "levels.{$i}.delivery_discount_percent");
            }
            $plan = $row['jm_plan'] ?? null;
            if ($plan !== null) {
                $limits = $plans[$plan] ?? null;
                if ($limits === null || ! $limits['allowed']) {
                    throw new BusinessRuleException("{$label} : la formule Jibiat Marchand choisie n'est pas permise.", "levels.{$i}.jm_plan");
                }
                if (($row['jm_free_months'] ?? 0) > $limits['max_months']) {
                    throw new BusinessRuleException("{$label} : au plus {$limits['max_months']} mois offerts pour cette formule.", "levels.{$i}.jm_free_months");
                }
                if (($row['jm_discount_percent'] ?? 0) > $limits['max_discount']) {
                    throw new BusinessRuleException("{$label} : au plus {$limits['max_discount']} % de réduction ensuite pour cette formule.", "levels.{$i}.jm_discount_percent");
                }
            }
            if ($i > 0 && ($row['min_delivered'] ?? 0) < ($rows[$i - 1]['min_delivered'] ?? 0)) {
                throw new BusinessRuleException("{$label} : un niveau plus haut demande au moins autant de courses que le précédent.", "levels.{$i}.min_delivered");
            }
        }

        DB::transaction(function () use ($company, $rows) {
            foreach (array_values($rows) as $i => $row) {
                $level = PremiumLevel::forCompany($company->id)->firstOrNew(['rank' => $i + 1]);
                $level->fill([
                    ...array_intersect_key($row, array_flip((new PremiumLevel)->getFillable())),
                    'company_id' => $company->id,
                    'rank' => $i + 1,
                    'name' => trim((string) ($row['name'] ?? PremiumLevel::DEFAULT_NAMES[$i + 1] ?? 'Niveau '.($i + 1))),
                    'jm_plan' => $row['jm_plan'] ?? null,
                    'jm_free_months' => ($row['jm_plan'] ?? null) ? (int) ($row['jm_free_months'] ?? 0) : 0,
                    'jm_discount_percent' => ($row['jm_plan'] ?? null) ? (int) ($row['jm_discount_percent'] ?? 0) : 0,
                ])->save();
            }
            PremiumLevel::forCompany($company->id)->where('rank', '>', count($rows))->delete();
        });
        $this->cache = [];

        return $this->levels($company);
    }

    /**
     * Bilan : chaque marchand actif va au plus haut niveau dont il remplit les conditions (aucun s'il doit de
     * l'argent depuis trop longtemps). Un marchand qui monte est prévenu ; s'il gagne une formule Jibiat Marchand et
     * n'a pas encore de carte, elle lui est offerte (dans la limite des cartes de l'entreprise).
     *
     * @return array{up: int, down: int, same: int, cards: int}
     */
    public function review(Company $company): array
    {
        $levels = $this->levels($company)->sortByDesc('rank')->values();
        $result = ['up' => 0, 'down' => 0, 'same' => 0, 'cards' => 0];
        $activity = $this->cards->activity($company);
        $debtors = array_flip($this->cards->lateDebtors($company));
        $withCard = PremiumCard::forCompany($company->id)->counted()->pluck('merchant_id')->flip();
        $ranks = $levels->pluck('rank', 'id');

        $merchants = Merchant::forCompany($company->id)->where('status', MerchantStatus::Active->value)->get();
        foreach ($merchants as $merchant) {
            $stats = $this->stats($merchant, $activity[$merchant->id] ?? null);
            $target = isset($debtors[$merchant->id]) ? null : $levels->first(fn (PremiumLevel $l) => $this->meets($stats, $l));
            $before = $merchant->premium_level_id ? (int) ($ranks[$merchant->premium_level_id] ?? 0) : 0;
            $after = $target?->rank ?? 0;

            if ($after === $before && $merchant->premium_level_id === $target?->id) {
                $result['same']++;

                continue;
            }
            $merchant->forceFill(['premium_level_id' => $target?->id, 'premium_level_since' => now()])->save();
            $result[$after > $before ? 'up' : 'down']++;

            if ($target !== null && $after > $before) {
                $this->announce($company, $merchant, $target);
                if ($target->jm_plan && ! isset($withCard[$merchant->id]) && $this->cards->active()
                    && $this->cards->used($company) < $this->cards->quota($company)) {
                    try {
                        $this->cards->offer($company, $merchant, 'automatic', null, $this->formula($target));
                        $result['cards']++;
                    } catch (Throwable $e) {
                        Log::warning('Niveau Premium : carte non offerte', ['merchant' => $merchant->id, 'error' => $e->getMessage()]);
                    }
                }
            }
        }
        $company->forceFill(['premium_reviewed_at' => now()])->save();
        $this->cache = [];

        return $result;
    }

    /**
     * @param  array{delivered: int, failed: int, first_order_at: ?string}|null  $a
     * @return array{delivered: int, success_rate: ?float, client_days: int}
     */
    private function stats(Merchant $merchant, ?array $a): array
    {
        $a ??= ['delivered' => 0, 'failed' => 0, 'first_order_at' => null];
        $closed = $a['delivered'] + $a['failed'];
        $since = CarbonImmutable::parse($merchant->created_at);
        if ($a['first_order_at'] !== null && CarbonImmutable::parse($a['first_order_at'])->lt($since)) {
            $since = CarbonImmutable::parse($a['first_order_at']);
        }

        return [
            'delivered' => $a['delivered'],
            'success_rate' => $closed > 0 ? round($a['delivered'] / $closed, 3) : null,
            'client_days' => (int) $since->diffInDays(now()),
        ];
    }

    /**
     * @param  array{delivered: int, success_rate: ?float, client_days: int}  $stats
     */
    private function meets(array $stats, PremiumLevel $level): bool
    {
        return $stats['delivered'] >= $level->min_delivered
            && $stats['success_rate'] !== null && $stats['success_rate'] >= $level->min_success_rate
            && $stats['client_days'] >= $level->min_client_days;
    }

    /**
     * @return array{plan: string, free_months: int, discount_rate: float}
     */
    public function formula(PremiumLevel $level): array
    {
        return ['plan' => (string) $level->jm_plan, 'free_months' => $level->jm_free_months, 'discount_rate' => round($level->jm_discount_percent / 100, 3)];
    }

    private function announce(Company $company, Merchant $merchant, PremiumLevel $level): void
    {
        $advantages = $level->advantages();
        DB::afterCommit(fn () => $this->messenger->merchantMessage($merchant, 'premium.level', WhatsAppTemplate::PremiumLevelReached, [
            $merchant->business_name,
            $level->name,
            $company->name,
            $advantages !== [] ? implode(', ', $advantages) : 'voir votre application',
        ]));
    }

    /**
     * Réduction Premium sur un tarif de livraison (francs entiers, arrondis au plus proche).
     */
    public function deliveryDiscount(Merchant $merchant, int $price): int
    {
        $percent = $this->levelFor($merchant)?->delivery_discount_percent ?? 0;

        return $percent > 0 ? (int) min($price, round($price * $percent / 100)) : 0;
    }

    /**
     * Heure limite de la livraison du jour pour ce marchand (celle de l'entreprise, plus son avantage), ou null.
     */
    public function cutoffFor(Company $company, ?Merchant $merchant, ?string $date = null): ?string
    {
        $cutoff = $company->cutoffFor($date);
        $extra = $this->levelFor($merchant)?->cutoff_extra_minutes ?? 0;
        if ($cutoff === null || $extra <= 0) {
            return $cutoff;
        }
        $time = CarbonImmutable::createFromFormat('H:i', substr($cutoff, 0, 5))->addMinutes($extra);

        return $time->isSameDay(CarbonImmutable::createFromFormat('H:i', substr($cutoff, 0, 5))) ? $time->format('H:i') : '23:59';
    }

    /**
     * Tableau de bord de l'entreprise : marchands par niveau et réductions accordées ce mois.
     *
     * @return array<int, array{merchants: int, discounts_month: int, orders_month: int}>
     */
    public function summary(Company $company): array
    {
        $counts = Merchant::forCompany($company->id)->whereNotNull('premium_level_id')->groupBy('premium_level_id')
            ->selectRaw('premium_level_id, COUNT(*) AS total')->pluck('total', 'premium_level_id');
        $discounts = Order::forCompany($company->id)->where('orders.premium_discount', '>', 0)->where('orders.created_at', '>=', now()->startOfMonth())
            ->join('merchants', 'merchants.id', '=', 'orders.merchant_id')->whereNotNull('merchants.premium_level_id')
            ->groupBy('merchants.premium_level_id')
            ->selectRaw('merchants.premium_level_id AS level_id, SUM(orders.premium_discount) AS amount, COUNT(*) AS orders')
            ->get()->keyBy('level_id');

        return $this->levels($company)->mapWithKeys(fn (PremiumLevel $l) => [$l->id => [
            'merchants' => (int) ($counts[$l->id] ?? 0),
            'discounts_month' => (int) ($discounts[$l->id]->amount ?? 0),
            'orders_month' => (int) ($discounts[$l->id]->orders ?? 0),
        ]])->all();
    }

    /**
     * Pour l'application du marchand : son niveau, ses avantages, ce qu'il a gagné ce mois, le niveau suivant.
     *
     * @return array<string, mixed>|null
     */
    public function forMerchant(Merchant $merchant): ?array
    {
        $company = Company::findOrFail($merchant->company_id);
        $levels = $this->levels($company);
        if ($levels->isEmpty()) {
            return null;
        }
        $level = $levels->firstWhere('id', $merchant->premium_level_id);
        $next = $levels->first(fn (PremiumLevel $l) => $l->rank > ($level->rank ?? 0));
        $stats = $this->stats($merchant, $this->cards->activity($company, [$merchant->id])[$merchant->id] ?? null);

        return [
            'level' => $level ? ['name' => $level->name, 'rank' => $level->rank, 'advantages' => $level->advantages(), 'since' => $merchant->premium_level_since?->toDateString()] : null,
            'saved_month' => (int) Order::forCompany($company->id)->where('merchant_id', $merchant->id)
                ->where('created_at', '>=', now()->startOfMonth())->sum('premium_discount'),
            'next_review' => now()->startOfMonth()->addMonth()->toDateString(),
            'next' => $next ? [
                'name' => $next->name,
                'advantages' => $next->advantages(),
                'needs' => [
                    'delivered' => ['have' => $stats['delivered'], 'need' => $next->min_delivered],
                    'success_rate' => ['have' => $stats['success_rate'], 'need' => $next->min_success_rate],
                    'client_days' => ['have' => $stats['client_days'], 'need' => $next->min_client_days],
                ],
            ] : null,
            'window_days' => $this->cards->settings()['window_days'],
        ];
    }

    /**
     * Bilan de toutes les entreprises qui ont des niveaux (commande du 1er du mois).
     *
     * @return array<string, array{up: int, down: int, same: int, cards: int}>
     */
    public function reviewAll(): array
    {
        $out = [];
        $ids = PremiumLevel::withoutGlobalScopes()->distinct()->pluck('company_id');
        foreach (Company::query()->whereIn('id', $ids)->get() as $company) {
            if ($company->isActive()) {
                $out[$company->slug] = $this->review($company);
            }
        }

        return $out;
    }
}
