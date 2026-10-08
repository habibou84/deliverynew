<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Argent encaissé auprès du destinataire d'une course livrée.
 */
class CashCollection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'order_id', 'courier_id', 'amount_expected', 'amount_collected', 'courier_expense',
        'method', 'received_by_company', 'transaction_ref', 'collected_at', 'remittance_id',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'received_by_company' => 'boolean',
            'amount_expected' => 'integer',
            'amount_collected' => 'integer',
            'courier_expense' => 'integer',
            'collected_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(CourierRemittance::class, 'remittance_id');
    }

    /**
     * Encaissements à régler avec la caisse : argent encore chez le livreur, ou
     * frais qu'il a avancés (expédition) et qui ne lui ont pas encore été déduits.
     */
    public function scopeInCourierHands(Builder $query): Builder
    {
        return $query->whereNull('remittance_id')
            ->where(fn ($q) => $q->where('received_by_company', false)->orWhere('courier_expense', '>', 0));
    }

    /**
     * Montant que le livreur doit verser pour cette course (négatif : la caisse lui doit de l'argent).
     */
    public function amountDue(): int
    {
        return ($this->received_by_company ? 0 : $this->amount_collected) - $this->courier_expense;
    }

    /**
     * Même calcul, en SQL, pour les totaux.
     */
    public static function amountDueSql(): string
    {
        return 'CASE WHEN received_by_company THEN 0 ELSE amount_collected END - courier_expense';
    }

    /**
     * Réglé avec la caisse : versé par le livreur, ou payé directement à l'entreprise sans frais avancés.
     */
    public function isSettled(): bool
    {
        return $this->remittance_id !== null || ($this->received_by_company && $this->courier_expense === 0);
    }
}
