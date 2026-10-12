<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Numéro WhatsApp Business (API Cloud de Meta) utilisé pour les envois.
 */
class WhatsAppAccount extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'company_id', 'owner_type', 'owner_id', 'provider', 'waba_id', 'phone_number_id',
        'display_phone', 'access_token', 'status', 'verified_name', 'quality_rating', 'last_error',
        'connected_at', 'webhook_subscribed_at',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'connected_at' => 'datetime',
            'webhook_subscribed_at' => 'datetime',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MetaTemplate::class, 'whatsapp_account_id');
    }

    public function isConfigured(): bool
    {
        return filled($this->phone_number_id) && filled($this->access_token);
    }

    /**
     * Envois réels pour ce numéro ? WHATSAPP_DRIVER=meta : dès qu'il est renseigné ;
     * « log » : jamais (simulation partout) ; « auto » (défaut) : une fois sa connexion
     * vérifiée auprès de Meta. Sinon, l'entreprise reste en simulation.
     */
    public function isLive(): bool
    {
        return match (config('messaging.whatsapp.driver')) {
            'meta' => $this->isConfigured(),
            'log' => false,
            default => $this->isConfigured() && $this->status === 'connected',
        };
    }

    public static function forCompanyId(int $companyId): ?self
    {
        return static::forCompany($companyId)->where('owner_type', 'company')->first();
    }
}
