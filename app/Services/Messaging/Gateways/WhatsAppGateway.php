<?php

namespace App\Services\Messaging\Gateways;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\MessagingException;

interface WhatsAppGateway
{
    /**
     * Envoie un modèle approuvé et renvoie l'identifiant du message chez le fournisseur.
     *
     * @param  list<string>  $params
     *
     * @throws MessagingException
     */
    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $language, array $params): string;

    /**
     * Modèles connus du fournisseur pour ce compte.
     *
     * @return list<array{name: string, language: string, status: string, category: ?string, rejected_reason: ?string}>
     *
     * @throws MessagingException
     */
    public function templates(?WhatsAppAccount $account): array;
}
