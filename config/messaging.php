<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    |
    | « log » : aucun envoi réel, les messages sont écrits dans le journal
    | (développement, démonstration). « meta » : API Cloud de Meta, avec le
    | numéro configuré par chaque entreprise dans Paramètres > WhatsApp.
    |
    */

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        // Application Meta : signature des webhooks et vérification de l'abonnement
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS de repli
    |--------------------------------------------------------------------------
    |
    | Envoyé quand un message WhatsApp n'a pas pu être remis (numéro sans
    | WhatsApp, échec définitif). « log », « twilio » ou « none » (désactivé).
    |
    */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    // Envois : 3 tentatives, espacées de 30 s puis 2 min
    'tries' => 3,
    'backoff' => [30, 120],

];
