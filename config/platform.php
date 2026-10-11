<?php

/*
 * Plateforme multi-entreprises : chaque entreprise de livraison a son adresse,
 * {slug}.PLATFORM_DOMAIN (ex. entreprise1.jibiat.com). Sans PLATFORM_DOMAIN,
 * l'installation sert une seule entreprise (BRANDING_COMPANY, sinon la première).
 */
return [
    // Domaine de la plateforme, sans « www » (ex. jibiat.com) ; vide = une seule entreprise
    'domain' => env('PLATFORM_DOMAIN'),

    // Console du super administrateur : admin.PLATFORM_DOMAIN
    'admin_subdomain' => env('PLATFORM_ADMIN_SUBDOMAIN', 'admin'),

    // Protocole des liens envoyés (WhatsApp, SMS) vers l'adresse de l'entreprise
    'scheme' => env('PLATFORM_SCHEME', 'https'),

    // Requêtes par minute sur l'espace connecté : par compte, et pour toute une entreprise
    // (une entreprise très active ne doit pas ralentir les autres)
    'limits' => [
        'user_per_minute' => (int) env('PLATFORM_USER_RATE_LIMIT', 300),
        'company_per_minute' => (int) env('PLATFORM_COMPANY_RATE_LIMIT', 3000),
    ],

    // Sous-domaines qui ne peuvent pas être attribués à une entreprise
    'reserved_subdomains' => ['www', 'admin', 'api', 'app', 'mail', 'smtp', 'ftp', 'ws', 'socket', 'static', 'cdn', 'docs', 'status', 'support', 'aide', 'blog'],
];
