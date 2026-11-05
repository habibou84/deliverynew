<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Frais de l'outil live : payé par l'e-commerçant (ou offert par l'agence) ; part de Jibiat facturée à l'entreprise */
class LiveCharge extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'live_id', 'kind', 'label', 'amount', 'platform_amount', 'offered_by_company', 'cards', 'platform_invoice_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'platform_amount' => 'integer', 'offered_by_company' => 'boolean', 'cards' => 'integer'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
