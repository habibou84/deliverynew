<?php

namespace App\Http\Resources\V1;

use App\Models\Courier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Courier
 */
class CourierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'phone' => $this->user?->phone,
            'status' => $this->user?->status,
            'vehicle_type' => $this->vehicle_type,
            'vehicle_plate' => $this->vehicle_plate,
            'pay_plan_id' => $this->pay_plan_id,
            'pay_plan' => $this->whenLoaded('payPlan', fn () => $this->payPlan ? [
                'id' => $this->payPlan->id,
                'name' => $this->payPlan->name,
                'personal' => $this->payPlan->courier_id !== null,
            ] : null),
            'is_available' => $this->is_available,
            'current_lat' => $this->current_lat,
            'current_lng' => $this->current_lng,
            'last_location_at' => $this->last_location_at,
            'zones' => ZoneResource::collection($this->whenLoaded('zones')),
            'active_assignments_count' => $this->whenCounted('activeAssignments'),
            'notes' => $this->notes,
        ];
    }
}
