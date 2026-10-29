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

## 2. Reprise des commandes et copie en continu

Cette fois, c'est **Jibiat qui va chercher** les données dans l'ancienne application, en lecture seule. Le module
`JibiatSyncController.php` y ouvre `GET /api/jibiat-sync/{summary|commands|products}`, protégé par une **clé de
lecture** (`jbr_…`, chiffrée dans Jibiat, mise dans le `.env` de l'ancienne application : `JIBIAT_SYNC_KEY`).

1. Télécharger le module et l'installer (explications en tête du fichier : `app/Http/Controllers`, une route dans
   `routes/api.php`, la clé dans `.env`, `php artisan config:clear`). Conseillé : un index sur `commands.updated_at`.
2. Créer la clé de lecture, indiquer l'adresse de l'ancienne application, « Vérifier ».
3. « Simuler », puis « Reprendre les commandes » (tâche de fond). Relancer ne crée pas de doublon.
4. « Comparer les soldes » : les totaux doivent être identiques, puis « Valider ces soldes ».

**Commandes reprises** : les 12 derniers mois, toutes celles en cours (toutes dates), et toutes celles dont l'argent
n'est pas soldé (paiement « encours » à l'e-commerçant, ou argent encore chez le livreur). Aucun message, alerte,
webhook ni gain de livreur. Ancien numéro sur la course (`orders.legacy_id`, cherchable) ; e-commerçant et livreur
retrouvés par leur ancien numéro ou, la première fois, par leur téléphone.

**Déroulement** : la reprise tourne sur le serveur par étapes d'une minute (file Horizon « long »), chacune gardant
son point d'arrêt ; on peut quitter la page. Une étape coupée (redémarrage, mise à jour du serveur) est relancée toute
seule au bout de 10 minutes, là où elle s'était arrêtée, sans doublon. Ancienne application injoignable : nouvel
essai, abandon après 5 échecs de suite (« Reprendre à nouveau » repart du début, les courses déjà reprises ne
bougent pas).

| Ancien état | Dans Jibiat |
|---|---|
| en cours | Validée, ou Ramassage assigné si un livreur est choisi |
| récupéré | Ramassée, colis chez le livreur ; Reportée si date de relance à venir ; Échec si motif de non-livraison |
| en chemin | En livraison, chez le livreur |
| termine | Livrée |
| annule | Annulée |
| retour | Retournée au marchand si `retour_at`, sinon Échec avec retour à faire |

**Montants** : articles = `montant − remise` (ce que touche l'e-commerçant), livraison = `livraison` à la charge du
client, à encaisser = articles + livraison. Prix gardés tels quels (pas recalculés). Commune inconnue : zone
« À préciser » (inactive).

**Argent** : la table `payments` a une ligne par commande livrée. Paiement « termine » = déjà reversé : aucune
écriture. Paiement « encours » = dû à l'e-commerçant, son montant fait foi (écart avec montant − remise signalé et
corrigé par un ajustement). Argent chez le livreur tant que `cashedback_at` est vide. Un reversement fait ensuite dans
l'ancienne application solde les écritures par un reversement « ANC-… » (sans message à l'e-commerçant).

**Copie en continu** (`legacy:sync` chaque minute) : commandes créées ou modifiées depuis la dernière copie (la
commande, son paiement, ses notes, frais, articles, relances), avec deux minutes de recouvrement. Chaque nuit
(`legacy:sync --recheck`), les courses reprises encore en cours ou non soldées sont relues. Avant la bascule,
l'ancienne application fait foi. Après, Jibiat fait foi : une course déjà là n'est plus écrasée (signalée
« Modifiée dans l'ancienne application ») ; une nouvelle commande saisie là-bas arrive et l'équipe est prévenue.

**Produits** : un produit par variante, prix et stock actuel (inventaire « Reprise de l'ancienne application »).

## 3. Bascule le jour choisi

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

Code : `app/Http/Controllers/Api/V1/LegacyImportController.php`, `LegacyOrdersController.php`, `LegacySwitchController.php`,
`app/Services/Legacy/` (`LegacyAccountsImporter`, `LegacyOrdersImporter`, `LegacyOrdersSync`, `LegacyProductsImporter`),
`legacy/laravel-5.8/` ; tests `LegacyPushImportTest`, `LegacyOrdersSyncTest`, `LegacySwitchTest`.
