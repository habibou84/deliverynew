<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Applications Android d'une entreprise à son nom (E-commerçant et Livreur), publiées sur son compte Google Play.
 * La clé de signature (PKCS12) reste chez Jibiat, chiffrée.
 */
class BrandedApp extends Model
{
    protected $fillable = [
        'company_id', 'enabled', 'merchant_app_id', 'courier_app_id', 'merchant_app_name', 'courier_app_name',
        'google_linked_at', 'short_description', 'full_description',
    ];

    protected $hidden = ['keystore', 'keystore_password'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'google_linked_at' => 'datetime',
            'keystore' => 'encrypted',
            'keystore_password' => 'encrypted',
            'keystore_created_at' => 'datetime',
            'next_version_code' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(BrandedAppBuild::class);
    }
}
