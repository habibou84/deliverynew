<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Commande prise pendant un live : course créée, ou liste d'attente quand le produit est épuisé */
class LiveOrder extends Model
{
    public const ORDERED = 'ordered';

    public const WAITING = 'waiting';

    protected $fillable = ['live_id', 'live_item_id', 'order_id', 'customer_name', 'customer_phone', 'quantity', 'status', 'source'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function live(): BelongsTo
    {
        return $this->belongsTo(Live::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LiveItem::class, 'live_item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
