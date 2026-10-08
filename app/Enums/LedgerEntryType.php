<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    // Montant encaissé auprès du destinataire, dû au marchand
    case CodCredit = 'cod_credit';
    // Frais de livraison retenus
    case DeliveryFee = 'delivery_fee';
    // Frais facturés pour un colis retourné
    case ReturnFee = 'return_fee';
    case Adjustment = 'adjustment';
    // Reversement effectué : solde les écritures du relevé
    case Payout = 'payout';

    public function label(): string
    {
        return match ($this) {
            self::CodCredit => 'Encaissement',
            self::DeliveryFee => 'Frais de livraison',
            self::ReturnFee => 'Frais de retour',
            self::Adjustment => 'Ajustement',
            self::Payout => 'Reversement',
        };
    }
}
