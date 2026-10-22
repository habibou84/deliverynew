<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plus grand nombre de livreurs actifs d'une entreprise un jour donné (facturation des livreurs en plus de la formule).
 */
class CourierCount extends Model
{
    protected $fillable = ['company_id', 'day', 'couriers'];

    protected function casts(): array
    {
        return [
            'day' => 'date:Y-m-d',
            'couriers' => 'integer',
        ];
    }
}
