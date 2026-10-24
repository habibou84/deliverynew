# Reprise de l'ancienne application (Laravel 5.8) et bascule

Deux temps, tous deux dans **Réglages › Intégrations › Reprise de l'ancienne application** (administrateur).

## 1. Reprise des comptes

D'abord les **noms des zones** (table `fees` : seulement la colonne `destination`, nettoyée ; ni prix ni tarifs, la
grille de Jibiat ne change pas ; une zone déjà présente n'est pas recréée, la ligne d'expédition est ignorée). Puis les
e-commerçants (table `clients`) et les livreurs (`livreurs`) arrivent avec leur compte de connexion (`users` de type
`client` ou `livreur`) : même numéro, même mot de passe. Les commandes et les comptes de l'équipe ne sont pas repris
(l'équipe se recrée dans Utilisateurs). Rien n'est modifié dans l'ancienne base.

1. Les zones arrivent en premier : la commune de chaque e-commerçant choisit sa zone de ramassage. Les tarifs se
   fixent ensuite dans Tarifs.
2. Télécharger `JibiatMigrationController.php` et l'installer dans l'ancienne application (explications en tête du
   fichier : le copier dans `app/Http/Controllers`, ajouter deux routes).
3. Créer une **clé de reprise** (valable 7 jours) et la coller, avec l'adresse de l'espace Jibiat, dans la page
   `/jibiat-migration` de l'ancienne application.
4. « Simuler », vérifier, puis « Envoyer ». Relancer ne crée pas de doublon (un numéro déjà présent est ignoré).

Sans accès à l'ancienne application : `php artisan legacy:import` lit un export SQL.

## 2. Bascule le jour choisi

À partir de la date choisie par l'administrateur, un e-commerçant ou un livreur connecté à l'ancienne application est
envoyé sur Jibiat **déjà connecté** à son espace (`/marchand` ou `/livreur`) : ni nouveau compte, ni mot de passe à
retaper. Avant la date, rien ne change. Les comptes de l'équipe ne sont jamais envoyés.

1. Télécharger `JibiatSwitch.php` et l'installer dans l'ancienne application : le copier dans `app/Http/Middleware`,
   l'ajouter au groupe `web` de `app/Http/Kernel.php`.
2. Créer une **clé de bascule** (sans date de fin, désactivable) et mettre les deux lignes affichées dans le `.env` de
   l'ancienne application (`JIBIAT_URL`, `JIBIAT_SWITCH_KEY`), puis `php artisan config:clear`.
3. Choisir le jour et l'heure (ou « Basculer maintenant »). La date se déplace ou s'annule depuis Jibiat ; l'ancienne
   application la redemande au plus tous les quarts d'heure.

Fonctionnement : à chaque page ouverte par un compte `client` ou `livreur`, l'ancienne application demande à
`POST /api/legacy-switch` (clé de bascule) le lien du compte. Avant la date, la réponse donne la date ; après, un lien
`/bascule/{ticket}` à usage unique valable deux minutes, qui ouvre la session sur Jibiat (jeton dans le fragment
`#session=…`, jamais envoyé au serveur). Un compte créé dans l'ancienne application après la reprise est repris à ce
moment-là, avec sa fiche. Un compte suspendu sur Jibiat, ou un serveur injoignable : l'ancienne application continue
normalement. Les appels JSON (application mobile) ne sont pas redirigés.

Le réglage affiche le nombre d'e-commerçants et de livreurs déjà passés par la bascule (`users.legacy_switched_at`).

Code : `app/Http/Controllers/Api/V1/LegacyImportController.php`, `LegacySwitchController.php`,
`app/Services/Legacy/LegacyAccountsImporter.php`, `legacy/laravel-5.8/` ; tests `LegacyPushImportTest`,
`LegacySwitchTest`.
