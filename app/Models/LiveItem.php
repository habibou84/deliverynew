<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Produit d'un live : code (A1…), prix du live, quantité mise en vente, quantité vendue */
class LiveItem extends Model
{
    protected $fillable = ['live_id', 'product_id', 'code', 'name', 'price', 'quantity', 'sold', 'position'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'quantity' => 'integer', 'sold' => 'integer', 'position' => 'integer'];
    }

    public function live(): BelongsTo
    {
        return $this->belongsTo(Live::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LiveOrder::class);
    }

    public function remaining(): int
    {
        return max(0, $this->quantity - $this->sold);
    }
}
