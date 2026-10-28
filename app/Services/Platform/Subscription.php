<?php

namespace App\Services\Platform;

use App\Enums\PlatformFeature;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CourierCount;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Abonnement d'une entreprise à la plateforme : formule, essai, consommation de la période de facturation
 * (courses prises en charge, livreurs) et limites.
 */
class Subscription
{
    public function __construct(private readonly Billing $billing) {}

    /**
     * Courses prises en charge (ramassées ou préparées à l'entrepôt) dans la période de facturation en cours.
     */
    public function ordersThisPeriod(Company $company): int
    {
        $current = Billing::currentPeriod($company);

        return Billing::takenInCharge($company, $current['start'], $current['end']->addDay());
    }

    /**
     * Livreurs actifs (comptes non suspendus).
     */
    public function activeCouriers(Company $company): int
    {
        return User::query()->where('company_id', $company->id)
            ->where('status', UserStatus::Active->value)
            ->role(Role::Courier->value)
            ->count();
    }

    /**
     * Refuse un livreur actif de plus quand la formule est au maximum, sauf si elle permet des livreurs en plus
     * (facturés).
     *
     * @throws ValidationException
     */
    public function ensureCourierRoom(Company $company, string $field = 'role'): void
    {
        $plan = $company->loadMissing('plan')->plan;
        $max = $plan?->max_couriers;

        if ($max !== null && ! $plan->allowsExtraCouriers() && $this->activeCouriers($company) >= $max) {
            throw ValidationException::withMessages([
                $field => "Votre formule « {$company->plan->name} » permet {$max} livreur(s) actif(s) : suspendez un livreur ou passez à une formule supérieure.",
            ]);
        }
    }

    /**
     * Relève le nombre de livreurs actifs du jour (le plus grand de la journée est gardé).
     */
    public function recordCouriers(Company $company): void
    {
        $day = CarbonImmutable::now($company->timezone)->toDateString();
        $count = $this->activeCouriers($company);
        $row = CourierCount::query()->firstOrNew(['company_id' => $company->id, 'day' => $day]);
        if (! $row->exists || $count > $row->couriers) {
            $row->couriers = $count;
            $row->save();
        }
    }

    /**
     * État de l'abonnement, pour la console et la page « Mon abonnement ».
     *
     * @return array<string, mixed>
     */
    public function summary(Company $company): array
    {
        $plan = $company->loadMissing('plan')->plan;
        $current = Billing::currentPeriod($company);
        $weekly = $current['cycle'] === PlatformInvoice::WEEKLY;
        $orders = $this->ordersThisPeriod($company);
        $included = $plan ? ($weekly ? $plan->weeklyIncludedOrders() : $plan->included_orders) : null;
        $base = $plan ? ($weekly ? $plan->weeklyPrice() : $plan->price_monthly) : 0;
        $overageOrders = $plan && $included !== null ? max(0, $orders - $included) : 0;
        $overageAmount = $overageOrders * ($plan?->overage_per_order ?? 0);
        // Formule à la livraison : part des courses déjà prises en charge dans la période
        $deliveries = null;
        if ($plan?->isPercent()) {
            $deliveries = $this->billing->amounts($plan, $company, $current['start'], $current['end']->addDay(), $weekly ? 12 / 52 : 1.0, $current['cycle']);
            [$base, $included, $overageOrders, $overageAmount] = [0, null, 0, 0];
        }
        // Livreurs en plus de la formule depuis le début de la période
        $extra = $plan ? $this->billing->extraCouriers($plan, $company, $current['start'], CarbonImmutable::now($company->timezone)->startOfDay(), $current['cycle']) : ['days' => 0, 'amount' => 0];
        // Options « Applications à votre nom » de la période entière
        $apps = $plan ? app(AppOptions::class)->billable($company, $plan, $current['start'], $current['end'], $current['cycle']) : ['amount' => 0];
        $couriers = $this->activeCouriers($company);
        $from = $company->billing_cycle_from;

        return [
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_monthly' => $plan->price_monthly,
                'price_weekly' => $plan->weeklyPrice(),
                'included_orders' => $plan->included_orders,
                'included_orders_weekly' => $plan->weeklyIncludedOrders(),
                'overage_per_order' => $plan->overage_per_order,
                'max_couriers' => $plan->max_couriers,
                'extra_courier_price' => $plan->allowsExtraCouriers() ? $plan->extra_courier_price : null,
                'pricing' => $plan->pricing ?: Plan::FLAT,
                'percent_rate' => $plan->percent_rate,
                'percent_min' => (int) $plan->percent_min,
                'percent_max' => $plan->percent_max,
                'volume_threshold' => $plan->volume_threshold,
                'volume_rate' => $plan->volume_rate,
                'cap_amount' => $plan->cap_amount,
                'cap_amount_weekly' => $plan->cap_amount !== null ? (int) round($plan->cap_amount * 12 / 52) : null,
                'min_invoice' => $plan->min_invoice,
            ] : null,
            'trial_ends_at' => $company->trial_ends_at?->toDateString(),
            'on_trial' => $company->onTrial(),
            'month' => CarbonImmutable::now($company->timezone)->format('Y-m'),
            // Rythme de facturation : celui de la période en cours, et le prochain s'il change
            'billing_period' => [
                'cycle' => $current['cycle'],
                'period_start' => $current['start']->toDateString(),
                'period_end' => $current['end']->toDateString(),
                'next_cycle' => $from !== null && $from->toDateString() > CarbonImmutable::now($company->timezone)->toDateString() ? $company->billing_cycle : null,
                'next_cycle_from' => $from?->toDateString(),
            ],
            'usage' => [
                'orders' => $orders,
                'included_orders' => $included,
                'overage_orders' => $overageOrders,
                'couriers' => $couriers,
                'max_couriers' => $plan?->max_couriers,
                'extra_couriers' => $plan?->max_couriers !== null ? max(0, $couriers - $plan->max_couriers) : 0,
                'extra_courier_days' => $extra['days'],
            ],
            // Montant estimé de la période (forfait + dépassement, ou part des courses) ; rien pendant l'essai
            'estimate' => [
                'base' => $base,
                'overage' => $overageAmount,
                'deliveries' => $deliveries['deliveries_amount'] ?? 0,
                'cap_applied' => $deliveries['cap_applied'] ?? false,
                'extra_couriers' => $extra['amount'],
                'app_options' => $apps['amount'],
                'total' => $company->onTrial() ? 0 : $base + $overageAmount + ($deliveries['deliveries_amount'] ?? 0) + $extra['amount'] + $apps['amount'],
            ],
            // Prix de chaque formule sur les 30 derniers jours, pour comparer
            'comparison' => $this->billing->comparison($company),
            'features' => array_map(fn (PlatformFeature $f) => [
                'key' => $f->value,
                'label' => $f->label(),
                'description' => $f->description(),
                'included' => $company->hasFeature($f),
            ], PlatformFeature::cases()),
            // Applications à votre nom : incluses, en option ou non disponibles, et l'option demandée
            'apps' => app(AppOptions::class)->state($company),
        ];
    }
}
