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
| GET/POST/PATCH | `/orders` (filtres `queue`, `status[]`, `merchant_id`, `courier_id`, `search`…) | personnel, ou le marchand pour ses courses |
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
| GET/PUT | `/whatsapp/settings` · POST `/whatsapp/test`, `/whatsapp/templates/sync` | `settings.manage` : numéro Meta, options, test, approbation des modèles |
| GET | `/messages` · POST `/messages/{id}/retry` · GET `/orders/{id}/messages` | `orders.dispatch` : journal des messages WhatsApp/SMS, renvoi d'un échec |
| GET/POST | `/api/webhooks/whatsapp` (hors `/v1`) | **public**, signé par Meta (`X-Hub-Signature-256`) : accusés de réception |

Temps réel (Reverb) : canaux privés `company.{id}` (personnel), `merchant.{id}` (marchand) et `App.Models.User.{id}` (notifications), événement `order.changed`.

## Architecture multi-entreprise

- Chaque utilisateur appartient à une entreprise (`users.company_id`), sauf le super administrateur (`null`).
- Les modèles métier utilisent le trait `App\Models\Concerns\BelongsToCompany` : filtrage automatique par entreprise et `company_id` rempli à la création.
- Les rôles et permissions sont définis dans `App\Enums\Role` et `App\Enums\Permission`, puis synchronisés en base par `RolesAndPermissionsSeeder`.
