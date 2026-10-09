<?php

namespace App\Enums;

enum EarningType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Return = 'return';
    // Manque constaté lors d'un versement à la caisse (montant négatif)
    case Shortfall = 'shortfall';
    // Retenue pour un colis perdu par le livreur (montant négatif)
    case LostParcel = 'lost_parcel';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Ramassage',
            self::Delivery => 'Livraison',
            self::Return => 'Retour',
            self::Shortfall => 'Manque de caisse',
            self::LostParcel => 'Retenue colis perdu',
            self::Adjustment => 'Ajustement',
        };
    }
}
