<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Véhicule du parc : moto, voiture… avec son propriétaire, le livreur à qui il est confié et ses papiers.
 */
class Vehicle extends Model
{
    use BelongsToCompany;

    public const TYPES = ['moto' => 'Moto', 'velo' => 'Vélo', 'voiture' => 'Voiture', 'tricycle' => 'Tricycle'];

    public const OWNERS = ['company' => 'L\'entreprise', 'courier' => 'Le livreur', 'lessor' => 'Un loueur'];

    public const STATUSES = ['active' => 'En service', 'repair' => 'En réparation', 'retired' => 'Retiré du parc'];

    protected $fillable = [
        'company_id', 'type', 'plate', 'brand', 'model', 'color', 'photo_path',
        'owner', 'owner_name', 'courier_id', 'status', 'notes',
    ];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    /** Nom court : « Moto · 1234 AB 01 » */
    public function label(): string
    {
        return trim((self::TYPES[$this->type] ?? 'Véhicule').($this->plate ? ' · '.$this->plate : ''));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
