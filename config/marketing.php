<?php

/*
 * Site de présentation (adresse principale de la plateforme, ex. jibiat.com).
 */
return [

    // Numéro WhatsApp commercial : bouton « Demander une démo » et pied de page
    'whatsapp' => env('MARKETING_WHATSAPP', '+2250703686876'),

    // Avis de clients affichés sur l'accueil
    'testimonials' => [
        ['author' => 'Express DS', 'quote' => 'Application très pratique, j\'ai multiplié ma productivité par 2.'],
        ['author' => 'Tivaya', 'quote' => 'Je ne passe plus des nuits à faire des points. Tout est automatisé. Quel bonheur !'],
    ],
];
