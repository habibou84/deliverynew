<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modification d'une course demandée par l'e-commerçant, à valider par l'équipe (réglage « Modifications des
 * e-commerçants ») : en attente, acceptée, refusée ou remplacée par une demande plus récente.
 */
class OrderChangeRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    public const REPLACED = 'replaced';

    protected $fillable = [
        'company_id', 'order_id', 'requested_by', 'decided_by', 'status', 'data', 'changes',
        'fees_before', 'fees_after', 'cod_before', 'cod_after', 'note', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'changes' => 'array',
            'fees_before' => 'integer',
            'fees_after' => 'integer',
            'cod_before' => 'integer',
            'cod_after' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'changes' => $this->getAttribute('changes'),
            'fees' => ['from' => $this->fees_before, 'to' => $this->fees_after],
            'cod' => ['from' => $this->cod_before, 'to' => $this->cod_after],
            'requested_by' => $this->requester?->name,
            'requested_at' => $this->created_at?->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
