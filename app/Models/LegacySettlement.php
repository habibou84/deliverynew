<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** « Solder l'ancien » : sommes de l'ancienne application réglées hors Jibiat (voir LegacySettlements) */
class LegacySettlement extends Model
{
    /** Délai pendant lequel le solde peut être annulé */
    public const UNDO_DAYS = 7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before' => 'date',
            'clear_cash' => 'boolean',
            'amount' => 'integer',
            'cash_amount' => 'integer',
            'undone_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canBeUndone(): bool
    {
        return $this->status === 'done' && $this->undone_at === null && $this->created_at->gt(now()->subDays(self::UNDO_DAYS));
    }
}
