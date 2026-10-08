<?php

namespace App\Models;

use App\Enums\EarningType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gain (ou retenue) d'un livreur. Solde à payer = somme des gains non payés.
 */
class CourierEarning extends Model
{
    use BelongsToCompany, IsAppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'courier_id', 'type', 'amount', 'order_id', 'remittance_id', 'payout_id', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => EarningType::class,
            'amount' => 'integer',
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

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CourierPayout::class, 'payout_id');
    }
}
