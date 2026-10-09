#!/usr/bin/env bash
#
# Installation complète de l'application sur un VPS neuf (LWS ou autre) :
# Ubuntu 22.04 / 24.04 ou Debian 12, à lancer en root.
#
#   DOMAIN=test.mondomaine.ci EMAIL=moi@mondomaine.ci bash deploy/install.sh
#
# Variables :
#   DOMAIN   (obligatoire) nom de domaine qui pointe vers l'adresse IP du VPS
#   EMAIL    (obligatoire) adresse pour le certificat HTTPS (Let's Encrypt)
#   REPO     dépôt Git (défaut : git@github.com:habibou84/deliverynew.git)
#   BRANCH   branche à déployer (défaut : main)
#   APP_DIR  dossier de l'application (défaut : /var/www/livraison)
#   DEMO     1 = données de démonstration (comptes 07 00 00 00 01…, mot de passe « password »), 0 = base vide
#
# Le script peut être relancé sans risque : ce qui est déjà fait est conservé
# (base de données, .env, clés), le reste est complété.

set -euo pipefail

DOMAIN="${DOMAIN:-}"
EMAIL="${EMAIL:-}"
REPO="${REPO:-git@github.com:habibou84/deliverynew.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/var/www/livraison}"
DEMO="${DEMO:-1}"
APP_USER=www-data
APP_HOME=/var/www
DB_NAME=livraison
DB_USER=livraison
NODE_MAJOR=22

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
fail() { printf '\n\033[1;31mErreur : %s\033[0m\n' "$*" >&2; exit 1; }

# Commande lancée dans le dossier de l'application, en tant qu'utilisateur du site
as_app() { sudo -u "$APP_USER" -H env COMPOSER_HOME="$APP_HOME/.composer" npm_config_cache="$APP_HOME/.npm" bash -c "cd '$APP_DIR' && $*"; }

# Valeur d'une variable du .env (vide si absente)
env_get() { grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

# Remplace (ou ajoute) une variable du .env
env_set() {
    local key="$1" value="$2"
    if grep -qE "^$key=" "$APP_DIR/.env"; then
        sed -i "s|^$key=.*|$key=$value|" "$APP_DIR/.env"
    else
        echo "$key=$value" >> "$APP_DIR/.env"
    fi
}

random() { openssl rand -hex "${1:-16}"; }

# ─────────────────────────── Vérifications ───────────────────────────

[[ $EUID -eq 0 ]] || fail "lancez ce script en root (sudo -i)."
[[ -n "$DOMAIN" ]] || fail "indiquez le domaine : DOMAIN=test.mondomaine.ci EMAIL=… bash deploy/install.sh"
[[ -n "$EMAIL" ]] || fail "indiquez une adresse e-mail pour le certificat HTTPS : EMAIL=moi@mondomaine.ci"

. /etc/os-release
case "$ID:$VERSION_ID" in
    ubuntu:22.04|ubuntu:24.04|debian:12) ;;
    *) fail "système non prévu ($PRETTY_NAME). Utilisez Ubuntu 22.04/24.04 ou Debian 12." ;;
esac

export DEBIAN_FRONTEND=noninteractive

# ─────────────────────────── Paquets système ───────────────────────────

step "Paquets système (Nginx, PostgreSQL, Redis, Supervisor, Certbot)"
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg git unzip ufw openssl lsb-release \
    nginx postgresql redis-server supervisor certbot python3-certbot-nginx

step "PHP 8.3"
if [[ "$ID:$VERSION_ID" == "ubuntu:22.04" ]]; then
    apt-get install -y -q software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
elif [[ "$ID" == "debian" ]]; then
    curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
    echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $VERSION_CODENAME main" > /etc/apt/sources.list.d/sury-php.list
    apt-get update -q
fi
PHP_V=8.3
apt-get install -y -q php$PHP_V-fpm php$PHP_V-cli php$PHP_V-pgsql php$PHP_V-redis php$PHP_V-mbstring \
    php$PHP_V-xml php$PHP_V-curl php$PHP_V-zip php$PHP_V-bcmath php$PHP_V-intl php$PHP_V-gd
PHP_BIN=/usr/bin/php$PHP_V
PHP_FPM_SOCK=/run/php/php$PHP_V-fpm.sock

# Photos et imports jusqu'à 20 Mo
for ini in /etc/php/$PHP_V/fpm/php.ini /etc/php/$PHP_V/cli/php.ini; do
    sed -i -e 's/^upload_max_filesize.*/upload_max_filesize = 20M/' -e 's/^post_max_size.*/post_max_size = 25M/' "$ini"
