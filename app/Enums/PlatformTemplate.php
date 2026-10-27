<?php

namespace App\Enums;

/**
 * Modèles WhatsApp du numéro officiel de Jibiat (console › WhatsApp), langue fr. Ce sont les messages que Jibiat
 * envoie en premier : Meta n'accepte un texte libre que dans les 24 heures qui suivent le dernier message de la
 * personne, au-delà il faut un modèle approuvé. Le code de connexion est un modèle « authentification » (texte fixé
 * par Meta, bouton Copier le code) ; les autres sont des modèles « utility ».
 */
enum PlatformTemplate: string
{
    case ConsoleCode = 'jibiat_code_connexion';
    case ConsoleLogin = 'jibiat_connexion_console';
    case WavePayment = 'jibiat_paiement_wave';
    case SupportAccess = 'jibiat_acces_assistance';

    public const LANGUAGE = 'fr';

    /** Durée de validité affichée sous le code (minutes) */
    public const CODE_MINUTES = 10;

    public function category(): string
    {
        return $this === self::ConsoleCode ? 'authentication' : 'utility';
    }

    /**
     * Texte soumis à Meta (modèles « utility »). Le modèle d'authentification n'a pas de texte libre : Meta affiche
     * « {code} est votre code de vérification », la recommandation de sécurité et la durée de validité.
     */
    public function body(): string
    {
        return match ($this) {
            self::ConsoleCode => '*{{1}}* est votre code de vérification. Pour votre sécurité, ne le communiquez à personne. Ce code expire dans '.self::CODE_MINUTES.' minutes.',
            self::ConsoleLogin => 'Jibiat · Nouvelle connexion à la console : {{1}}, {{2}}, adresse IP {{3}}. Ce n\'était pas vous ? Sécurisez votre compte ici : {{4}} .',
            self::WavePayment => 'Jibiat · {{1}} : {{2}} depuis le numéro Wave {{3}}, pour {{4}} (facture {{5}}). Vérifiez dans Wave que le montant est arrivé, puis confirmez ici : {{6}} .',
            self::SupportAccess => 'Jibiat · L\'assistance Jibiat a ouvert l\'espace de {{1}}. Par : {{2}}, jusqu\'à {{3}}. Motif : {{4}}. Le détail est dans Réglages › Accès de l\'assistance.',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ConsoleCode => 'Code de connexion à la console (bouton Copier le code)',
            self::ConsoleLogin => 'Alerte de nouvelle connexion à la console',
            self::WavePayment => 'Alerte de paiement Wave au validateur',
            self::SupportAccess => 'Aux administrateurs, quand l\'assistance ouvre leur espace',
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
            self::ConsoleCode => ['482913'],
            self::ConsoleLogin => ['mardi 27/10 à 14:05', 'Chrome sur Android', '102.67.12.4', 'https://admin.jibiat.com/console/mes-sessions'],
            self::WavePayment => ['Paiement Wave déclaré payé', '45 000 F', '07 09 98 08 85', 'Rapide Express', 'FAC-202610-0012', 'https://admin.jibiat.com/console/paiements-wave'],
            self::SupportAccess => ['Rapide Express', 'Awa (Jibiat)', '16:30', 'Aide à la configuration des zones'],
        };
    }

    /**
     * Texte final, paramètres remplacés (texte libre dans les 24 heures, ou sans modèle approuvé).
     *
     * @param  list<string>  $params
     */
    public function render(array $params): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $params[(int) $m[1] - 1] ?? '', $this->body());
    }

    /**
     * Composants soumis à Meta.
     *
     * @return list<array<string, mixed>>
     */
    public function components(): array
    {
        if ($this === self::ConsoleCode) {
            return [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => self::CODE_MINUTES],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copier le code']]],
            ];
        }

        return [['type' => 'BODY', 'text' => $this->body(), 'example' => ['body_text' => [$this->example()]]]];
    }

    /**
     * Paramètres d'un modèle : une ligne chacun (Meta refuse les retours à la ligne et les longues suites d'espaces).
     *
     * @param  list<string>  $params
     * @return list<string>
     */
    public static function clean(array $params): array
    {
        return array_map(fn ($p) => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $p)), 0, 300) ?: '—', $params);
    }
}
