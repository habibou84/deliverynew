# Reprise de l'ancienne application (Laravel 5.8) et bascule

Tout se fait dans **Réglages › Reprise de l'ancienne application** (administrateur). La page d'accueil montre
l'état en une ligne (copie, bascule), un encadré « À faire » (supprimées à vérifier, courses en chemin depuis plus de
30 jours, soldes à valider, point de départ de l'argent des livreurs) et cinq étapes, chacune sur sa page :

| Étape | Adresse | Contenu |
|---|---|---|
| 1. Brancher l'ancienne application | `/admin/reglages/reprise/brancher` | les trois modules et leurs clés (lecture des commandes, envoi des comptes, bascule), l'adresse, « Vérifier » |
| 2. Comptes et zones | `/admin/reglages/reprise/comptes` | ce qui est repris, dernier envoi reçu |
| 3. Commandes | `/admin/reglages/reprise/commandes` | simulation, reprise, copie chaque minute |
| 4. Contrôles et ménage | `/admin/reglages/reprise/controles` | zones et tarifs (`/reprise/zones`, `/reprise/tarifs`), recomparaison, contrôle des soldes, argent chez les livreurs, anciennes courses à clôturer |
| 5. Bascule vers Jibiat | `/admin/reglages/reprise/bascule` | date et suivi de la bascule |

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

### Comptes tenus à jour chaque minute (module de lecture version 4)

Avec le module de lecture des commandes (`JibiatSyncController.php`) en version 4 ou plus, la copie de chaque minute
commence par les comptes (`commands?accounts_since=…` : clients, livreurs et leurs `users` créés ou modifiés depuis la
dernière copie). Les nouveaux comptes sont repris comme à l'envoi par bouton ; **jusqu'à la bascule**, une fiche venue
de la reprise (source `import` ou `legacy_order`) et modifiée dans l'ancienne application est mise à jour (nom,
adresse, commune, numéro s'il est libre). Après la bascule, Jibiat fait foi et ne la touche plus. La version du module
est relue au plus une fois par heure ; avant la version 4, rien ne change.

À la commande aussi, le module v4 envoie le compte complet de l'e-commerçant (`client_users`) et du livreur
(`livreur_users`) : un compte inconnu est repris aussitôt, avec son mot de passe.

**Secours** (module plus ancien) : un e-commerçant inconnu est créé depuis la commande (nom, numéro, commune ; source
`legacy_order`, sans compte de connexion, avec un avertissement dans le rapport). Au prochain envoi de ses comptes, la
fiche reçoit son compte et son mot de passe, sans doublon.

**Commandes en attente d'un compte** (`legacy_pending_commands`) : une commande dont l'e-commerçant ne peut pas être
repris (numéro absent ou déjà pris) est gardée de côté, relancée à chaque copie, puis retirée de la liste une fois
reprise. Elle apparaît dans « Comptes et zones » et dans les « À faire » de la reprise ; le bouton « Copier les comptes
maintenant » lance la copie et la relance tout de suite (`POST company/legacy-orders/accounts/sync`).

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

**Ordre** : des plus récentes aux plus anciennes (jour de livraison, sinon jour de création), pour que les courses
du jour soient là en premier. Il faut le module à jour (`JibiatSyncController.php` téléchargé de nouveau) ; avec
l'ancien module, la reprise se fait par numéro et l'écran le signale.

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
| undelivered (ou « non livré ») | Échec de livraison avec la raison (`why_undelivered`), file « À décider », colis chez le livreur (au dépôt sans livreur) ; Reportée si date de relance à venir |
| termine | Livrée |
| annule | Annulée |
| retour | Retournée au marchand si `retour_at`, sinon Échec avec retour à faire |

