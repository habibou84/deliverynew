<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Live d'un e-commerçant : produits à vendre (codes A1, A2…), carte à épingler, commandes prises pendant le direct.
 */
class Live extends Model
{
    use BelongsToCompany;

    public const DRAFT = 'draft';

    public const LIVE = 'live';

    public const ENDED = 'ended';

    protected $fillable = ['company_id', 'merchant_id', 'name', 'status', 'plan', 'ai_cards_included', 'ai_cards_used', 'started_at', 'ended_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'ai_cards_included' => 'integer',
            'ai_cards_used' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LiveItem::class)->orderBy('position')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LiveOrder::class);
    }
}
