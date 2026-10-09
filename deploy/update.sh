#!/usr/bin/env bash
#
# Mise à jour de l'application déjà installée (install.sh ou ispconfig.sh), à lancer en root :
#
#   bash /chemin/de/l/application/deploy/update.sh
#
# Récupère la dernière version de la branche, met à jour les dépendances, recompile
# l'interface, applique les migrations puis redémarre les processus.

set -euo pipefail

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root."
[[ -f "$DEPLOY_CONF" ]] || fail "$DEPLOY_CONF absent : l'application n'a pas été installée avec install.sh ou ispconfig.sh."
. "$DEPLOY_CONF"

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
# PHP-FPM : vider le cache OPcache de l'ancien code
for unit in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' | awk '{print $1}'); do
    systemctl reload "$unit"
done
as_app "php artisan horizon:terminate"
supervisorctl restart livraison-reverb
supervisorctl status

step "Terminé : $(as_app "git log -1 --format='%h %s'")"
