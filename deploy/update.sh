#!/usr/bin/env bash
#
# Mise à jour de l'application déjà installée (à lancer en root) :
#
#   bash /var/www/livraison/deploy/update.sh
#
# Récupère la dernière version de la branche, met à jour les dépendances,
# recompile l'interface, applique les migrations puis redémarre les processus.

set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
APP_USER=www-data
APP_HOME=/var/www

[[ $EUID -eq 0 ]] || { echo "Lancez ce script en root." >&2; exit 1; }

as_app() { sudo -u "$APP_USER" -H env COMPOSER_HOME="$APP_HOME/.composer" npm_config_cache="$APP_HOME/.npm" bash -c "cd '$APP_DIR' && $*"; }
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

BRANCH="$(as_app "git rev-parse --abbrev-ref HEAD")"

step "Mode maintenance"
as_app "php artisan down --retry=30 || true"
trap 'as_app "php artisan up"' EXIT

step "Code (branche $BRANCH)"
as_app "git pull --ff-only origin '$BRANCH'"

step "Dépendances PHP"
as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"

step "Interface"
as_app "npm ci --no-audit --no-fund && npm run build"

step "Migrations et caches"
as_app "php artisan migrate --force"
as_app "php artisan db:seed --class=RolesAndPermissionsSeeder --force"
as_app "php artisan optimize"

step "Redémarrage des processus"
systemctl reload "$(systemctl list-units --type=service --no-legend 'php*-fpm.service' | awk '{print $1}' | head -n1)"
as_app "php artisan horizon:terminate"
as_app "php artisan reverb:restart" || supervisorctl restart livraison-reverb
supervisorctl status

step "Terminé : $(as_app "git log -1 --format='%h %s'")"
