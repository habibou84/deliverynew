<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'phone',
        'email',
        'address',
        'tagline',
        'currency',
        'timezone',
        'auto_confirm_orders',
        'default_max_attempts',
        'require_delivery_code',
        'return_fee_percent',
        'notify_recipients',
        'sms_fallback',
        'whatsapp_orders',
        'merchant_signup',
        'field_alert_reminder_minutes',
        'parcel_hold_alert_hours',
        'pickup_assign_alert_minutes',
        'delivery_assign_alert_minutes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'auto_confirm_orders' => 'boolean',
            'default_max_attempts' => 'integer',
            'require_delivery_code' => 'boolean',
            'return_fee_percent' => 'integer',
            'notify_recipients' => 'boolean',
            'sms_fallback' => 'boolean',
            'whatsapp_orders' => 'boolean',
            'merchant_signup' => 'boolean',
            'field_alert_reminder_minutes' => 'integer',
            'parcel_hold_alert_hours' => 'integer',
            'pickup_assign_alert_minutes' => 'integer',
            'delivery_assign_alert_minutes' => 'integer',
            'status' => CompanyStatus::class,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }
}
