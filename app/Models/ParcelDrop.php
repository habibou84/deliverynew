<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dépôt en libre-service : colis déposés par un livreur dans le bac du dépôt (borne scannée, colis scannés, photo),
 * contrôlés ensuite par un agent, ou confirmés automatiquement au bout de 24 h.
 */
class ParcelDrop extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const CONTROLLED = 'controlled';

    public const AUTO = 'auto';

    /** Confirmation automatique d'un dépôt resté sans contrôle */
    public const AUTO_CONFIRM_HOURS = 24;

    protected $fillable = [
        'company_id', 'courier_id', 'status', 'order_ids', 'typed_codes', 'photo_path', 'lat', 'lng',
        'dropped_at', 'controlled_at', 'controlled_by',
    ];

    protected function casts(): array
    {
        return [
            'order_ids' => 'array',
            'typed_codes' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'dropped_at' => 'datetime',
            'controlled_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function controller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'controlled_by');
    }

    /** Colis encore en attente du contrôle */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
