<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Crédit d'une agence chez Jibiat : sa part des paiements PayDunya de ses e-commerçants (+), déduite de ses factures
 * ou versée sur demande (−). Le solde est la somme des lignes.
 */
class PlatformCredit extends Model
{
    public const EARNED = 'live_share';

    public const APPLIED = 'invoice';

    public const PAYOUT = 'payout';

    protected $fillable = ['company_id', 'amount', 'reason', 'label', 'live_payment_id', 'platform_invoice_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public static function balance(int $companyId): int
    {
        return (int) static::query()->where('company_id', $companyId)->sum('amount');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
