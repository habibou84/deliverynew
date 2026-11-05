<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conversation WhatsApp d'une cliente pendant un live : produit choisi, puis nom et quartier attendus */
class LiveChat extends Model
{
    public const DETAILS = 'details';

    public const CONFIRM = 'confirm';

    protected $fillable = ['live_id', 'phone', 'push_name', 'step', 'live_item_id', 'quantity', 'customer_name', 'zone_id', 'expires_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'expires_at' => 'datetime'];
    }

    public function live(): BelongsTo
    {
        return $this->belongsTo(Live::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LiveItem::class, 'live_item_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