**Montants** : articles = `montant − remise` (ce que touche l'e-commerçant), livraison = `livraison` à la charge du
client, à encaisser = articles + livraison. Prix gardés tels quels (pas recalculés). Commune inconnue : zone
« À préciser » (inactive).

**Argent** : la table `payments` a une ligne par commande livrée. Paiement « termine » = déjà reversé : aucune
écriture. Paiement « encours » = dû à l'e-commerçant, son montant fait foi (écart avec montant − remise signalé et
corrigé par un ajustement). Argent chez le livreur tant que `cashedback_at` est vide **et** que le paiement n'est pas
« termine » : un paiement terminé à l'e-commerçant vaut remise du livreur (réglage « Un paiement terminé vaut remise de
l'argent par le livreur », activé par défaut, dans Contrôles ; à couper si l'entreprise paie parfois avant que le
livreur ait rendu l'argent ; s'applique aux copies suivantes). Un reversement fait ensuite dans l'ancienne application
solde les écritures par un reversement « ANC-… » (sans message à l'e-commerçant).

Ce qui est soldé dans l'ancienne application (reversements `ANC-…`, encaissements « Remis dans l'ancienne application »
ou « Payé à l'e-commerçant dans l'ancienne application ») n'entre pas dans la clôture de caisse du jour. L'étape
Contrôles affiche ce qui a été soldé aujourd'hui et en tout. Le contrôle des soldes applique la même règle (le module
envoie `cash_paid_by_livreur` ; un module plus ancien ne l'envoie pas et laisse un écart sur ces courses).

**Copie en continu** (`legacy:sync` chaque minute) : d'abord les nouvelles commandes, par numéro (plus grand que le
dernier repris), quelles que soient leurs dates ; puis les commandes créées ou modifiées depuis la dernière copie (la
commande, son paiement, ses notes, frais, articles, relances), avec deux minutes de recouvrement. Puis un paquet de
400 courses encore en cours (ou dont l'argent n'est pas soldé) est relu par leur numéro, à tour de rôle : une
commande changée là-bas sans mise à jour de sa date de modification (requête directe de l'ancienne application, ou
base et application réglées sur deux fuseaux différents) arrive quand même en quelques minutes. « Copier
maintenant » relit toutes les courses en cours d'un coup. Le module version 3 part de l'heure la plus ancienne
entre la base et l'application pour la copie suivante : le retélécharger et le remplacer (l'écran « Vérifier »
signale un module pas à jour).

**Code 429** : Laravel limite les adresses de `routes/api.php` à 60 demandes par minute. Jibiat demande les numéros par
paquets de 500 et « Copier maintenant » relit au plus 2 000 courses en cours d'un coup ; en tâche de fond (copie de
chaque minute, reprise, relecture de la nuit), il attend le temps demandé par l'ancienne application puis réessaie.
Pour lever la limite : déplacer la ligne `jibiat-sync` de `routes/api.php` vers `routes/web.php` avec l'adresse
`api/jibiat-sync/{resource}` (voir l'en-tête du module), ou passer `throttle:60,1` à `throttle:300,1` dans
`app/Http/Kernel.php` de l'ancienne application. Chaque nuit
(`legacy:sync --recheck`), les courses reprises encore en cours ou non soldées sont relues. Avant la bascule,
l'ancienne application fait foi. Après, Jibiat fait foi : une course déjà là n'est plus écrasée (signalée
« Modifiée dans l'ancienne application ») ; une nouvelle commande saisie là-bas arrive et l'équipe est prévenue.

**Recomparaison** (même page, « Comparer les commandes à partir du », puis « Lancer la recomparaison ») : relit les
courses reprises créées dans l'ancienne application depuis la date choisie (par défaut le jour du premier lancement de
la reprise), en deux temps : d'abord toutes les commandes créées ou modifiées dans l'ancienne application depuis
cette date (les nouvelles, jamais arrivées, sont créées ; les modifiées mises à jour), puis les courses déjà dans
Jibiat, des plus récentes aux plus anciennes, par paquets, en arrière-plan (reprend seule après une coupure). Les modifiées sont mises à jour comme par la
copie de chaque minute (y compris les courses terminées ici, modifiées là-bas sans date de mise à jour). Une commande
**effacée** dans l'ancienne application (elle n'est plus renvoyée) :

| Cas | Action |
|---|---|
| Pas livrée, pas chez un livreur, aucun argent en jeu (avant la bascule) | Annulée sans message, note « Supprimée dans l'ancienne application » ; annulable 7 jours (elle passe alors à vérifier) |
| Déjà annulée ou retournée, aucun argent en jeu | Rien (note seulement) |
| Livrée, argent chez un livreur ou dû à l'e-commerçant, colis chez un livreur, ou après la bascule | « Supprimées à vérifier » (Réglages › Reprise) : Garder, ou Annuler (l'argent pas encore réglé disparaît ; refusé si déjà passé à la caisse ou reversé) |

