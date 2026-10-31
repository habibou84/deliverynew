<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Session d'assistance ouverte par un super administrateur dans une entreprise.
 */
class SupportSession extends Model
{
    public const TOKEN_PREFIX = 'support:';

    // Durée de validité de l'accès
    public const MINUTES = 60;

    protected $fillable = ['company_id', 'opened_by', 'user_id', 'reason', 'ip', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * Coupe toutes les sessions d'assistance ouvertes par ce membre de l'équipe (suspension, rôle changé).
     */
    public static function revokeOpenedBy(User $member): void
    {
        PersonalAccessToken::query()->where('name', self::TOKEN_PREFIX.$member->id)->delete();
        self::query()->where('opened_by', $member->id)->where('expires_at', '>', now())->update(['expires_at' => now()]);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
