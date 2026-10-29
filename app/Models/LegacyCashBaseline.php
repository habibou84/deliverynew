<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un point de départ de l'argent chez les livreurs (voir LegacyCashBaselines) */
class LegacyCashBaseline extends Model
{
    /** Délai pendant lequel un point de départ peut être annulé */
    public const UNDO_DAYS = 7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cutoff' => 'date',
            'lines' => 'array',
            'undone_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CashCollection::class);
    }

    public function canBeUndone(): bool
    {
        return $this->status === 'done' && $this->undone_at === null && $this->created_at->gt(now()->subDays(self::UNDO_DAYS));
    }
}
