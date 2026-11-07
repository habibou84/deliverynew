<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conversation WhatsApp d'un client pendant un live : articles choisis (panier), nom et quartier, puis récapitulatif
 * à confirmer. Rien n'est envoyé à l'agence avant « 1 Je confirme ».
 */
class LiveChat extends Model
{
    /** Nom et quartier attendus */
    public const DETAILS = 'details';

    /** Récapitulatif envoyé : 1 Je confirme, 2 Ajouter, 3 Changer l'adresse, 4 Annuler */
    public const CONFIRM = 'confirm';

    /** Code de l'article à ajouter attendu */
    public const ADD = 'add';

    /** Annulation demandée : à redire une fois */
    public const CANCEL = 'cancel';

    protected $fillable = ['live_id', 'phone', 'push_name', 'step', 'live_item_id', 'quantity', 'cart', 'customer_name', 'zone_id', 'expires_at', 'last_text', 'misses'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'cart' => 'array', 'expires_at' => 'datetime', 'misses' => 'integer'];
    }

    /**
     * Articles du panier (les anciennes conversations n'ont qu'un article)
     *
     * @return list<array{item_id: int, quantity: int}>
     */
    public function lines(): array
    {
        if ($this->cart) {
            return array_values($this->cart);
        }

        return $this->live_item_id ? [['item_id' => (int) $this->live_item_id, 'quantity' => max(1, (int) $this->quantity)]] : [];
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
