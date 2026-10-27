<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Envoi des e-mails de la plateforme par Gmail, connexion Google sans mot de passe (docs/GMAIL.md)
    'google_mail' => [
        // Ou les noms de Jibiat Marchand (GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET) ; la console a priorité
        'client_id' => env('GOOGLE_MAIL_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_MAIL_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // API publique : requêtes par minute et par clé
    'public_api' => [
        'rate_limit' => env('PUBLIC_API_RATE_LIMIT', 120),
    ],

    // Notifications push (Web Push, VAPID) : clés générées par « php artisan webpush:vapid »
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    // Webhooks sortants : en production, HTTPS uniquement et jamais vers un réseau privé
    'webhooks' => [
        'allow_http' => env('WEBHOOKS_ALLOW_HTTP', false),
        'allow_private_targets' => env('WEBHOOKS_ALLOW_PRIVATE_TARGETS', false),
        'timeout' => 10,
    ],

    // Jibiat Marchand : lien d'invitation des e-commerçants (un mois d'essai en plus via l'annuaire) ; part de
    // l'abonnement versée à l'entreprise pour un e-commerçant parrainé (affichage : les montants viennent de
    // Jibiat Marchand)
    'jibiat_marchand' => [
        'url' => rtrim((string) env('JIBIAT_MARCHAND_URL', 'https://jibiat-marchand.com'), '/'),
        'referral_rate' => (float) env('JIBIAT_MARCHAND_REFERRAL_RATE', 0.2),
        'referral_months' => (int) env('JIBIAT_MARCHAND_REFERRAL_MONTHS', 12),
        // Secret partagé avec Jibiat Marchand pour les cartes Membre Premium (contrat socle 1.6) : sur le serveur
        // seulement, jamais dans le code
        'premium_secret' => env('JIBIAT_PREMIUM_SECRET'),
        // API serveur à serveur de Jibiat Marchand (cartes Premium), sur son domaine api.
        'api_url' => rtrim((string) env('JIBIAT_MARCHAND_API_URL', 'https://api.jibiat-marchand.com'), '/'),
    ],

];
