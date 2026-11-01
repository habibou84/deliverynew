<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carte Membre Premium Jibiat Marchand offerte par l'entreprise à un marchand. La carte est créée et tenue par
 * Jibiat Marchand (contrat socle premium-card) ; on garde ici sa copie, relue chaque jour.
 */
class PremiumCard extends Model
{
    use BelongsToCompany;

    public const STATE_LABELS = [
        'available' => 'Pas encore activée',
        'free' => 'Mois offerts en cours',
        'discount' => 'Réduction en cours',
        'lapsed' => 'Réduction arrêtée',
        'revoked' => 'Annulée',
    ];

    protected $fillable = [
        'company_id', 'merchant_id', 'code', 'number', 'label', 'url', 'state', 'reason', 'offered_by', 'offered_at',
        'revoked_at', 'free_until', 'activated_at', 'store_name', 'store_url', 'synced_at', 'price', 'platform_invoice_id', 'plan', 'free_months', 'discount_rate',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'offered_at' => 'datetime',
            'revoked_at' => 'datetime',
            'free_until' => 'date',
            'activated_at' => 'datetime',
            'synced_at' => 'datetime',
            'price' => 'integer',
            'free_months' => 'integer',
            'discount_rate' => 'float',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by');
    }

    /** Cartes qui comptent dans le nombre offert par l'entreprise (toutes sauf les annulées) */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
