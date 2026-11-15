<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;

/**
 * Réglages du prépayé, modifiables dans la console (Console › Factures) : bandeau et délai de grâce après l'échéance
 * d'un abonnement, jours de préavis de la facture payée d'avance (mois, semaine), taille du pack de courses.
 * Tant que la console n'a rien enregistré : 1 jour, 3 jours, 5 jours, 2 jours, 100 courses.
 */
class BillingSettings
{
    public const DEFAULTS = ['warning_days' => 1, 'grace_days' => 3, 'notice_days' => 5, 'weekly_notice_days' => 2, 'orders_pack' => 100];

    /** Bornes de chaque réglage (console) */
    public const LIMITS = ['warning_days' => [0, 30], 'grace_days' => [0, 60], 'notice_days' => [0, 20], 'weekly_notice_days' => [0, 6], 'orders_pack' => [1, 100000]];

    /** @return array{warning_days: int, grace_days: int, notice_days: int, weekly_notice_days: int, orders_pack: int} */
    public static function all(): array
    {
        $stored = json_decode((string) PlatformSetting::get('billing.settings'), true) ?: [];
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            [$min, $max] = self::LIMITS[$key];
            $values[$key] = max($min, min($max, (int) ($stored[$key] ?? $default)));
        }

        return $values;
    }

    public static function get(string $key): int
    {
        return self::all()[$key];
    }

    /** @param  array<string, int>  $values */
    public static function update(array $values): array
    {
        PlatformSetting::put('billing.settings', json_encode([...self::all(), ...array_map('intval', array_intersect_key($values, self::DEFAULTS))]));

        return self::all();
    }
}
