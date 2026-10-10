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

    // Sous-domaines qui ne peuvent pas être attribués à une entreprise
    'reserved_subdomains' => ['www', 'admin', 'api', 'app', 'mail', 'smtp', 'ftp', 'ws', 'socket', 'static', 'cdn', 'docs', 'status', 'support', 'aide', 'blog'],
];
