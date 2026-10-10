<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Support\Tenancy;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        'daily_cutoff_time',
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
            'cutoff_alerted_on' => 'date:Y-m-d',
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

    /**
     * Lien vers une page de l'entreprise : son adresse sur la plateforme, sinon APP_URL.
     */
    public function url(string $path = ''): string
    {
        return Tenancy::baseUrl($this).($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * Adresse de l'entreprise (sous-domaine) : minuscules, chiffres et tirets, 3 à 40
     * caractères, ni réservée (www, admin…) ni déjà prise.
     *
     * @return list<mixed>
     */
    public static function slugRules(?self $ignore = null): array
    {
        return [
            'string', 'min:3', 'max:40', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
            Rule::notIn(config('platform.reserved_subdomains')),
            Rule::unique('companies', 'slug')->ignore($ignore),
        ];
    }

    /**
     * Adresse libre proposée à partir du nom (« Rapide Express » → rapide-express).
     */
    public static function freeSlug(string $name): string
    {
        $base = trim(substr(Str::slug($name), 0, 36), '-');
        $base = strlen($base) >= 3 && ! in_array($base, config('platform.reserved_subdomains'), true) ? $base : 'entreprise';
        $slug = $base;
        for ($i = 2; static::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(Courier::class);
    }
}
