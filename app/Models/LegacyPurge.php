<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un nettoyage des anciennes courses reprises (voir LegacyOrdersPurge) */
class LegacyPurge extends Model
{
    /** Délai pendant lequel un nettoyage peut être annulé */
    public const UNDO_DAYS = 7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'counts' => 'array',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LegacyPurgeItem::class);
    }

    public function canBeUndone(): bool
    {
        return $this->status === 'done' && $this->undone_at === null
            && $this->finished_at !== null && $this->finished_at->gt(now()->subDays(self::UNDO_DAYS));
    }
}
