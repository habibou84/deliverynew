<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Groupe de lieux et son prix de livraison « depuis partout » : prix vers ces lieux, d'où que parte le colis, quand
 * aucun trajet particulier n'a de prix.
 */
class PricingGroup extends Model
{
    protected $fillable = ['pricing_grid_id', 'name', 'price', 'sort_order'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'sort_order' => 'integer'];
    }

    public function grid(): BelongsTo
    {
        return $this->belongsTo(PricingGrid::class, 'pricing_grid_id');
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'pricing_group_zone')->withPivot('pricing_grid_id');
    }
}
