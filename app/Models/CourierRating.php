<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jibiat\Socle\Contract\Contract;

/**
 * Avis laissé sur une course pour le livreur qui l'a livrée (destinataire ou e-commerçant).
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

    /** Points forts de l'e-commerçant : les quatre premières remarques du contrat (Contract::MERCHANT_RATING_TAGS) */
    private const MERCHANT_GOOD = ['pickup_on_time', 'careful', 'good_with_customers', 'cash_returned'];

    /** Remarques qui alertent tout de suite les responsables */
    public const SERIOUS_TAGS = ['asked_money', 'damaged', 'rude', 'customer_mistreated'];

    /**
     * Remarques possibles selon la source de l'avis.
     *
     * @return array<string, array{label: string, good: bool}>
     */
    public static function tagsFor(string $source): array
    {
        if ($source !== self::SOURCE_MERCHANT) {
            return self::RECIPIENT_TAGS;
        }

        return collect(Contract::MERCHANT_RATING_TAGS)
            ->map(fn (string $label, string $code) => ['label' => $label, 'good' => in_array($code, self::MERCHANT_GOOD, true)])
            ->all();
    }

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
        $known = self::tagsFor($this->source);

        return collect($this->tags ?? [])
            ->filter(fn ($t) => isset($known[$t]))
            ->map(fn ($t) => ['code' => $t, ...$known[$t]])
            ->values()->all();
    }
}
