<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relais entre deux livreurs : celui qui a les colis (from) les remet à celui qui doit les livrer (to), qui saisit
 * le code à 4 chiffres affiché chez le premier. Le dispatch peut valider à leur place (téléphone en panne).
 */
class ParcelRelay extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 15;

    protected $fillable = [
        'company_id', 'from_courier_id', 'to_courier_id', 'status', 'code', 'attempts', 'order_ids', 'received_ids',
        'missing_ids', 'expires_at', 'confirmed_at', 'validated_by', 'reason', 'from_lat', 'from_lng', 'to_lat', 'to_lng',
    ];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'attempts' => 'integer',
            'order_ids' => 'array',
            'received_ids' => 'array',
            'missing_ids' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function fromCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'from_courier_id');
    }

    public function toCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'to_courier_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture() && $this->attempts < self::MAX_ATTEMPTS;
    }
}
