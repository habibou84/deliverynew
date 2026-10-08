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

Prérequis : PHP 8.2+ (extensions `pdo_pgsql`, `redis`, `pcntl`), Composer, Node 22, PostgreSQL 16, Redis.

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
composer dev                        # serveur, Horizon, Reverb, logs et Vite
```

Puis ouvrir http://localhost:8000.

### Comptes de démonstration

Créés par `DemoSeeder` (environnements `local` et `testing` uniquement). Mot de passe : `password`.

| Rôle | Téléphone | E-mail |
|---|---|---|
| Super administrateur | 07 00 00 00 00 | superadmin@livraison.test |
| Administrateur | 07 00 00 00 01 | admin@livraison.test |
| Dispatcher | 07 00 00 00 02 | dispatch@livraison.test |
| Caissier | 07 00 00 00 03 | caisse@livraison.test |
| Livreur | 07 00 00 00 04 | - |
| Livreuse | 07 00 00 00 05 | - |

On se connecte avec le **téléphone** (format local `07 xx xx xx xx` ou international `+225…`) **ou** l'e-mail.

### Production

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # à chaque déploiement (idempotent)
php artisan app:create-super-admin                               # première installation
```

Processus à superviser (Supervisor/systemd) : `php artisan horizon`, `php artisan reverb:start`, et le planificateur (`php artisan schedule:run` chaque minute).

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

## Architecture multi-entreprise

- Chaque utilisateur appartient à une entreprise (`users.company_id`), sauf le super administrateur (`null`).
- Les modèles métier utilisent le trait `App\Models\Concerns\BelongsToCompany` : filtrage automatique par entreprise et `company_id` rempli à la création.
- Les rôles et permissions sont définis dans `App\Enums\Role` et `App\Enums\Permission`, puis synchronisés en base par `RolesAndPermissionsSeeder`.
