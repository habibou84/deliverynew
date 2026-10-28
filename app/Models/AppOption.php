<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Option « Applications à votre nom » demandée par une entreprise, pour Android ou iPhone : en attente de Jibiat,
 * active (facturée au prix retenu à l'acceptation), refusée ou arrêtée.
 */
class AppOption extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REFUSED = 'refused';

    public const STOPPED = 'stopped';

    protected $fillable = ['company_id', 'platform', 'status', 'price', 'requested_by', 'decided_by', 'started_at', 'stopped_at', 'note'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'started_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
