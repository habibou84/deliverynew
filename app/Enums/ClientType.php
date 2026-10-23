<?php

namespace App\Enums;

/**
 * Relation avec le client : sous contrat (dans la durée, facturé par période) ou ponctuel (une distribution à la
 * fois, réglée à la distribution).
 */
enum ClientType: string
{
    case Contract = 'contract';
    case Occasional = 'occasional';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Sous contrat',
            self::Occasional => 'Ponctuel',
        };
    }
}
