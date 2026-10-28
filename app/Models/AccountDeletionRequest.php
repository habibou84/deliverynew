<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de suppression de compte d'un e-commerçant ou d'un livreur (depuis son profil) : compte coupé tout de suite,
 * données personnelles effacées après le délai, sauf si l'entreprise annule avant.
 */
class AccountDeletionRequest extends Model
{
    use BelongsToCompany;

    public const DELAY_DAYS = 30;

    protected $fillable = ['company_id', 'user_id', 'name', 'role', 'erase_after', 'cancelled_at', 'cancelled_by', 'erased_at'];

    protected function casts(): array
    {
        return [
            'erase_after' => 'datetime',
            'cancelled_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Demandes en cours : ni annulées ni effacées */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')->whereNull('erased_at');
    }
}
