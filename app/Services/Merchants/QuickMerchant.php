<?php

namespace App\Services\Merchants;

use App\Enums\MerchantStatus;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Accounts\Invitations;
use App\Services\Messaging\Messenger;
use App\Support\PhoneNumber;

/**
 * E-commerçant créé par l'équipe pendant l'enregistrement d'une course : la boutique et son compte (sans mot de
 * passe), puis un seul message de bienvenue avec le code de la course et un lien pour ouvrir son espace. Ce message
 * remplace « course validée » pour cette première course.
 */
class QuickMerchant
{
    /** @var array<int, true> courses dont la confirmation est remplacée par la bienvenue */
    private array $welcomed = [];

    public function __construct(
        private readonly Invitations $invitations,
        private readonly Messenger $messenger,
    ) {}

    /**
     * @param  array{business_name: string, phone: string, contact_name?: ?string, pickup_zone_id?: ?int}  $data
     */
    public function create(User $by, array $data): Merchant
    {
        $phone = PhoneNumber::normalize($data['phone']);
        $existing = Merchant::query()->where('phone', $phone)->first();
        if ($existing !== null) {
            throw new BusinessRuleException("Ce numéro est déjà celui de {$existing->business_name}.", 'new_merchant.phone');
        }
        if (User::takenIn($by->company_id, 'phone', $phone)) {
            throw new BusinessRuleException('Ce numéro est déjà utilisé par un compte de l\'entreprise.', 'new_merchant.phone');
        }

        $contact = filled($data['contact_name'] ?? null) ? $data['contact_name'] : $data['business_name'];
        $merchant = Merchant::create([
            'company_id' => $by->company_id,
            'business_name' => $data['business_name'],
            'contact_name' => $contact,
            'phone' => $phone,
            'pickup_zone_id' => $data['pickup_zone_id'] ?? null,
            'status' => MerchantStatus::Active,
        ]);

        // Connexion par WhatsApp ou par le lien de bienvenue : pas de mot de passe
        $owner = new User(['company_id' => $by->company_id, 'merchant_id' => $merchant->id, 'name' => $contact, 'phone' => $phone]);
        $owner->forceFill(['password' => null])->save();
        $owner->assignRole(Role::MerchantOwner->value);

        return $merchant;
    }

    /** La confirmation de cette course part dans le message de bienvenue */
    public function holdConfirmation(Order $order): void
    {
        $this->welcomed[$order->id] = true;
    }

    public function confirmationHeld(Order $order): bool
    {
        return isset($this->welcomed[$order->id]);
    }

    /**
     * Lien de bienvenue ; envoyé par WhatsApp quand le numéro de l'entreprise le permet, sinon à envoyer depuis le
     * téléphone de l'équipe.
     *
     * @return array{sent: bool, url: string, text: string, whatsapp_link: string, phone: string}
     */
    public function welcome(Merchant $merchant, Order $order, User $by): array
    {
        $owner = User::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $url = $this->invitations->create($owner, $by)['url'];
        $params = [$merchant->business_name, $merchant->company->name, $order->tracking_code, $url];
        $text = WhatsAppTemplate::MerchantWelcome->render($params);

        $sent = $this->canSend($merchant->company_id)
            && $this->messenger->whatsapp($merchant->company_id, $merchant->phone, 'merchant', WhatsAppTemplate::MerchantWelcome, $params,
                ['event' => 'merchant.welcome', 'merchant_id' => $merchant->id, 'order_id' => $order->id]) !== null;

        return [
            'sent' => $sent,
            'url' => $url,
            'text' => $text,
            'whatsapp_link' => 'https://wa.me/'.ltrim($merchant->phone, '+').'?text='.rawurlencode($text),
            'phone' => PhoneNumber::display($merchant->phone),
        ];
    }

    /** Numéro de l'entreprise relié, et modèle approuvé par Meta (le QR code envoie du texte libre) */
    private function canSend(int $companyId): bool
    {
        $account = WhatsAppAccount::forCompanyId($companyId);
        if ($account === null || ! $account->isLive()) {
            return false;
        }

        return $account->isQr() || $account->templates()
            ->where('name', WhatsAppTemplate::MerchantWelcome->value)->where('language', WhatsAppTemplate::LANGUAGE)
            ->where('status', 'approved')->exists();
    }
}
