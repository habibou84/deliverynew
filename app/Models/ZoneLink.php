<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rattachement d'une zone à un lieu standard, ou fusion dans la zone de ce lieu : ce qui a été changé, pour pouvoir
 * l'annuler pendant 7 jours.
 */
class ZoneLink extends Model
{
    use BelongsToCompany;

    public const UNDO_DAYS = 7;

    protected $fillable = ['company_id', 'user_id', 'zone_id', 'into_zone_id', 'action', 'place_code', 'before', 'status'];

    protected function casts(): array
    {
        return ['before' => 'array', 'undone_at' => 'datetime'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function canBeUndone(): bool
    {
        return $this->status === 'done' && $this->created_at->gt(now()->subDays(self::UNDO_DAYS));
    }
}
