<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderAssignment
 */
class OrderAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'status' => $this->status,
            'courier_id' => $this->courier_id,
            'courier_name' => $this->whenLoaded('courier', fn () => $this->courier->user?->name),
            'assigned_by_name' => $this->whenLoaded('assignedBy', fn () => $this->assignedBy?->name),
            'assigned_at' => $this->assigned_at,
            'accepted_at' => $this->accepted_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'refusal_reason' => $this->refusal_reason,
            'order' => OrderResource::make($this->whenLoaded('order')),
        ];
    }
}
