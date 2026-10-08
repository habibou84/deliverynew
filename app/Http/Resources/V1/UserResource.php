<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    private bool $withPermissions = false;

    /**
     * Inclut la liste des permissions (utile au front-end pour l'utilisateur connecté).
     */
    public function withPermissions(): static
    {
        $this->withPermissions = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->primaryRole();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'company' => CompanyResource::make($this->whenLoaded('company')),
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant ? [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
            ] : null),
            'courier' => $this->whenLoaded('courier', fn () => $this->courier ? [
                'id' => $this->courier->id,
                'is_available' => $this->courier->is_available,
                'vehicle_type' => $this->courier->vehicle_type,
            ] : null),
            'permissions' => $this->when(
                $this->withPermissions,
                fn () => $this->getAllPermissions()->pluck('name')->values(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
