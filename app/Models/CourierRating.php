<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avis laissé sur une course pour le livreur qui l'a livrée (destinataire, puis e-commerçant).
 */
class CourierRating extends Model
{
    use BelongsToCompany;

    public const SOURCE_RECIPIENT = 'recipient';

    public const SOURCE_MERCHANT = 'merchant';

    /** Ce qui a plu, ce qui n'a pas plu (destinataire) */
    public const RECIPIENT_TAGS = [
        'on_time' => ['label' => "À l'heure", 'good' => true],
        'polite' => ['label' => 'Poli', 'good' => true],
        'good_condition' => ['label' => 'Colis en bon état', 'good' => true],
        'called_ahead' => ['label' => "M'a prévenu", 'good' => true],
        'late' => ['label' => 'En retard', 'good' => false],
        'rude' => ['label' => 'Impoli', 'good' => false],
        'damaged' => ['label' => 'Colis abîmé', 'good' => false],
        'asked_money' => ['label' => "M'a demandé plus d'argent", 'good' => false],
    ];

    /** Remarques qui alertent tout de suite les responsables */
    public const SERIOUS_TAGS = ['asked_money', 'damaged', 'rude'];

    protected $fillable = [
        'company_id', 'courier_id', 'order_id', 'source', 'score', 'tags', 'comment', 'channel',
        'rater_phone', 'rater_user_id', 'flagged', 'handled_at', 'handled_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'tags' => 'array',
            'flagged' => 'boolean',
            'handled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function scopeToHandle(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')->where('flagged', true)->whereNull('handled_at');
    }

    /**
     * @param  list<string>|null  $tags
     */
    public static function isSerious(int $score, ?array $tags): bool
    {
        return $score <= 1 || array_intersect($tags ?? [], self::SERIOUS_TAGS) !== [];
    }

    /**
     * @return list<array{code: string, label: string, good: bool}>
     */
    public function tagList(): array
    {
        return collect($this->tags ?? [])
            ->filter(fn ($t) => isset(self::RECIPIENT_TAGS[$t]))
            ->map(fn ($t) => ['code' => $t, ...self::RECIPIENT_TAGS[$t]])
            ->values()->all();
    }
}
