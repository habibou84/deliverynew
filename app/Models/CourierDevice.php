<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Téléphone d'un livreur qui reçoit la sonnerie des missions (jeton Firebase de l'application Android).
 */
class CourierDevice extends Model
{
    protected $fillable = ['company_id', 'user_id', 'token', 'platform', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
