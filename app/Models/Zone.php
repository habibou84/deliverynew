<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'parent_id', 'place_code', 'name', 'aliases', 'city', 'is_shipping', 'shipping_fee_estimate', 'is_active', 'is_pickup', 'sort_order', 'legacy_price'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_pickup' => 'boolean',
            'is_shipping' => 'boolean',
            'shipping_fee_estimate' => 'integer',
            'sort_order' => 'integer',
            'aliases' => 'array',
            'legacy_price' => 'integer',
            'legacy_price_done_at' => 'datetime',
            'place_checked_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Groupes de prix « depuis partout » où se trouve ce lieu (un par grille au plus) */
    public function pricingGroups(): BelongsToMany
    {
        return $this->belongsToMany(PricingGroup::class, 'pricing_group_zone');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Lieux où l'entreprise ramasse : actifs, ramassage permis, et pour un quartier aussi dans sa commune.
     */
    public function scopePickup(Builder $query): Builder
    {
        return $query->active()->where('is_pickup', true)
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas('parent', fn ($p) => $p->where('is_pickup', true)));
    }

    /** L'entreprise ramasse-t-elle ici ? (un quartier suit sa commune quand elle est coupée) */
    public function acceptsPickup(): bool
    {
        if (! $this->is_active || ! $this->is_pickup) {
            return false;
        }

        // Commune pas chargée : on s'en tient au lieu lui-même (les commandes, elles, vérifient la commune)
        return $this->parent_id === null || ! $this->relationLoaded('parent') || ($this->parent?->is_pickup ?? true);
    }

    /**
     * Nom complet : « Cocody › Angré » pour un quartier.
     */
    public function fullName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }
}
