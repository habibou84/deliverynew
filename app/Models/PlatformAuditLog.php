<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Action d'un super administrateur : qui, quoi (route), sur quelle entreprise, avec quelles données (sans les
 * secrets), d'où. Jamais modifiée.
 */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'company_id', 'action', 'method', 'path', 'status', 'data', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