done
systemctl restart php$PHP_V-fpm

step "Composer"
if ! command -v composer >/dev/null; then
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$(sha384sum /tmp/composer-setup.php | cut -d' ' -f1)"
    [[ "$expected" == "$actual" ]] || fail "signature de l'installateur Composer invalide."
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

step "Node.js $NODE_MAJOR (compilation de l'interface)"
if ! command -v node >/dev/null || [[ "$(node -v | cut -d. -f1 | tr -d v)" -lt 20 ]]; then
    curl -fsSL https://deb.nodesource.com/setup_$NODE_MAJOR.x | bash -
    apt-get install -y -q nodejs
fi

# ─────────────────────────── Base de données ───────────────────────────

step "PostgreSQL : base « $DB_NAME »"
systemctl enable --now postgresql redis-server
if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
    info "Utilisateur $DB_USER déjà créé (mot de passe dans $APP_DIR/.env)."
    DB_PASSWORD=""
else
    DB_PASSWORD="$(random 16)"
    sudo -u postgres psql -q -c "CREATE ROLE $DB_USER LOGIN PASSWORD '$DB_PASSWORD'"
fi
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1; then
    sudo -u postgres createdb -O "$DB_USER" "$DB_NAME"
fi

# ─────────────────────────── Code source ───────────────────────────

step "Code source ($REPO, branche $BRANCH)"
mkdir -p "$APP_HOME/.ssh" "$APP_HOME/.composer" "$APP_HOME/.npm"
chown -R "$APP_USER:$APP_USER" "$APP_HOME/.ssh" "$APP_HOME/.composer" "$APP_HOME/.npm"
chmod 700 "$APP_HOME/.ssh"

if [[ "$REPO" == git@* ]]; then
    # Dépôt privé : clé de déploiement (lecture seule) à ajouter dans GitHub
    if [[ ! -f "$APP_HOME/.ssh/id_ed25519" ]]; then
        sudo -u "$APP_USER" ssh-keygen -q -t ed25519 -N "" -C "deploy@$DOMAIN" -f "$APP_HOME/.ssh/id_ed25519"
    fi
    sudo -u "$APP_USER" -H ssh-keyscan -t ed25519 github.com 2>/dev/null > "$APP_HOME/.ssh/known_hosts"
    chown "$APP_USER:$APP_USER" "$APP_HOME/.ssh/known_hosts"
    if ! sudo -u "$APP_USER" -H git ls-remote "$REPO" >/dev/null 2>&1; then
        printf '\n\033[1;33mLe serveur n%s pas encore accès au dépôt.\033[0m\n' "'a"
        echo "Dans GitHub : dépôt > Settings > Deploy keys > Add deploy key, collez cette clé (sans cocher « Allow write access ») :"
        echo
        cat "$APP_HOME/.ssh/id_ed25519.pub"
        echo
        echo "Puis relancez la même commande."
        exit 1
    fi
fi

if [[ ! -d "$APP_DIR/.git" ]]; then
    mkdir -p "$APP_DIR"
    chown "$APP_USER:$APP_USER" "$APP_DIR"
    sudo -u "$APP_USER" -H git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
else
    info "Déjà cloné : mise à jour."
    as_app "git fetch origin '$BRANCH' && git checkout '$BRANCH' && git pull --ff-only origin '$BRANCH'"
fi

# ─────────────────────────── Configuration (.env) ───────────────────────────

step "Configuration (.env)"
if [[ ! -f "$APP_DIR/.env" ]]; then
    [[ -n "$DB_PASSWORD" ]] || fail "le .env est absent mais l'utilisateur PostgreSQL existe déjà : recréez-le ou fixez son mot de passe."
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    env_set APP_ENV production
    env_set APP_DEBUG false
    env_set APP_URL "https://$DOMAIN"
    env_set LOG_STACK daily
    env_set LOG_LEVEL info
    env_set DB_DATABASE "$DB_NAME"
    env_set DB_USERNAME "$DB_USER"
    env_set DB_PASSWORD "$DB_PASSWORD"
    # Temps réel : le serveur Reverb écoute en local, Nginx le publie en HTTPS sur /app
    env_set REVERB_APP_ID "$((RANDOM * RANDOM))"
    env_set REVERB_APP_KEY "$(random 10)"
    env_set REVERB_APP_SECRET "$(random 16)"
    env_set REVERB_HOST 127.0.0.1
    env_set REVERB_PORT 8080
    env_set REVERB_SCHEME http
    env_set REVERB_SERVER_HOST 127.0.0.1
    env_set REVERB_SERVER_PORT 8080
    env_set VITE_REVERB_APP_KEY "$(env_get REVERB_APP_KEY)"
    env_set VITE_REVERB_HOST "$DOMAIN"
    env_set VITE_REVERB_PORT 443
    env_set VITE_REVERB_SCHEME https
    env_set VAPID_SUBJECT "mailto:$EMAIL"
    env_set WHATSAPP_VERIFY_TOKEN "$(random 12)"
    chown "$APP_USER:$APP_USER" "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"
