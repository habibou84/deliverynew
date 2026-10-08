<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Argent remis par la caisse à un livreur avant une mission (frais de gare…) ;
 * ce qu'il n'a pas dépensé revient à la caisse lors de son versement.
 */
class CourierAdvance extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'courier_id', 'amount', 'reason', 'order_id', 'given_by', 'given_at', 'remittance_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'given_at' => 'datetime',
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

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereNull('remittance_id');
    }
}