Si l'ancienne application ne renvoie aucune commande d'un paquet (base vide, mauvaise adresse), la recomparaison
s'arrête sans rien supprimer. La relecture de chaque nuit (`legacy:sync --recheck`) applique les mêmes règles aux
courses encore en cours.

**Nettoyage des anciennes courses** (même page, une fois la reprise terminée) : courses venues de l'ancienne
application, pas terminées, dont le jour de livraison est passé depuis plus de 30 jours (7 à 90 au choix). Simulation
d'abord, puis « Clôturer » (taper CLÔTURER), en arrière-plan, sans message, webhook, gain livreur ni facture :

| Cas | Action |
|---|---|
| Jamais ramassées | Annulées |
| Ramassées, au dépôt, en échec, reportées | Retournées à l'e-commerçant ; colis encore chez un livreur : retour décidé, à rendre (point de caisse du livreur) |
| En chemin | Rien : liste « à vérifier » livreur par livreur (Réglages › Reprise › À vérifier), « Livrées » (argent au point de caisse du livreur, dû à l'e-commerçant) ou « Retournées » |
| Livrées, argent pas soldé | Pas touchées (caisse) |

Chaque course garde une note « Clôturée lors du nettoyage de la reprise » et n'est plus recopiée depuis l'ancienne
application. Un nettoyage s'annule pendant 7 jours (les courses traitées depuis restent comme elles sont) ; les
vérifications « en chemin » ne s'annulent pas.

**Argent chez les livreurs : point de départ** (Réglages › Reprise › Argent chez les livreurs). Toutes les
entreprises ne remplissaient pas « encaisser le livreur » dans l'ancienne application : des mois de livraisons
paraissent encore chez les livreurs. On choisit une date (conseillé : la date de bascule) ; avant elle, l'argent est
compté comme remis. Pour chaque livreur, après avoir compté avec lui : « Tout remis » (il garde seulement ce qui a été
encaissé depuis la date) ou le montant qu'il a vraiment (Jibiat garde ses encaissements les plus récents qui tiennent
dans ce montant). Taper VALIDER. Rien ne bouge dans la caisse (aucun versement, aucun message) et ce qui est dû aux
e-commerçants ne change pas ; chaque course garde une note « Argent remis dans l'ancienne application (point de
départ) ». Un encaissement réglé ainsi ne revient plus chez le livreur avec la copie en continu. Annulable 7 jours.
Le contrôle des soldes livreurs montre ensuite un écart avec l'ancienne application : c'est attendu.

