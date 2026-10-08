<?php

namespace App\Support;

class Money
{
    /**
     * 12500 → « 12 500 F » (espace ordinaire : certains téléphones affichent mal l'espace insécable dans les SMS).
     */
    public static function format(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' F';
    }
}
