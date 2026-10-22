<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Numéro WhatsApp de l'entreprise utilisé pour les envois : API Cloud de Meta (« meta_cloud »), ou connexion par
 * QR code (« qr », service whatsapp-bridge) en attendant la validation de Meta.
 */
class WhatsAppAccount extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'company_id', 'owner_type', 'owner_id', 'provider', 'waba_id', 'phone_number_id',
        'display_phone', 'access_token', 'status', 'verified_name', 'quality_rating', 'last_error',
        'connected_at', 'connected_via', 'webhook_subscribed_at', 'registration_pin',
        'token_expires_at', 'token_warned_at', 'platform_token',
    ];

    protected $hidden = ['access_token', 'registration_pin'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'registration_pin' => 'encrypted',
            'connected_at' => 'datetime',
            'webhook_subscribed_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'token_warned_at' => 'datetime',
            'platform_token' => 'boolean',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MetaTemplate::class, 'whatsapp_account_id');
    }

    /**
     * Jeton utilisé pour appeler Meta : celui de l'utilisateur système de la plateforme
     * quand il a été ajouté au compte WhatsApp de l'entreprise, sinon celui de l'entreprise.
     */
    public function apiToken(): ?string
    {
        $platform = config('messaging.whatsapp.system_user_token');

        return $this->platform_token && filled($platform) ? $platform : $this->access_token;
    }

    public const QR = 'qr';

    public function isQr(): bool
    {
        return $this->provider === self::QR;
    }

    /** Session du service whatsapp-bridge */
    public function bridgeSession(): string
    {
        return 'company-'.$this->company_id;
    }

    public function isConfigured(): bool
    {
        return $this->isQr() || (filled($this->phone_number_id) && filled($this->apiToken()));
    }

    /**
     * Envois réels pour ce numéro ? WHATSAPP_DRIVER=meta : dès qu'il est renseigné ;
     * « log » : jamais (simulation partout) ; « auto » (défaut) : une fois sa connexion
     * vérifiée auprès de Meta. Sinon, l'entreprise reste en simulation.
     */
    public function isLive(): bool
    {
        // QR code : réel dès que le téléphone est relié (sauf simulation imposée par le serveur)
        if ($this->isQr()) {
            return config('messaging.whatsapp.driver') !== 'log' && $this->status === 'connected';
        }

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