**Produits** : un produit par variante, prix et stock actuel (inventaire « Reprise de l'ancienne application »).

### Dates des courses reprises

Une seule date compte dans Jibiat : la **date de livraison** (liste du jour, point du jour, tableau de bord,
statistiques). Pour une commande de l'ancienne application, c'est sa date de livraison, ou celle du dernier report
quand elle a été reportée plus tard (`relance_date`). La **première date prévue** (avant le premier report) est gardée
à part, figée, et affichée sur la fiche (« Prévue d'abord le … »). L'heure de livraison d'une commande terminée est
celle de la création du paiement de l'e-commerçant, sinon midi le jour de la livraison : une remise d'argent ou une
correction faite plus tard dans l'ancienne application ne la déplace plus. La recomparaison (complète ou de nuit) remet
ces dates d'accord sur les courses déjà reprises, même après la bascule, sans toucher à leur état ni à leur argent ;
son bilan indique combien de dates ont été corrigées.

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

### Avant la bascule : bandeau dans l'ancienne application (module version 2)

Réglages › Reprise › Bascule › « Montrer Jibiat dans l'ancienne application » : un bandeau s'affiche en bas des pages
HTML de l'ancienne application, activé **public par public** (coupé par défaut) :

| Public | Bandeau | Bouton |
|---|---|---|
| Équipe (tout compte autre que `client` et `livreur`) | « La nouvelle application Jibiat est ouverte… » | « Ouvrir Jibiat » : l'espace Jibiat de l'entreprise (`/admin`), nouvel onglet ; connexion habituelle |
| E-commerçants (`client`) | message réglable (sinon un message par défaut) | « Ouvrir la nouvelle application » : espace Jibiat **déjà connecté**, nouvel onglet, sans quitter l'ancienne application |
| Livreurs (`livreur`) | idem | idem |

La date de bascule, si elle est fixée, est rappelée dans le bandeau. Il se ferme d'un clic pour la visite (onglet).
Après la date, les e-commerçants et les livreurs sont envoyés sur Jibiat : seul le bandeau de l'équipe reste.

Fonctionnement : le module lit les réglages avec `POST /api/legacy-switch` en `mode=config` (gardés 5 minutes) ; le
bouton ajoute `?jibiat_ouvrir=1` à la page, et le module demande alors un lien en `mode=open`, accordé avant la date
seulement si le bandeau est montré à ce public. Le module envoie sa version (`module_version`) : le réglage
« Brancher l'ancienne application » dit si le module installé est à jour. Mise à jour : remplacer le fichier
`app/Http/Middleware/JibiatSwitch.php`, rien d'autre à changer. Un module version 1 continue de faire la bascule à la
date, sans bandeau.

Code : `app/Http/Controllers/Api/V1/LegacyImportController.php`, `LegacyOrdersController.php`, `LegacySwitchController.php`,
`app/Services/Legacy/` (`LegacyAccountsImporter`, `LegacyOrdersImporter`, `LegacyOrdersSync`, `LegacyProductsImporter`),
`legacy/laravel-5.8/` ; tests `LegacyPushImportTest`, `LegacyOrdersSyncTest`, `LegacySwitchTest`.

## Zones standard et tarifs

Les communes et quartiers de chaque entreprise viennent de la liste commune du socle (`Jibiat\Socle\Places\Places`),
la même que dans Jibiat Marchand : chaque zone porte le code de son lieu (`zones.place_code`), les villes de
l'intérieur arrivent masquées. L'entreprise ne crée plus que ses zones à elle (expédition, lieu absent de la liste).

**Reprise des zones** (table `fees`) : un nom qui est exactement un lieu de la liste (nom ou variante : « YOP »,
« Riv 3 ») prend la zone standard de ce lieu (l'ancien nom est retenu comme variante) ; sinon une zone propre à
l'entreprise est créée. Le prix de l'ancienne application est gardé sur la zone (`legacy_price`), sans rien
appliquer. Relancer l'envoi des zones remplit ces prix pour une reprise déjà faite.

**Rattacher les zones** (`/admin/reglages/reprise/zones`) : chaque zone propre à l'entreprise (hors expédition)
reçoit un lieu proposé, « sûr » (nom ou variante exacte), « à confirmer » (lieu cité dans le nom) ou aucun.
Valider rattache la zone au lieu et la range sous sa commune ; si le lieu a déjà sa zone, les deux sont fusionnées :
courses, e-commerçants, destinataires, points relais, prix des trajets, groupes de prix, livreurs, planning et paie
passent sur la zone du lieu, l'ancienne est masquée. Annulable 7 jours (`zone_links`). « Garder telle quelle » la
retire des propositions. **Reclasser** relit dans l'ancienne application la commune des courses « À préciser » et la
cherche de nouveau (noms, anciens noms retenus, liste commune) : seule la zone change.

**Tarifs proposés** (`/admin/reglages/reprise/tarifs`) : les anciens prix, regroupés par montant, modifiables ;
valider crée un groupe « depuis partout » dans la grille choisie ; ignorer retire la proposition. Les courses déjà
créées gardent leur prix.

**Calcul du prix** (Réglages › Tarifs) : grille de l'e-commerçant, sinon grille par défaut ; trajet particulier du
plus précis au plus large en remontant tous les niveaux (quartier, commune…) ; sinon prix « depuis partout » du
groupe du lieu de livraison, ou du lieu qui le contient ; puis suppléments. L'API publique accepte `delivery_place`
et `pickup_place` (codes) et donne `place` sur chaque zone (contrat 1.7).

