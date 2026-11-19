<?php

namespace App\Enums;

/**
 * Pourquoi un colis repart chez l'e-commerçant (contrat 1.11, champ « return_cause »). Le retour n'est « demandé »
 * par l'e-commerçant que pour Merchant : les autres viennent du client ou de l'agence.
 */
enum ReturnCause: string
{
    case Refused = 'refused';
    case MaxAttempts = 'max_attempts';
    case Company = 'company';
    case Merchant = 'merchant';

    /** Pour l'agence */
    public function label(): string
    {
        return match ($this) {
            self::Refused => 'Refusé par le client',
            self::MaxAttempts => 'Tentatives épuisées',
            self::Company => 'Retour décidé par l\'agence',
            self::Merchant => 'Retour demandé par l\'e-commerçant',
        };
    }

    /** Pour l'e-commerçant */
    public function merchantLabel(): string
    {
        return match ($this) {
            self::Refused => 'Refusé par le client : le colis vous sera retourné',
            self::MaxAttempts => 'Tentatives épuisées : le colis vous sera retourné',
            self::Company => 'L\'agence vous retourne le colis',
            self::Merchant => 'Vous avez demandé le retour',
        };
    }
}
