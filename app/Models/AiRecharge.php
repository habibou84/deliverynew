<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recharge Jibiat IA : plus de courses et de consommation pour le mois, facturée sur la facture suivante */
class AiRecharge extends Model
{
    protected $fillable = ['company_id', 'month', 'amount', 'created_by', 'platform_invoice_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
