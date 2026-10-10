<?php

namespace App\Models;

use App\Enums\FeePayer;
use App\Enums\MerchantStatus;
use App\Enums\NotificationEvent;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
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
        'shop_slug',
        'shop_enabled',
        'shop_intro',
        'shop_fee_payer',
    ];

    protected function casts(): array
    {
        return [
            'default_fee_payer' => FeePayer::class,
            'status' => MerchantStatus::class,
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'shop_enabled' => 'boolean',
            'shop_fee_payer' => FeePayer::class,
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

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(MerchantLedgerEntry::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(MerchantPayout::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function scheduledReports(): HasMany
    {
        return $this->hasMany(ScheduledReport::class);
    }

    /**
     * Le marchand veut-il recevoir cet événement sur WhatsApp ?
     */
    public function wantsWhatsApp(NotificationEvent $event): bool
    {
        $preference = $this->loadMissing('notificationPreferences')->notificationPreferences->firstWhere('event', $event);

        return $preference ? in_array('whatsapp', $preference->channels, true) : $event->enabledByDefault();
    }

    /**
     * Numéro qui reçoit les messages WhatsApp du marchand.
     */
    public function messagingPhone(): ?string
    {
        return $this->whatsapp_phone ?: $this->phone;
    }

    public function isActive(): bool
    {
        return $this->status === MerchantStatus::Active;
    }
}
