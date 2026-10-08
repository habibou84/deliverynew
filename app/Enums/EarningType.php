<?php

namespace App\Enums;

enum EarningType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Return = 'return';
    // Manque constaté lors d'un versement à la caisse (montant négatif)
    case Shortfall = 'shortfall';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Ramassage',
            self::Delivery => 'Livraison',
            self::Return => 'Retour',
            self::Shortfall => 'Manque de caisse',
            self::Adjustment => 'Ajustement',
        };
    }
}
