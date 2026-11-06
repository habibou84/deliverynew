<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paiement PayDunya d'un e-commerçant à Jibiat : un live, le forfait du mois ou des fiches IA */
class LivePayment extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    protected $fillable = ['company_id', 'merchant_id', 'user_id', 'live_id', 'kind', 'amount', 'provider', 'token', 'status', 'method', 'error', 'live_charge_id', 'paid_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function live(): BelongsTo
    {
        return $this->belongsTo(Live::class);
    }
}
