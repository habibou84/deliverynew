<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une recomparaison complète (ou de nuit) avec l'ancienne application (voir LegacyRechecks) */
class LegacyRecheck extends Model
{
    /** Délai pendant lequel les annulations d'une recomparaison peuvent être annulées */
    public const UNDO_DAYS = 7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'since' => 'date',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function purge(): BelongsTo
    {
        return $this->belongsTo(LegacyPurge::class, 'legacy_purge_id');
    }

    public function canBeUndone(): bool
    {
        return $this->status === 'done' && $this->undone_at === null && $this->legacy_purge_id !== null
            && ($this->counts['deleted_cancelled'] ?? 0) > 0
            && $this->finished_at !== null && $this->finished_at->gt(now()->subDays(self::UNDO_DAYS));
    }
}
