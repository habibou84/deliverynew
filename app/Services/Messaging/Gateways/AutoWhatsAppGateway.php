<?php

namespace App\Services\Messaging\Gateways;

use App\Models\WhatsAppAccount;

/**
 * Envoi réel pour les numéros connectés (API Cloud de Meta, ou QR code en attendant Meta), simulation (journal)
 * pour les autres : chaque entreprise sort du mode démo dès que son numéro est prêt.
 */
class AutoWhatsAppGateway implements WhatsAppGateway
{
    public function __construct(
        private readonly MetaCloudGateway $meta,
        private readonly LogWhatsAppGateway $log,
        private readonly ?QrBridgeGateway $qr = null,
    ) {}

    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $language, array $params): string
    {
        return $this->for($account)->sendTemplate($account, $to, $template, $language, $params);
    }

    public function sendReply(?WhatsAppAccount $account, string $to, string $text, array $buttons = []): string
    {
        return $this->for($account)->sendReply($account, $to, $text, $buttons);
    }

    public function templates(?WhatsAppAccount $account): array
    {
        return $this->for($account)->templates($account);
    }

    private function for(?WhatsAppAccount $account): WhatsAppGateway
    {
        if (! $account?->isLive()) {
            return $this->log;
        }

        return $account->isQr() && $this->qr !== null ? $this->qr : $this->meta;
    }
}
