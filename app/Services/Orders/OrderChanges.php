<?php

namespace App\Services\Orders;

use App\Enums\FeePayer;
use App\Models\Zone;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Changements d'une course lisibles par une personne : libellé du champ, ancienne et nouvelle valeur (zones par leur
 * nom, montants en F, dates). Sert à l'aperçu avant d'enregistrer, aux modifications à valider et à la mission du
 * livreur.
 */
class OrderChanges
{
    public const LABELS = [
        'recipient_name' => 'Nom du client',
        'recipient_phone' => 'Téléphone du client',
        'recipient_phone2' => 'Autre numéro',
        'delivery_zone_id' => 'Zone de livraison',
        'delivery_address' => 'Adresse de livraison',
        'delivery_landmark' => 'Repère',
        'delivery_scheduled_date' => 'Jour de livraison',
        'delivery_time_slot' => 'Créneau',
        'pickup_zone_id' => 'Zone de ramassage',
        'pickup_address' => 'Adresse de ramassage',
        'pickup_landmark' => 'Repère du ramassage',
        'pickup_contact_name' => 'Contact du ramassage',
        'pickup_phone' => 'Téléphone du ramassage',
        'items_amount' => 'Montant des articles',
        'fee_payer' => 'Frais payés par',
        'cod_amount' => 'À encaisser',
        'delivery_fee' => 'Tarif de livraison',
        'surcharges_total' => 'Suppléments',
        'package_size' => 'Taille du colis',
        'weight_kg' => 'Poids (kg)',
        'is_fragile' => 'Fragile',
        'is_express' => 'Express',
        'description' => 'Contenu',
        'merchant_note' => 'Note',
        'merchant_reference' => 'Référence',
    ];

    /** Ce qui concerne le livreur en mission (il est prévenu quand ça change) */
    public const COURIER_FIELDS = [
        'recipient_name', 'recipient_phone', 'recipient_phone2', 'delivery_zone_id', 'delivery_address', 'delivery_landmark',
        'delivery_scheduled_date', 'delivery_time_slot', 'cod_amount', 'pickup_zone_id', 'pickup_address', 'pickup_landmark',
        'pickup_contact_name', 'pickup_phone', 'is_fragile', 'description',
    ];

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes  changements bruts (journal « edited »)
     * @param  list<string>|null  $only  champs à garder
     * @return list<array{field: string, label: string, from: ?string, to: ?string}>
     */
    public static function describe(array $changes, ?array $only = null): array
    {
        $zoneIds = collect($changes)->only(['delivery_zone_id', 'pickup_zone_id'])->flatMap(fn ($c) => [$c['from'] ?? null, $c['to'] ?? null])->filter()->unique();
        $zones = $zoneIds->isEmpty() ? collect() : Zone::withoutGlobalScopes()->with('parent')->whereIn('id', $zoneIds)->get()->keyBy('id');

        $rows = [];
        foreach ($changes as $field => $change) {
            if (! isset(self::LABELS[$field]) || ($only !== null && ! in_array($field, $only, true))) {
                continue;
            }
            $rows[] = [
                'field' => $field,
                'label' => self::LABELS[$field],
                'from' => self::value($field, $change['from'] ?? null, $zones),
                'to' => self::value($field, $change['to'] ?? null, $zones),
            ];
        }

        return $rows;
    }

    /** « Zone de livraison, Adresse de livraison » */
    public static function labels(array $changes, ?array $only = null): string
    {
        return implode(', ', array_column(self::describe($changes, $only), 'label'));
    }

    private static function value(string $field, mixed $value, $zones): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'delivery_zone_id', 'pickup_zone_id' => $zones->get($value)?->fullName() ?? (string) $value,
            'items_amount', 'cod_amount', 'delivery_fee', 'surcharges_total' => Money::format((int) $value),
            'fee_payer' => FeePayer::tryFrom((string) $value) === FeePayer::Recipient ? 'Le client' : 'L\'e-commerçant',
            'is_fragile', 'is_express' => $value ? 'Oui' : 'Non',
            'delivery_scheduled_date' => Carbon::parse($value)->locale('fr')->isoFormat('dddd D/MM'),
            default => (string) $value,
        };
    }
}
