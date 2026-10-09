<?php

namespace App\Http\Resources\V1;

use App\Models\Company;
use App\Support\Branding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'auto_confirm_orders' => $this->auto_confirm_orders,
            'default_max_attempts' => $this->default_max_attempts,
            'require_delivery_code' => $this->require_delivery_code,
            'return_fee_percent' => $this->return_fee_percent,
            'field_alert_reminder_minutes' => $this->field_alert_reminder_minutes,
            'parcel_hold_alert_hours' => $this->parcel_hold_alert_hours,
            'tagline' => $this->tagline,
            'logo_url' => Branding::logoUrl($this->resource),
            'status' => $this->status,
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
