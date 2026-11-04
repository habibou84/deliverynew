<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'annulation (ou de refus) faite par un membre de l'équipe au-delà de sa limite du jour : la course reste
 * active jusqu'à la décision d'un administrateur.
 */
class CancellationRequest extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    // La course a avancé (ramassée…) avant la décision : plus rien à annuler
    public const EXPIRED = 'expired';

    protected $fillable = ['company_id', 'order_id', 'to_status', 'requested_by', 'reason_code', 'note', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['to_status' => OrderStatus::class, 'decided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
