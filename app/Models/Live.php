<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Live d'un e-commerçant : produits à vendre (codes A1, A2…), carte à épingler, commandes prises pendant le direct.
 */
class Live extends Model
{
    use BelongsToCompany;

    public const DRAFT = 'draft';

    public const LIVE = 'live';

    public const ENDED = 'ended';

    protected $fillable = ['company_id', 'merchant_id', 'name', 'status', 'plan', 'ai_cards_included', 'ai_cards_used', 'started_at', 'ended_at', 'created_by', 'billed_orders', 'billed_offered', 'billed_amount', 'billed_at'];

    protected static function booted(): void
    {
        // Lien public du live (/l/{jeton}) : commander sans WhatsApp
        static::creating(function (Live $live) {
            $live->public_token ??= Str::lower(Str::random(10));
        });
    }

    /** Adresse du lien du live, sur le domaine de l'agence */
    public function publicUrl(): string
    {
        $company = $this->relationLoaded('merchant') ? $this->merchant?->company : null;
        $company ??= Company::withoutGlobalScopes()->find($this->company_id);

        return $company->url("/l/{$this->public_token}");
    }

    protected function casts(): array
    {
        return [
            'ai_cards_included' => 'integer',
            'ai_cards_used' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'billed_orders' => 'integer',
            'billed_offered' => 'integer',
            'billed_amount' => 'integer',
            'billed_at' => 'datetime',
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
