<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'merchant_id', 'sku', 'name', 'description', 'price', 'weight_kg', 'low_stock_threshold', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'weight_kg' => 'float',
            'low_stock_threshold' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->merchant_id !== null ? $query->where('merchant_id', $user->merchant_id) : $query;
    }

    /**
     * Quantité disponible tous emplacements confondus (niveaux chargés).
     */
    public function available(): int
    {
        return (int) $this->levels->sum(fn (StockLevel $l) => $l->available());
    }

    /**
     * Stock bas : seuil défini et disponible inférieur ou égal.
     */
    public function isLow(?int $available = null): bool
    {
        return $this->low_stock_threshold !== null && ($available ?? $this->available()) <= $this->low_stock_threshold;
    }
}
