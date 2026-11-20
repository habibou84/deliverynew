<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quartier absent de la liste commune, signalé par une entreprise ou un de ses e-commerçants : la plateforme
 * l'ajoute au référentiel (socle), pour toutes les entreprises, ou refuse.
 */
class PlaceReport extends Model
{
    public const STATUSES = ['pending', 'added', 'rejected'];

    protected $fillable = ['company_id', 'user_id', 'name', 'parent_code', 'note', 'status'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }
}
