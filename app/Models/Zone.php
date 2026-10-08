<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    /** @use HasFactory<\Database\Factories\ZoneFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'parent_id', 'name', 'city', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Nom complet : « Cocody › Angré » pour un quartier.
     */
    public function fullName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }
}
