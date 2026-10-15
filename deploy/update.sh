#!/usr/bin/env bash
#
# Mise à jour de l'application déjà installée (install.sh, ispconfig.sh ou preprod.sh), à lancer en root :
#
#   bash /chemin/de/l/application/deploy/update.sh
#
# Met à jour l'installation du dossier où se trouve ce script (production ou préproduction) :
# récupère la dernière version de sa branche, met à jour les dépendances, recompile
# l'interface, applique les migrations puis redémarre les processus.
#
# Changer la branche suivie (une seule fois, ex. production sur la branche « production ») :
#   BRANCH=production bash /chemin/de/l/application/deploy/update.sh

set -euo pipefail

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root."
load_deploy_conf "$(dirname "${BASH_SOURCE[0]}")/.."

BRANCH="${BRANCH:-$(as_app "git rev-parse --abbrev-ref HEAD")}"

step "Mode maintenance"
as_app "php artisan down --retry=30 || true"
trap 'as_app "php artisan up"' EXIT

step "Code (branche $BRANCH)"
sync_code

step "Dépendances PHP"
as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"

step "Interface"
as_app "npm ci --no-audit --no-fund && npm run build"

step "Migrations et caches"
as_app "php artisan migrate --force"
as_app "php artisan db:seed --class=RolesAndPermissionsSeeder --force"
# Positions des marchands d'après les ramassages déjà faits (sans effet si rien de nouveau)
as_app "php artisan merchants:locate"
as_app "php artisan optimize"

step "Redémarrage des processus"
# PHP-FPM : vider le cache OPcache de l'ancien code
for unit in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' | awk '{print $1}'); do
    systemctl reload "$unit"
done
as_app "php artisan horizon:terminate"
supervisorctl restart "$INSTANCE-reverb"
supervisorctl status

step "Terminé ($INSTANCE, branche $BRANCH) : $(as_app "git log -1 --format='%h %s'")"
