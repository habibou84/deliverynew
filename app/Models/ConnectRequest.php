<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'ouverture de compte d'un e-commerçant (inscriptions fermées) : validée ou refusée par l'agence.
 * Depuis la connexion directe (contrat socle 1.12), elle peut venir du serveur de Jibiat Marchand
 * (« request_id ») et servir à rattacher un compte existant avec l'accord du marchand (« confirmation »).
 */
class ConnectRequest extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Ouverture d'un nouveau compte */
    public const KIND_REQUEST = 'request';

    /** Rattachement d'un compte existant : le marchand confirme sur WhatsApp */
    public const KIND_CONFIRMATION = 'confirmation';

    protected $fillable = [
        'company_id', 'client_id', 'business_name', 'contact_name', 'phone', 'email', 'pickup_zone_id', 'pickup_address', 'message',
        'password_hash', 'status', 'merchant_id', 'decided_by', 'decided_at', 'request_id', 'kind', 'store_reference', 'store_url',
        'pickup_place', 'phone_verified_at', 'consent_at', 'waiting_orders', 'decide_before', 'activity', 'decline_reason',
    ];

    protected $hidden = ['password_hash', 'pending_key'];

    protected $attributes = ['kind' => self::KIND_REQUEST, 'waiting_orders' => 0];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'consent_at' => 'datetime',
            'decide_before' => 'datetime',
            'decision_sent_at' => 'datetime',
            'activity' => 'array',
            'waiting_orders' => 'integer',
            'pending_key' => 'encrypted',
        ];
    }

    public function pickupZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'pickup_zone_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** Demande envoyée par le serveur de Jibiat Marchand (connexion directe) */
    public function isDirect(): bool
    {
        return $this->request_id !== null;
    }

    public function isConfirmation(): bool
    {
        return $this->kind === self::KIND_CONFIRMATION;
    }

    /**
     * État au sens du contrat (Contract::CONNECT_DIRECT_STATUSES).
     */
    public function directStatus(): string
    {
        return match ($this->status) {
            self::ACCEPTED => 'connected',
            self::PENDING => $this->isConfirmation() ? 'confirmation' : 'pending',
            default => $this->status,
        };
    }
}
