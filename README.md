# Livraison : plateforme de gestion de livraison pour e-commerçants

Back-office (entreprise de livraison), espace e-commerçant et application livreur.

- **Plan d'exécution, choix techniques et roadmap** : [`docs/PLAN.md`](docs/PLAN.md)
- **Schéma de base de données** : [`docs/database.dbml`](docs/database.dbml) (à importer sur https://dbdiagram.io)

## Stack

| Couche | Technologie |
|---|---|
| API | Laravel 12, Sanctum (jetons), spatie/laravel-permission |
| Base de données | PostgreSQL 16 |
| Files d'attente / cache | Redis + Laravel Horizon |
| Temps réel | Laravel Reverb (WebSocket) |
| Front-end | Vue 3, Pinia, Vue Router, Tailwind CSS 4 (Vite) |

## Installation locale

Prérequis : PHP 8.2+ (extensions `pdo_pgsql` et `redis` ; `pcntl` seulement pour Horizon en production), Composer, Node 22, PostgreSQL 16, Redis.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan reverb:install          # génère les clés REVERB_* dans .env

# Créer la base (adapter à votre installation PostgreSQL)
createuser -P livraison             # mot de passe : secret (cf. .env)
createdb -O livraison livraison

php artisan migrate --seed          # rôles + données de démonstration
composer dev                        # serveur, file d'attente, Reverb et Vite
```

`composer dev` fonctionne sous Windows, macOS et Linux : il lance `queue:listen` (et non Horizon) et ne lance pas `pail`.
Les logs sont dans `storage/logs/laravel.log` ; sous macOS/Linux, `php artisan pail` les affiche en direct.

#### Windows

Horizon et Pail ont besoin des extensions PHP `pcntl` et `posix`, qui n'existent pas sous Windows.
`composer.json` les déclare dans `config.platform` pour que `composer install` fonctionne quand même ;
ces deux outils ne s'utilisent simplement pas en local (Horizon sert en production, sur un serveur Linux).

Sans Redis (cas fréquent sous Windows), utilisez la base de données pour le cache et les files d'attente dans `.env` :

```dotenv
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Après une mise à jour du code, relancez toujours `composer install`, `npm install` et `php artisan migrate`.

Puis ouvrir http://localhost:8000.

### Applications mobiles (PWA)

| Application | Adresse | Pour |
|---|---|---|
| Back-office | `/admin` | administrateurs, dispatch, caisse (écran d'ordinateur) |
| Mes livraisons | `/marchand` | e-commerçants (téléphone) |
| Livreur | `/livreur` | livreurs (téléphone) |

Après connexion, chacun arrive sur son application. Sur Android (Chrome), un bouton « Installer l'application » apparaît ;
sur iPhone (Safari), *Partager → Sur l'écran d'accueil*. L'installation et le service worker exigent **HTTPS**
(ou `localhost`) : pour tester sur un téléphone en local, passez par un tunnel HTTPS (ngrok, Cloudflare Tunnel…).
Le service worker n'est actif qu'avec le build de production (`npm run build`), pas avec `npm run dev`.

Hors ligne, l'application s'ouvre et affiche les dernières données connues ; les actions demandent le réseau.

### WhatsApp et SMS

Par défaut, rien n'est réellement envoyé (`WHATSAPP_DRIVER=log`, `SMS_DRIVER=log`) : les messages apparaissent dans
**Messages** (back-office), sur la fiche colis et dans `storage/logs/laravel.log`.

Pour envoyer de vrais messages :

1. Chez Meta : compte Business vérifié, application avec le produit WhatsApp, numéro ajouté, jeton d'accès permanent (utilisateur système).
2. Dans le back-office, **WhatsApp** : saisir l'identifiant du numéro, l'identifiant WABA et le jeton ; envoyer un message de test.
3. Créer dans le gestionnaire WhatsApp chacun des modèles affichés sur cette page (catégorie Utilitaire, langue Français, texte identique), puis « Vérifier l'approbation chez Meta ».
4. Dans `.env` : `WHATSAPP_DRIVER=meta`, `WHATSAPP_APP_SECRET` (secret de l'application Meta) et `WHATSAPP_VERIFY_TOKEN` (chaîne de votre choix) ; abonner l'adresse du webhook indiquée sur la page au champ `messages`.
5. SMS de repli (facultatif) : `SMS_DRIVER=twilio` avec `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` ; `SMS_DRIVER=none` pour le désactiver.

#### Courses par WhatsApp

Un marchand dont le numéro est connu écrit au numéro de l'agence (message libre, commande transférée de son client
ou menu à boutons) : le bot demande ce qui manque, montre un récapitulatif chiffré et crée la course après
« Confirmer ». Il donne aussi le suivi d'un colis (envoyer son numéro `LV-…`) et le point du jour. Le réglage
« Courses par WhatsApp » se trouve sur la page **WhatsApp**, et **WhatsApp > Simulateur** permet de tout essayer
sans compte Meta (les courses confirmées sont réellement créées).

L'analyse se fait par règles ; avec `ANTHROPIC_API_KEY` (et `ANTHROPIC_MODEL`, par défaut `claude-opus-5-5`),
Claude comprend aussi les messages sans format, avec retour automatique aux règles en cas d'erreur.

### Remontées terrain

Les notes et problèmes enregistrés par les livreurs (échec ou report de livraison, échec de ramassage, refus de
mission, frais déclarés, notes, même internes) arrivent **en direct** au dispatch : toast, **son** (carillon pour une
note, trois bips pour un problème) et notification du bureau si l'onglet est caché. Réglages (son, volume, test,
notifications du bureau) via 🔊 dans l'en-tête ; le navigateur n'autorise le son qu'après un premier clic dans la page.
La page **Remontées terrain** les liste (filtres : à traiter / traitées, type, livreur, dates, recherche) ; chaque
remontée se marque « traitée » avec un commentaire, le menu affiche le nombre restant (30 derniers jours).

- **Réponse au livreur** : depuis une remontée (ou la fiche d'une course), le dispatch envoie une consigne, avec des
  réponses rapides en un clic. Le livreur la reçoit en direct (son, vibration, bandeau), la retrouve dans sa mission
  et dans **Consignes de l'agence**, et la confirme d'un « 👍 Compris » ; le dispatch voit « lu » puis « compris ».
  La consigne est tracée au journal de la course (note interne) et peut marquer la remontée comme traitée.
- **Relance automatique** (`field-reports:remind`, chaque minute) : un problème resté sans réponse ni traitement est
  relancé au dispatch après le délai réglé dans **Paramètres** (10 min par défaut, 0 = jamais), puis aux
  administrateurs après trois fois ce délai. Rien n'est relancé si la course est terminée.

### Colis non livrés chez les livreurs

L'application sait à tout moment quel livreur a quel colis en main (ramassé, en livraison, en échec ou reporté),
jusqu'à ce qu'il soit rendu au dépôt, livré ou retourné au marchand.

- **Point de caisse** : le versement liste les colis non livrés du livreur ; le caissier coche ceux qu'il reçoit
  (« Tout cocher » possible). Les colis non cochés sont notés « gardés » sur le versement. Un colis ramassé rendu
  passe « Au dépôt » ; un colis en échec ou reporté garde son statut pour que le dispatch décide de la suite.
  Un livreur sans argent à verser rend ses colis via « Recevoir les colis ».
- **Point bloqué** tant qu'une course du livreur est « En chemin » : il doit d'abord indiquer « livré » ou « échec ».
- **Colis chez les livreurs** (back-office, `orders.dispatch`) : colis par livreur avec leur ancienneté, filtre
  « En retard », réception au dépôt en un clic. Au-delà du délai réglé dans **Paramètres** (24 h par défaut,
  0 = jamais), le colis passe en rouge, le menu affiche un badge et le dispatch reçoit une alerte sonore
  (`parcels:overdue`, toutes les 15 min, une alerte par colis).
- Le livreur voit dans **Ma caisse** les colis à rapporter au dépôt.
- **Décider de la suite** (file **À décider** des courses, ou fiche de la course) : un échec de livraison attend
  une décision avant de revenir dans « À livrer » : **relivrer** à une date (aujourd'hui, demain…, livreur
  facultatif), **retourner au marchand** ou, pour une commande d'entrepôt, **remettre en stock**. Le marchand est
  prévenu par WhatsApp (nouvelle date ou retour du colis). Les reports à une date future sont dans **Reportées** et
  reviennent dans « À livrer » le jour prévu.
- **Rappel du matin** (`orders:due-today`, chaque jour à 06:50) : le dispatch est prévenu des courses reportées à
  aujourd'hui et pas encore assignées, avec les livreurs qui ont déjà le colis en main (à leur confier en priorité).
  Dans la liste des courses, 🎒 indique le livreur qui a le colis.
- **Bon de retour groupé** (**Retours marchands**, back-office) : les colis à rendre sont regroupés par marchand ;
  le dispatch crée un bon (`BR-…`) confié à un livreur (celui qui a déjà les colis est proposé). Le marchand reçoit
  le lien du bon par WhatsApp. Dans son application, le livreur ouvre le bon, coche les colis remis, saisit le nom
  de la personne qui reçoit et fait **signer à l'écran** ou **photographie** la remise : toutes les courses passent
  « Retourné » et le marchand reçoit la confirmation. Le bon est imprimable (A4) depuis `/bon-de-retour/{id}`.
  Deux nouveaux modèles WhatsApp sont à faire approuver : `bon_de_retour` et `retour_remis`.
- **Colis perdu** : un administrateur (dispatch + caisse) déclare la perte depuis la fiche de la course (bouton
  « 🚨 Déclarer le colis perdu ») : circonstances, **indemnité au marchand** (valeur des articles proposée), portée à
  son grand livre et versée avec son prochain reversement, et **retenue facultative** sur la paie du livreur
  responsable. La course passe « Perdu » (statut final), le marchand est prévenu par WhatsApp, le stock d'une
  commande d'entrepôt est sorti. Un colis resté chez un livreur plus de trois fois le délai d'alerte (72 h par défaut)
  est signalé « peut-être perdu » aux administrateurs et dans « Colis chez les livreurs ».

### Notifications push des livreurs

Le livreur active les notifications depuis le bandeau de **Missions** ou son **Profil** (bouton de test). Il est alors
prévenu même application fermée ou téléphone en veille : **nouvelle mission** et **consigne de l'agence** (le toucher
ouvre la mission). Web Push standard (VAPID, chiffrement `aes128gcm`), implémenté sans dépendance ni service tiers.

- Générer les clés une fois par serveur : `php artisan webpush:vapid`, puis copier `VAPID_PUBLIC_KEY`,
  `VAPID_PRIVATE_KEY` et `VAPID_SUBJECT` (mailto: ou https:) dans `.env`. Ne pas les changer ensuite : les abonnements
  existants deviendraient invalides. Sans clés, la fonction est simplement masquée.
- HTTPS obligatoire (sauf `localhost`) ; le service worker n'est enregistré que dans la version compilée (`npm run build`).
- **iPhone** : iOS 16.4 minimum et application **installée sur l'écran d'accueil** ; l'app le signale au livreur.
- Les abonnements expirés (réponse 404/410) sont supprimés automatiquement ; la déconnexion retire celui de l'appareil.

### Carte des livreurs

**Carte des livreurs** (back-office, droit `orders.dispatch`) montre la dernière position de chaque livreur, ses
missions en cours et l'âge de la position ; elle se met à jour en direct (Reverb). L'application livreur envoie sa
position toutes les 30 secondes pendant le service (localisation autorisée sur le téléphone).
**Trajet du jour** (depuis la carte, ou `/admin/carte?livreur={id}&date=AAAA-MM-JJ`) : tracé de la journée, étapes
des courses localisées (ramassé, livré, échec…), distance parcourue et heures de service. Les positions trop
imprécises, à l'arrêt ou aberrantes ne sont pas conservées ; l'historique est purgé après 90 jours (`model:prune`). Fond de carte
OpenStreetMap via Leaflet, sans clé ; pour un usage intensif, passer à un fournisseur de tuiles (MapTiler, Stadia…).

### Stock et entrepôts

Le marchand enregistre ses produits et le stock qu'il garde chez lui (**Mon stock** dans son application) ; l'entreprise
tient le stock de ses entrepôts (**Stock** dans le back-office : entrées, retraits, inventaires, mouvements). En créant
une course, on choisit les articles : ils sont réservés, puis sortis du stock à la livraison, ou libérés si la course
est annulée ou le colis remis en stock. Une course qui part d'un entrepôt n'a pas de ramassage : elle est préparée
(onglet « À préparer ») puis livrée. Le stockage est facturé chaque mois selon le contrat du marchand
(`storage:bill`, lancé par le planificateur le 1er du mois ; `php artisan storage:bill --month=2026-09` pour un mois donné).

### API publique, webhooks et import

Un e-commerçant connecte sa boutique en ligne ou son logiciel depuis **Profil › Intégrations** (l'administration
le fait aussi depuis **Intégrations (API)**) :

- **Clé API** (`lv_…`, affichée une seule fois) pour l'API `/api/public/v1` : zones, devis, création et suivi des
  courses, annulation, point, produits. Documentation : **`/developpeurs/api`** (spécification
  `public/docs/openapi.yaml`, importable dans Postman). `Idempotency-Key` obligatoire sur `POST /orders`,
  120 requêtes par minute et par clé.
- **Webhooks** : la boutique est prévenue de chaque événement (`order.created`, `order.status_changed`,
  `order.incident`, `payout.paid`, `stock.low`). Vérification de la signature côté boutique :

  ```php
  $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Webhook-Timestamp').'.'.$request->getContent(), $secret);
  abort_unless(hash_equals($expected, $request->header('X-Webhook-Signature')), 401);
  ```

  Envois par la file `default`, 6 tentatives au plus, journal et renvoi dans l'écran Intégrations.
- **Import** : **Courses › Importer** (back-office) ou **Profil › Importer des courses** (application marchand),
  à partir du modèle CSV téléchargeable ou d'un fichier Excel ; aperçu contrôlé avant création.

Les envois passent par la file `messages` et les points d'activité par le planificateur (`reports:send` toutes les 5 minutes) :
`composer dev` lance les deux.

### Comptes de démonstration

Créés par `DemoSeeder` (environnements `local` et `testing` uniquement). Mot de passe : `password`.

| Rôle | Téléphone | E-mail |
|---|---|---|
| Super administrateur | 07 00 00 00 00 | superadmin@livraison.test |
| Administrateur | 07 00 00 00 01 | admin@livraison.test |
| Dispatcher | 07 00 00 00 02 | dispatch@livraison.test |
| Caissier (caisse, reversements, paie) | 07 00 00 00 03 | caisse@livraison.test |
| Agent d'entrepôt (stock, préparation) | 07 00 00 00 06 | entrepot@livraison.test |
| Livreur | 07 00 00 00 04 | - |
| Livreuse | 07 00 00 00 05 | - |
| E-commerçant (Boutique Chic Abidjan) | 05 00 00 00 01 | boutique@livraison.test |

On se connecte avec le **téléphone** (format local `07 xx xx xx xx` ou international `+225…`) **ou** l'e-mail.

### Production

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # à chaque déploiement (idempotent)
php artisan app:create-super-admin                               # première installation
```

**Mise en ligne sur un VPS (LWS ou autre)** : voir [docs/DEPLOIEMENT-LWS.md](docs/DEPLOIEMENT-LWS.md).
`deploy/ispconfig.sh` pour un serveur géré par ISPConfig (Debian 13, Apache), `deploy/install.sh` pour un VPS nu
(Nginx, PHP 8.3, PostgreSQL, Redis, Horizon, Reverb, cron, HTTPS) ; `deploy/update.sh` met à jour.

Processus à superviser (Supervisor/systemd) : `php artisan horizon`, `php artisan reverb:start`, et le planificateur (`php artisan schedule:run` chaque minute).
Les notifications, la diffusion temps réel et les messages WhatsApp/SMS passent par les files `default` et `messages` : sans Horizon (ou `queue:work --queue=default,messages`), ils ne partent pas.

## Tests et qualité

```bash
php artisan test                    # SQLite en mémoire (rapide)
DB_CONNECTION=pgsql DB_DATABASE=livraison_test php artisan test   # sur PostgreSQL, comme la CI
vendor/bin/pint                     # formatage du code
npm run build                       # build front-end
```

La CI GitHub Actions (`.github/workflows/ci.yml`) exécute Pint, les migrations aller-retour et les tests sur PostgreSQL, puis le build front-end.

## API

Toutes les routes sont préfixées par `/api/v1` et répondent en JSON au format `{ "message": "...", "errors": { ... } }` en cas d'erreur.
Authentification : en-tête `Authorization: Bearer <jeton>`.

| Méthode | Route | Accès |
|---|---|---|
| POST | `/auth/login` | public (`login` = téléphone ou e-mail, `password`, `device_name`) ; 5 essais/minute |
| GET | `/auth/me` | connecté : profil, entreprise, permissions |
| POST | `/auth/logout` | connecté : révoque le jeton de l'appareil courant |
| GET | `/roles` | rôles que l'utilisateur peut attribuer |
| GET/POST | `/users` | `users.view` / `users.manage` (filtres `role`, `status`, `search`, `company_id` pour le super admin) |
| GET/PATCH/DELETE | `/users/{id}` | même entreprise + `users.manage` |
| GET/POST | `/companies` | super administrateur (création avec premier admin facultatif) |
| GET/PATCH | `/companies/{id}` | super admin, ou admin de l'entreprise (paramètres hors statut) |
| GET | `/zones` · POST/PATCH `/zones/{id}` | tous · `settings.manage` (`is_shipping`, `shipping_fee_estimate` pour une zone d'expédition) |
| CRUD | `/pricing-grids`, PUT `/pricing-grids/{id}/rules` et `/surcharges` | `settings.manage` |
| POST | `/quotes` | devis d'une course (marchand ou personnel) |
| GET/POST/PATCH | `/merchants`, POST `/merchants/{id}/users` | `merchants.view` / `merchants.manage` |
| GET/PATCH | `/couriers` | dispatch / `users.manage` |
| GET/POST/PATCH | `/orders` (filtres `queue` dont `to_prepare`, `status[]`, `merchant_id`, `hub_id`, `courier_id`, `search`…) ; création avec `items[]` et `pickup_hub_id` | personnel, ou le marchand pour ses courses |
| POST | `/orders/{id}/status` | changement de statut (règles par rôle dans `OrderWorkflow`) |
| POST | `/orders/{id}/assign`, `/orders/bulk-assign` | `orders.dispatch` (missions ramassage / livraison / retour) |
| POST | `/orders/{id}/notes`, `/return-request`, `/attachments` | notes, demande de retour, photo de preuve |
| GET | `/courier/missions` · POST `/courier/assignments/{id}/accept\|refuse` · PATCH `/courier/status` | livreur |
| GET | `/reports/summary`, `/incident-reasons`, `/recipients`, `/notifications` | connecté |
| GET | `/tracking/{code}` | **public** (suivi destinataire, 30 req/min) |
| GET | `/finance/cash`, `/finance/couriers/{id}/collections`, `/finance/remittances` · POST `/finance/remittances` | caisse : argent chez les livreurs et versements (`finance.view` / `finance.manage`) |
| GET | `/finance/merchants`, `/finance/merchants/{id}/ledger` · POST `.../adjustments` | soldes et grand livre (le marchand voit le sien) |
| GET/POST | `/finance/payouts`, `/finance/payouts/{id}` · POST `.../pay`, `.../cancel` | reversements aux marchands |
| GET/POST | `/finance/couriers/{id}/earnings`, `/finance/courier-payouts` · POST `.../pay`, `.../cancel` | paie des livreurs |
| GET | `/courier/wallet` | livreur : à verser (encaissé + avances − frais payés), gains non payés |
| POST | `/finance/couriers/{id}/advances` | `finance.manage` : avance de caisse au livreur (frais de gare…) |
| POST | `/orders/{id}/expenses` · `/orders/{id}/expenses/{expense}/cancel` | frais d'une course : le livreur de la course, ou dispatch / caisse (payé par, à la charge de) ; annulation par le personnel |
| GET/PUT | `/merchants/{id}/notifications` | messages WhatsApp du marchand (événements, points quotidien et hebdomadaire, numéro) : le marchand ou `merchants.manage` |
| GET | `/field-reports` (`state`, `kind`, `courier_id`, `from`, `to`, `search`) · GET `/field-reports/counts` · POST `/field-reports/{event}/handle` · `/reopen` | `orders.dispatch` : remontées terrain et leur suivi |
| GET/POST | `/orders/{id}/courier-messages` (`body`, `reply_to_event_id`, `mark_handled`) · livreur : GET `/courier/messages` · POST `/courier/messages/{id}/ack` | `orders.dispatch` / livreur : consignes au livreur |
| GET | `/couriers/{id}/track?date=` | `orders.dispatch` : trajet d'une journée (points, étapes, distance) |
| GET | `/couriers/map` | `orders.dispatch` : positions et missions en cours des livreurs (diffusion `courier.location` sur `company.{id}`) |
| GET/POST/DELETE | `/api-keys` | `integrations.manage` : clés de l'API publique (le marchand les siennes, l'administration avec `merchant_id`) |
| GET/POST/PATCH/DELETE | `/webhooks` · POST `/webhooks/{id}/test`, `/webhooks/{id}/secret` · GET `/webhooks/{id}/deliveries` · POST `/webhook-deliveries/{id}/redeliver` | `integrations.manage` : adresses webhook, test, journal, renvoi |
| GET | `/orders/import/template` · POST `/orders/import` (`file`, `dry_run`, `skip_invalid`, `merchant_id`) | `orders.create` : import CSV/Excel |
| — | `/api/public/v1/…` (hors `/v1`) | **clé API** : voir `/developpeurs/api` |
| GET | `/hubs` · POST/PATCH `/hubs/{id}` | connecté (liste) · `settings.manage` : entrepôts |
| GET/POST/PUT/DELETE | `/products` | marchand (ses produits) ou personnel ; création et modification : `stock.manage` |
| GET/POST | `/stock/movements` | journal ; POST `{product_id, hub_id?, action: receipt\|withdrawal\|count, quantity}` : le marchand chez lui, le personnel dans les entrepôts |
| GET/POST/PUT/DELETE | `/storage-contracts` | marchand (les siens) ; gestion : `settings.manage` ou `finance.manage` |
| GET/PUT | `/whatsapp/settings` · POST `/whatsapp/test`, `/whatsapp/templates/sync` | `settings.manage` : numéro Meta, options, test, approbation des modèles |
| GET | `/messages` · POST `/messages/{id}/retry` · GET `/orders/{id}/messages` | `orders.dispatch` : journal des messages WhatsApp/SMS, renvoi d'un échec |
| POST | `/whatsapp/simulate` | `settings.manage` : simulateur de conversation (`from`, `text` ou `button_id`) ; renvoie les réponses du bot |
| GET/POST | `/api/webhooks/whatsapp` (hors `/v1`) | **public**, signé par Meta (`X-Hub-Signature-256`) : accusés de réception et messages reçus |

Temps réel (Reverb) : canaux privés `company.{id}` (personnel), `merchant.{id}` (marchand) et `App.Models.User.{id}` (notifications), événement `order.changed`.

## Architecture multi-entreprise

- Chaque utilisateur appartient à une entreprise (`users.company_id`), sauf le super administrateur (`null`).
- Les modèles métier utilisent le trait `App\Models\Concerns\BelongsToCompany` : filtrage automatique par entreprise et `company_id` rempli à la création.
- Les rôles et permissions sont définis dans `App\Enums\Role` et `App\Enums\Permission`, puis synchronisés en base par `RolesAndPermissionsSeeder`.
