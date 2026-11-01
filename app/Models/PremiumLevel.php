<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Niveau Premium d'une entreprise : conditions à remplir, avantages de livraison et formule Jibiat Marchand offerte.
 */
class PremiumLevel extends Model
{
    use BelongsToCompany;

    /** Reversement de l'argent encaissé : 2 fois par semaine, chaque jour */
    public const PAYOUT_FREQUENCIES = ['twice_weekly' => '2 fois par semaine', 'daily' => 'Chaque jour'];

    public const DEFAULT_NAMES = [1 => 'Argent', 2 => 'Or', 3 => 'Platine'];

    protected $fillable = [
        'company_id', 'rank', 'name', 'min_delivered', 'min_success_rate', 'min_client_days',
        'delivery_discount_percent', 'return_discount_percent', 'cutoff_extra_minutes', 'priority_pickup', 'payout_frequency',
        'jm_plan', 'jm_free_months', 'jm_discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'min_delivered' => 'integer',
            'min_success_rate' => 'float',
            'min_client_days' => 'integer',
            'delivery_discount_percent' => 'integer',
            'return_discount_percent' => 'integer',
            'cutoff_extra_minutes' => 'integer',
            'priority_pickup' => 'boolean',
            'jm_free_months' => 'integer',
            'jm_discount_percent' => 'integer',
        ];
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'premium_level_id');
    }

    /**
     * Avantages en phrases courtes (écrans et messages).
     *
     * @return list<string>
     */
    public function advantages(): array
    {
        return array_values(array_filter([
            $this->delivery_discount_percent > 0 ? "−{$this->delivery_discount_percent} % sur chaque livraison" : null,
            $this->payout_frequency ? 'Argent reversé '.mb_strtolower(self::PAYOUT_FREQUENCIES[$this->payout_frequency] ?? '') : null,
            $this->priority_pickup ? 'Ramassage en priorité' : null,
            $this->cutoff_extra_minutes > 0 ? 'Livraison du jour : '.self::duration($this->cutoff_extra_minutes).' de plus pour commander' : null,
            $this->return_discount_percent >= 100 ? 'Retours gratuits' : ($this->return_discount_percent > 0 ? "−{$this->return_discount_percent} % sur les frais de retour" : null),
            $this->jm_plan ? 'Jibiat Marchand '.ucfirst($this->jm_plan).' offert'.($this->jm_free_months > 0 ? " {$this->jm_free_months} mois" : '') : null,
        ]));
    }

    private static function duration(int $minutes): string
    {
        return $minutes % 60 === 0 ? ($minutes / 60).' h' : "{$minutes} min";
    }
}
