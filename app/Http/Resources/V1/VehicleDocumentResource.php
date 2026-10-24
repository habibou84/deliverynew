<?php

namespace App\Http\Resources\V1;

use App\Models\VehicleDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VehicleDocument
 */
class VehicleDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'courier_id' => $this->courier_id,
            'type' => $this->type,
            'label' => $this->label(),
            'number' => $this->number,
            'expires_at' => $this->expires_at?->toDateString(),
            // valid | soon | expired | none (sans date de fin)
            'state' => $this->state(),
            'days_left' => $this->daysLeft(),
            'notes' => $this->notes,
            'file_url' => $this->file_path ? "/vehicle-documents/{$this->id}/file" : null,
            'file_is_pdf' => $this->file_mime === 'application/pdf',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
