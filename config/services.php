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

    // Webhooks sortants : en production, HTTPS uniquement et jamais vers un réseau privé
    'webhooks' => [
        'allow_http' => env('WEBHOOKS_ALLOW_HTTP', false),
        'allow_private_targets' => env('WEBHOOKS_ALLOW_PRIVATE_TARGETS', false),
        'timeout' => 10,
    ],

];
