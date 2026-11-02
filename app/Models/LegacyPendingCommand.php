<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Commande de l'ancienne application mise de côté (e-commerçant introuvable, erreur), relancée à chaque copie */
class LegacyPendingCommand extends Model
{
    protected $fillable = ['company_id', 'legacy_id', 'client_legacy_id', 'client_name', 'reason', 'attempts', 'last_tried_at'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'last_tried_at' => 'datetime'];
    }
}
