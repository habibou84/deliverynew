<?php

return [
    /*
    | PayDunya : paiements des e-commerçants (live, forfait du mois, fiches IA) par Orange Money, MTN, Moov, Wave
    | ou carte. Les clés se trouvent dans le tableau de bord PayDunya (Intégrez notre API) ; elles ne vont que dans
    | le fichier .env du serveur. Mode « test » : environnement de test de PayDunya (aucun argent réel).
    */
    'paydunya' => [
        'master_key' => env('PAYDUNYA_MASTER_KEY'),
        'private_key' => env('PAYDUNYA_PRIVATE_KEY'),
        'token' => env('PAYDUNYA_TOKEN'),
        'mode' => env('PAYDUNYA_MODE', 'test'),
        'store_name' => env('PAYDUNYA_STORE_NAME', 'Jibiat'),
        'url' => env('PAYDUNYA_URL', 'https://app.paydunya.com'),
    ],
];
