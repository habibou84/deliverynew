<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Fiche produit remplie par l'IA à partir d'une photo (reprises de la photo comprises dans la même fiche) */
class LiveAiCard extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'live_id', 'token', 'source', 'attempts', 'cost_millis'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'cost_millis' => 'integer'];
    }
}
