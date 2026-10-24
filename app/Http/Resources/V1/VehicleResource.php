<?php

namespace App\Http\Resources\V1;

use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Services\Vehicles\VehicleFleet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $current = $this->relationLoaded('documents') ? VehicleFleet::current($this->documents) : collect();
        $states = $current->map->state();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => Vehicle::TYPES[$this->type] ?? $this->type,
            'label' => $this->label(),
            'plate' => $this->plate,
            'brand' => $this->brand,
            'model' => $this->model,
            'color' => $this->color,
            'photo_url' => $this->photo_path ? "/vehicles/{$this->id}/photo?v=".$this->updated_at?->timestamp : null,
            'owner' => $this->owner,
            'owner_label' => $this->owner ? Vehicle::OWNERS[$this->owner] : 'À préciser',
            'owner_name' => $this->owner_name,
            'status' => $this->status,
            'status_label' => Vehicle::STATUSES[$this->status] ?? $this->status,
            'notes' => $this->notes,
            'courier' => $this->courier ? ['id' => $this->courier->id, 'name' => $this->courier->user?->name, 'phone' => $this->courier->user?->phone] : null,
            // Papiers en cours (le plus récent de chaque type) et leur état global
            'documents' => $this->whenLoaded('documents', fn () => VehicleDocumentResource::collection($current->values())),
            'all_documents' => $this->whenLoaded('assignments', fn () => VehicleDocumentResource::collection($this->documents->sortByDesc('expires_at')->values())),
            'documents_state' => $states->contains('expired') ? 'expired' : ($states->contains('soon') ? 'soon' : 'valid'),
            'insurance_state' => $states->get('insurance', 'missing'),
            'history' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn (VehicleAssignment $a) => [
                'courier' => $a->courier?->user?->name,
                'started_at' => $a->started_at?->toIso8601String(),
                'ended_at' => $a->ended_at?->toIso8601String(),
            ])),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
