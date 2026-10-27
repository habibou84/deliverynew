<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version des applications d'une entreprise fabriquée par GitHub Actions : le workflow lit sa fiche et renvoie les
 * fichiers avec un jeton propre à la version, qui expire.
 */
class BrandedAppBuild extends Model
{
    public const STATUSES = [
        'queued' => 'En attente',
        'building' => 'En cours',
        'ready' => 'Prête',
        'failed' => 'Échec',
        'published' => 'Publiée',
    ];

    /** Applications fabriquées (dossiers mobile/<application>) */
    public const APPS = ['ecommercant', 'livreur'];

    protected $fillable = [
        'branded_app_id', 'version_name', 'version_code', 'status', 'token_hash', 'token_expires_at', 'run_url', 'files',
        'error', 'created_by', 'started_at', 'finished_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'files' => 'array',
            'token_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(BrandedApp::class, 'branded_app_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Fabrication pas encore terminée : le workflow peut lire la fiche et envoyer les fichiers */
    public function open(): bool
    {
        return in_array($this->status, ['queued', 'building'], true);
    }
}
