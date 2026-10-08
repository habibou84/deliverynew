<?php

namespace App\Enums;

/**
 * Modèles WhatsApp à faire approuver par Meta (catégorie « utility », langue fr).
 * Le texte ci-dessous est celui à soumettre tel quel dans le gestionnaire WhatsApp ;
 * il sert aussi de texte pour le SMS de repli.
 */
enum WhatsAppTemplate: string
{
    case OrderConfirmed = 'course_confirmee';
    case OrderPickedUp = 'colis_recupere';
    case OutForDelivery = 'colis_en_route';
    case OrderDelivered = 'colis_livre';
    case DeliveryIncident = 'incident_livraison';
    case PayoutPaid = 'reversement_effectue';
    case ActivityReport = 'rapport_activite';

    public const LANGUAGE = 'fr';

    public function body(): string
    {
        return match ($this) {
            self::OrderConfirmed => 'Bonjour {{1}}, votre course {{2}} pour {{3}} est validée. Frais de livraison : {{4}}. Notre livreur passe bientôt récupérer le colis.',
            self::OrderPickedUp => 'Bonjour {{1}}, le colis {{2}} pour {{3}} a été récupéré par notre livreur. Il sera livré prochainement.',
            self::OutForDelivery => 'Bonjour {{1}}, votre colis de {{2}} est en route. Montant à payer au livreur : {{3}}. Code de livraison à lui donner : {{4}}. Suivi du colis : {{5}} . Merci de votre confiance.',
            self::OrderDelivered => 'Bonjour {{1}}, le colis {{2}} pour {{3}} a été livré. Montant encaissé : {{4}}. Merci de votre confiance.',
            self::DeliveryIncident => 'Bonjour {{1}}, incident sur le colis {{2}} pour {{3}} : {{4}}. Choisissez la suite depuis votre espace : {{5}} . Merci.',
            self::PayoutPaid => 'Bonjour {{1}}, votre reversement {{2}} de {{3}} a été effectué par {{4}}. Le détail est dans votre espace marchand. Merci.',
            self::ActivityReport => 'Bonjour {{1}}, voici votre point {{2}} : {{3}} courses, {{4}} livrées, {{5}} non livrées ou reportées, {{6}} retournées. Encaissé : {{7}}. Frais : {{8}}. Net : {{9}}. Merci de votre confiance.',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OrderConfirmed => 'Au marchand, quand sa course est validée',
            self::OrderPickedUp => 'Au marchand, quand le colis est récupéré',
            self::OutForDelivery => 'Au destinataire, quand le livreur part livrer (avec le code de livraison)',
            self::OrderDelivered => 'Au marchand, quand le colis est livré',
            self::DeliveryIncident => 'Au marchand, en cas d\'échec de livraison, de ramassage ou de report',
            self::PayoutPaid => 'Au marchand, quand son reversement est payé',
            self::ActivityReport => 'Au marchand, point quotidien ou hebdomadaire',
        };
    }

    /**
     * Exemples demandés par Meta lors de la soumission.
     *
     * @return list<string>
     */
    public function example(): array
    {
        return match ($this) {
            self::OrderConfirmed => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)', '1 500 F'],
            self::OrderPickedUp => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)'],
            self::OutForDelivery => ['Awa', 'Boutique Chic', '13 500 F', '4821', 'https://exemple.ci/suivi/LV-7K2M-9PQX'],
            self::OrderDelivered => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)', '13 500 F'],
            self::DeliveryIncident => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)', 'Client injoignable', 'https://exemple.ci/marchand/courses/12'],
            self::PayoutPaid => ['Boutique Chic', 'RV-2610-0004', '154 000 F', 'Wave'],
            self::ActivityReport => ['Boutique Chic', 'du 08/10/2026', '24', '20', '3', '1', '310 000 F', '36 000 F', '274 000 F'],
        };
    }

    public function parameterCount(): int
    {
        return count($this->example());
    }

    /**
     * Texte final, paramètres remplacés.
     *
     * @param  list<string>  $params
     */
    public function render(array $params): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $params[(int) $m[1] - 1] ?? '', $this->body());
    }
}
