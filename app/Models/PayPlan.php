<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan de rémunération des livreurs : règles (étape, calcul, conditions) qui
 * s'additionnent, options de ramassage et bornes par course. Un plan peut être
 * partagé (attribué à plusieurs livreurs, ou plan par défaut) ou personnel
 * (exception propre à un livreur).
 */
class PayPlan extends Model
{
    use BelongsToCompany;

    public const PICKUP_PER_PARCEL = 'per_parcel';

    public const PICKUP_PER_VISIT = 'per_visit';

    protected $fillable = [
        'company_id', 'courier_id', 'name', 'is_default', 'pickup_mode', 'pickup_extra_parcel_amount', 'min_amount', 'max_amount',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'pickup_extra_parcel_amount' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PayPlanRule::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Livreur dont c'est le plan personnel.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'courier_id');
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(Courier::class);
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->whereNull('courier_id');
    }

    public static function defaultFor(int $companyId): ?self
    {
        return static::forCompany($companyId)->shared()->where('is_default', true)->first();
    }
}
