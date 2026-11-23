<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écart de remise : un colis que le livreur devait rendre au dépôt n'a pas été réceptionné (motif de l'agent).
 * Le livreur confirme (« je l'ai encore ») ou conteste (« je l'ai remis ») ; un administrateur tranche.
 */
class ParcelGap extends Model
{
    use BelongsToCompany;

    public const CASH_POINT = 'cash_point';

    public const DROP_CONTROL = 'drop_control';

    public const REASONS = [
        'not_presented' => 'Pas présenté par le livreur',
        'not_found' => 'Introuvable dans le bac',
        'damaged' => 'Colis abîmé, refusé',
        'other' => 'Autre',
    ];

    public const KEPT = 'kept';

    public const HANDED = 'handed';

    public const AT_HUB = 'at_hub';

    public const COURIER = 'courier';

    protected $fillable = [
        'company_id', 'courier_id', 'order_id', 'source', 'parcel_drop_id', 'reported_by', 'reason', 'note',
        'courier_answer', 'answered_at', 'resolution', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /** Contesté par le livreur, pas encore tranché */
    public function isDisputed(): bool
    {
        return $this->courier_answer === self::HANDED && $this->resolution === null;
    }

    /** Le livreur n'a pas encore répondu */
    public function isOpen(): bool
    {
        return $this->courier_answer === null && $this->resolution === null;
    }
}
