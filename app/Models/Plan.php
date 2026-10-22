<?php

namespace App\Models;

use App\Enums\PlatformFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formule d'abonnement de la plateforme (forfait mensuel, courses incluses, dépassement,
 * livreurs, modules).
 */
class Plan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price_monthly',
        'included_orders',
        'overage_per_order',
        'max_couriers',
        'features',
        'is_active',
        'position',
        'pricing',
        'percent_rate',
        'percent_min',
        'percent_max',
        'volume_threshold',
        'volume_rate',
        'cap_amount',
        'min_invoice',
        'extra_courier_price',
    ];

    public const FLAT = 'flat';

    public const PERCENT = 'percent';

    protected function casts(): array
    {
        return [
            'price_monthly' => 'integer',
            'included_orders' => 'integer',
            'overage_per_order' => 'integer',
            'max_couriers' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
            'percent_rate' => 'integer',
            'percent_min' => 'integer',
            'percent_max' => 'integer',
            'volume_threshold' => 'integer',
            'volume_rate' => 'integer',
            'cap_amount' => 'integer',
            'min_invoice' => 'integer',
            'extra_courier_price' => 'integer',
        ];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * Forfait d'une semaine : le prix du mois ramené à la semaine (12 mois = 52 semaines), arrondi à 5 F.
     */
    public function weeklyPrice(): int
    {
        return (int) (round($this->price_monthly * 12 / 52 / 5) * 5);
    }

    /**
     * Courses incluses dans une semaine (null : illimitées).
     */
    public function weeklyIncludedOrders(): ?int
    {
        return $this->included_orders === null ? null : (int) floor($this->included_orders * 12 / 52);
    }

    /**
     * Livreurs en plus de la limite possibles (facturés chacun au prix du mois, au prorata des jours).
     */
    public function allowsExtraCouriers(): bool
    {
        return $this->max_couriers !== null && $this->extra_courier_price !== null;
    }

    public function isPercent(): bool
    {
        return $this->pricing === self::PERCENT;
    }

    /**
     * Part d'une course (formule à la livraison) : taux des frais de livraison, entre le minimum et le maximum.
     */
    public function chargeFor(int $fee, int $rate): int
    {
        $charge = (int) round($fee * $rate / 10000);
        $charge = max($charge, (int) $this->percent_min);

        return $this->percent_max !== null ? min($charge, $this->percent_max) : $charge;
    }

    /**
     * Prix de la formule à la livraison en une phrase (site, inscription), null pour un forfait.
     */
    public function percentSummary(): ?string
    {
        if (! $this->isPercent()) {
            return null;
        }
        $f = fn (int $n) => number_format($n, 0, ',', ' ').' F';
        $text = rtrim(rtrim(number_format(((int) $this->percent_rate) / 100, 2, ',', ' '), '0'), ',').' % des frais de chaque course';
        if ($this->percent_min || $this->percent_max !== null) {
            $text .= $this->percent_max !== null ? " (de {$f((int) $this->percent_min)} à {$f($this->percent_max)})" : " (au moins {$f((int) $this->percent_min)})";
        }
        if ($this->volume_threshold !== null && $this->volume_rate !== null) {
            $text .= ', '.rtrim(rtrim(number_format($this->volume_rate / 100, 2, ',', ' '), '0'), ',')." % au-delà de {$this->volume_threshold} courses";
        }

        return $this->cap_amount !== null ? $text.", plafonné à {$f($this->cap_amount)} par mois" : $text;
    }

    public function includes(PlatformFeature $feature): bool
    {
        return in_array($feature->value, $this->features ?? [], true);
    }

    /**
     * Formules proposées au départ (modifiables ensuite dans la console).
     *
     * @return list<array<string, mixed>>
     */
    public static function suggestions(): array
    {
        return [
            [
                'name' => 'À la livraison', 'description' => 'Sans forfait : 1 % des frais de chaque course prise en charge, plafonné au prix du Pro.',
                'pricing' => self::PERCENT, 'price_monthly' => 0, 'included_orders' => null, 'overage_per_order' => 0, 'max_couriers' => 5,
                'percent_rate' => 100, 'percent_min' => 10, 'percent_max' => 60, 'volume_threshold' => 1000, 'volume_rate' => 75,
                'cap_amount' => 60000, 'min_invoice' => 1000, 'features' => [], 'position' => 0,
            ],
            [
                'name' => 'Essentiel', 'description' => 'Pour démarrer : courses, livreurs, caisse et suivi.',
                'price_monthly' => 25000, 'included_orders' => 500, 'overage_per_order' => 30, 'max_couriers' => 5,
                'features' => [], 'position' => 1,
            ],
            [
                'name' => 'Pro', 'description' => 'Tous les modules pour une entreprise qui grandit.',
                'price_monthly' => 60000, 'included_orders' => 2000, 'overage_per_order' => 25, 'max_couriers' => 20,
                'features' => PlatformFeature::values(), 'position' => 2,
            ],
            [
                'name' => 'Entreprise', 'description' => 'Gros volumes, livreurs illimités.',
                'price_monthly' => 150000, 'included_orders' => 6000, 'overage_per_order' => 20, 'max_couriers' => null,
                'features' => PlatformFeature::values(), 'position' => 3,
            ],
        ];
    }
}
