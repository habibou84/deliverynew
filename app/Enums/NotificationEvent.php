<?php

namespace App\Enums;

/**
 * Événements pour lesquels un marchand peut recevoir un message WhatsApp (ou une notification sur son téléphone).
 * Les notifications dans l'application sont toujours envoyées. L'agence choisit pour chaque étape
 * (Company::merchantWantsByDefault) et peut empêcher l'e-commerçant de changer.
 */
enum NotificationEvent: string
{
    // Course enregistrée par l'e-commerçant et validée automatiquement
    case OrderReceived = 'order.received';
    case OrderConfirmed = 'order.confirmed';
    case OrderPickedUp = 'order.picked_up';
    case OrderDelivered = 'order.delivered';
    case OrderIncident = 'order.incident';
    case PayoutPaid = 'payout.paid';
    case ReturnSlip = 'order.returned';

    public function label(): string
    {
        return match ($this) {
            self::OrderReceived => 'Course reçue et validée',
            self::OrderConfirmed => 'Course validée par l\'agence',
            self::OrderPickedUp => 'Colis récupéré',
            self::OrderDelivered => 'Colis livré',
            self::OrderIncident => 'Incident (échec, report)',
            self::PayoutPaid => 'Reversement effectué',
            self::ReturnSlip => 'Retour de colis (bon de retour)',
        };
    }

    /**
     * Réglage par défaut : on évite d'inonder le marchand de messages de routine.
     */
    public function enabledByDefault(): bool
    {
        return match ($this) {
            self::OrderReceived, self::OrderConfirmed, self::OrderPickedUp => false,
            default => true,
        };
    }
}
