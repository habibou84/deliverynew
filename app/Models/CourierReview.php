<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Évaluation du mois d'un livreur par un responsable.
 */
class CourierReview extends Model
{
    use BelongsToCompany;

    public const CRITERIA = [
        'punctuality' => 'Ponctualité',
        'appearance' => 'Tenue et présentation',
        'cash' => 'Caisse rendue à l’heure',
        'instructions' => 'Respect des consignes',
        'behaviour' => 'Comportement',
    ];

    protected $fillable = ['company_id', 'courier_id', 'reviewer_id', 'period', 'criteria', 'score', 'comment', 'comment_visible'];

    protected function casts(): array
    {
        return [
            'period' => 'date:Y-m-d',
            'criteria' => 'array',
            'score' => 'float',
            'comment_visible' => 'boolean',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
