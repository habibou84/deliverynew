<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compte Jibiat Marchand d'un marchand parrainé par l'entreprise : rapporté par Jibiat Marchand
 * (PUT /api/public/v1/merchant-app), jamais saisi ici.
 */
class MerchantAppAccount extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'merchant_id', 'store_name', 'store_url', 'referred', 'signed_up_at', 'plan_name', 'plan_status',
        'commission_rate', 'commission_months', 'commission_ends_at', 'currency', 'earned_total', 'earned_month',
        'paid_total', 'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'referred' => 'boolean',
            'signed_up_at' => 'datetime',
            'commission_rate' => 'float',
            'commission_months' => 'integer',
            'commission_ends_at' => 'date',
            'earned_total' => 'integer',
            'earned_month' => 'integer',
            'paid_total' => 'integer',
            'reported_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
