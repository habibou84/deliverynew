<?php

namespace App\Enums;

/**
 * Périodicité de facturation d'un client sous contrat.
 */
enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Fortnightly = 'fortnightly';
    case Weekly = 'weekly';
    case PerDistribution = 'per_distribution';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Fin de mois',
            self::Fortnightly => 'Toutes les deux semaines',
            self::Weekly => 'Chaque semaine',
            self::PerDistribution => 'À chaque distribution',
        };
    }
}