else
    info ".env déjà présent : conservé."
fi

step "Dépendances PHP"
as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"

if [[ -z "$(env_get APP_KEY)" ]]; then
    as_app "php artisan key:generate --force"
fi

# Clés des notifications push (une seule fois : les changer désabonne les livreurs)
if [[ -z "$(env_get VAPID_PUBLIC_KEY)" ]]; then
    vapid="$(as_app "php artisan webpush:vapid")"
    env_set VAPID_PUBLIC_KEY "$(echo "$vapid" | grep '^VAPID_PUBLIC_KEY=' | cut -d= -f2-)"
    env_set VAPID_PRIVATE_KEY "$(echo "$vapid" | grep '^VAPID_PRIVATE_KEY=' | cut -d= -f2-)"
fi

step "Interface (compilation Vite)"
as_app "npm ci --no-audit --no-fund && npm run build"

step "Base de données (migrations)"
as_app "php artisan migrate --force"
as_app "php artisan db:seed --force"
if [[ "$DEMO" == "1" ]] && [[ "$(as_app "php artisan tinker --execute='echo App\\Models\\Company::count();'" | tail -n1)" == "0" ]]; then
    info "Données de démonstration…"
    as_app "php artisan db:seed --class=DemoSeeder --force"
fi

as_app "php artisan optimize"
chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# ─────────────────────────── Services ───────────────────────────

step "Nginx"
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHP_FPM_SOCK__|$PHP_FPM_SOCK|g" \
    "$APP_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/livraison
ln -sf /etc/nginx/sites-available/livraison /etc/nginx/sites-enabled/livraison
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

step "Supervisor (files d'attente Horizon, temps réel Reverb)"
sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHP__|$PHP_BIN|g" "$APP_DIR/deploy/supervisor.conf" > /etc/supervisor/conf.d/livraison.conf
systemctl enable --now supervisor
supervisorctl reread
supervisorctl update
supervisorctl restart livraison-horizon livraison-reverb

step "Tâches planifiées (relances, alertes, rapports, facturation)"
echo "* * * * * $APP_USER cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/livraison
chmod 644 /etc/cron.d/livraison

step "Pare-feu"
if ufw allow OpenSSH >/dev/null && ufw allow 'Nginx Full' >/dev/null && ufw --force enable >/dev/null; then
    info "Ouverts : SSH, HTTP, HTTPS. Reverb (8080), PostgreSQL et Redis restent internes."
else
    info "Pare-feu non activé (ufw indisponible) : vérifiez le pare-feu dans l'espace client LWS."
fi

step "HTTPS (Let's Encrypt)"
if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect; then
    info "Certificat installé, renouvellement automatique."
else
    printf '\033[1;33m    Certificat non obtenu : vérifiez que %s pointe vers l%sadresse IP du VPS (enregistrement A),\n    puis lancez : certbot --nginx -d %s -m %s --agree-tos --redirect\033[0m\n' "$DOMAIN" "'" "$DOMAIN" "$EMAIL"
fi

# ─────────────────────────── Résumé ───────────────────────────

step "Terminé"
cat <<EOF
    Application      : https://$DOMAIN
    Back-office      : https://$DOMAIN/login
    Livreur (mobile) : https://$DOMAIN/livreur   ·   Marchand (mobile) : https://$DOMAIN/marchand
    Dossier          : $APP_DIR (journaux : storage/logs)
    Mise à jour      : bash $APP_DIR/deploy/update.sh

EOF
if [[ "$DEMO" == "1" ]]; then
    cat <<EOF
    Comptes de démonstration (mot de passe « password », à changer) :
      07 00 00 00 01 administrateur · 07 00 00 00 02 dispatcher · 07 00 00 00 03 caissier
      07 00 00 00 04 et 05 livreurs · 05 00 00 00 01 marchand · 07 00 00 00 06 agent d'entrepôt
EOF
else
    echo "    Créez le premier compte : cd $APP_DIR && sudo -u $APP_USER php artisan app:create-super-admin"
fi
