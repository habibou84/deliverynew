<?php

namespace App\Models;

use App\Enums\FeePayer;
use App\Enums\MerchantStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    /** @use HasFactory<\Database\Factories\MerchantFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'business_name',
        'contact_name',
        'phone',
        'whatsapp_phone',
        'email',
        'pickup_zone_id',
        'pickup_address',
        'pickup_landmark',
        'pickup_lat',
        'pickup_lng',
        'pricing_grid_id',
        'default_fee_payer',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_fee_payer' => FeePayer::class,
            'status' => MerchantStatus::class,
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PhoneNumber::normalize($value) ?? $value);
    }

    protected function whatsappPhone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PhoneNumber::normalize($value) ?? $value);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pickupZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'pickup_zone_id');
    }

    public function pricingGrid(): BelongsTo
    {
        return $this->belongsTo(PricingGrid::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class);
    }

    public function isActive(): bool
    {
        return $this->status === MerchantStatus::Active;
    }
}
