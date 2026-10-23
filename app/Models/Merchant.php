<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\ClientType;
use App\Enums\FeePayer;
use App\Enums\MerchantStatus;
use App\Enums\NotificationEvent;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'pickup_location_source',
        'pickup_located_at',
        'pickup_location_accuracy',
        'pricing_grid_id',
        'default_fee_payer',
        'status',
        'client_type',
        'contract_starts_on',
        'contract_ends_on',
        'billing_period',
        'contract_terms',
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
            'client_type' => ClientType::class,
            'billing_period' => BillingPeriod::class,
            'contract_starts_on' => 'date:Y-m-d',
            'contract_ends_on' => 'date:Y-m-d',
            'pickup_lat' => 'float',
            'is_test' => 'boolean',
            'pickup_lng' => 'float',
            'pickup_located_at' => 'datetime',
            'pickup_location_accuracy' => 'integer',
            'shop_enabled' => 'boolean',
            'shop_fee_payer' => FeePayer::class,
        ];
    }

    public const LOCATION_MERCHANT = 'merchant';

    public const LOCATION_STAFF = 'staff';

    public const LOCATION_COURIER = 'courier';

    public function isUnderContract(): bool
    {
        return $this->client_type === ClientType::Contract;
    }

    /**
     * État du contrat : active (en cours, ou sans dates), upcoming (commence plus tard), expired (terminé) ; null pour
     * un client ponctuel.
     */
    public function contractStatus(): ?string
    {
        if (! $this->isUnderContract()) {
            return null;
        }

        return match (true) {
            $this->contract_ends_on !== null && $this->contract_ends_on->isPast() && ! $this->contract_ends_on->isToday() => 'expired',
            $this->contract_starts_on !== null && $this->contract_starts_on->isFuture() => 'upcoming',
            default => 'active',
        };
    }

    public function scopeUnderContract(Builder $query): Builder
    {
        return $query->where('client_type', ClientType::Contract->value);
    }

    public function scopeOccasional(Builder $query): Builder
    {
        return $query->where('client_type', ClientType::Occasional->value);
    }

    /** Contrats dont la date de fin est passée */
    public function scopeContractExpired(Builder $query): Builder
    {
        return $query->underContract()->whereNotNull('contract_ends_on')->whereDate('contract_ends_on', '<', now()->toDateString());
    }

    public function hasPickupLocation(): bool
    {
        return $this->pickup_lat !== null && $this->pickup_lng !== null;
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
     * Le marchand veut-il être prévenu de cet événement (notification ou WhatsApp) ?
     */
    public function wantsNotification(NotificationEvent $event): bool
    {
        $preference = $this->loadMissing('notificationPreferences')->notificationPreferences->firstWhere('event', $event);

        return $preference ? $preference->channels !== [] : $event->enabledByDefault();
    }

    /**
     * Appareils (téléphones, navigateurs) des comptes du marchand abonnés aux notifications push.
     */
    public function pushSubscriptions(): Builder
    {
        return PushSubscription::whereIn('user_id', User::withoutGlobalScopes()->where('merchant_id', $this->id)->where('status', 'active')->select('id'));
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
