# Fonctions communes aux scripts d'installation (install.sh, ispconfig.sh, preprod.sh) et de mise à jour.
# Variables attendues : APP_DIR, APP_USER, TOOLS_HOME (dossier personnel des outils :
# clé Git, caches Composer et npm), et selon les fonctions : DOMAIN, EMAIL, REPO, BRANCH,
# DEMO, PHP_BIN, REVERB_PORT.
#
# INSTANCE nomme une installation sur le serveur (base, Supervisor, tâches planifiées, réglages de
# déploiement) : plusieurs installations cohabitent, par exemple la production (« livraison », le nom
# d'origine) et la préproduction (« jibiat-preprod », deploy/preprod.sh).

INSTANCE="${INSTANCE:-livraison}"
DB_NAME="${DB_NAME:-${INSTANCE//-/_}}"
DB_USER="${DB_USER:-${INSTANCE//-/_}}"
DEPLOY_CONF="/etc/$INSTANCE-deploy.conf"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m    %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mErreur : %s\033[0m\n' "$*" >&2; exit 1; }
random() { openssl rand -hex "${1:-16}"; }

# Commande lancée en tant qu'utilisateur du site, avec ses outils (Git, Composer, npm)
as_user() {
    sudo -u "$APP_USER" -H env HOME="$TOOLS_HOME" COMPOSER_HOME="$TOOLS_HOME/.composer" \
        npm_config_cache="$TOOLS_HOME/.npm" \
        GIT_SSH_COMMAND="ssh -i $TOOLS_HOME/.ssh/id_ed25519 -o UserKnownHostsFile=$TOOLS_HOME/.ssh/known_hosts -o StrictHostKeyChecking=accept-new" \
        bash -c "$*"
}

# Même chose, dans le dossier de l'application
as_app() { as_user "cd '$APP_DIR' && $*"; }

# Valeur d'un réglage du .env ; vide (sans erreur) s'il est absent, par exemple dans un .env
# plus ancien que le réglage
env_get() { { grep -E "^$1=" "$APP_DIR/.env" || true; } | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

env_set() {
    local key="$1" value="$2"
    if grep -qE "^$key=" "$APP_DIR/.env"; then
        sed -i "s|^$key=.*|$key=$value|" "$APP_DIR/.env"
    else
        echo "$key=$value" >> "$APP_DIR/.env"
    fi
}

# Base PostgreSQL et utilisateur dédiés ; DB_PASSWORD est vide si l'utilisateur existait déjà
setup_postgres() {
    step "PostgreSQL : base « $DB_NAME »"
    systemctl enable --now postgresql
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
}

# Clé de déploiement (lecture seule) puis clone ou mise à jour du dépôt
fetch_code() {
    step "Code source ($REPO, branche $BRANCH)"
    mkdir -p "$TOOLS_HOME/.ssh" "$TOOLS_HOME/.composer" "$TOOLS_HOME/.npm"
    chown -R "$APP_USER:" "$TOOLS_HOME/.ssh" "$TOOLS_HOME/.composer" "$TOOLS_HOME/.npm"
    chmod 700 "$TOOLS_HOME/.ssh"

    if [[ "$REPO" == git@* ]]; then
        if [[ ! -f "$TOOLS_HOME/.ssh/id_ed25519" ]]; then
            as_user "ssh-keygen -q -t ed25519 -N '' -C 'deploy@$DOMAIN' -f '$TOOLS_HOME/.ssh/id_ed25519'"
        fi
        if ! as_user "git ls-remote '$REPO'" >/dev/null 2>&1; then
            printf '\n\033[1;33mLe serveur n%s pas encore accès au dépôt.\033[0m\n' "'a"
            echo "Dans GitHub : dépôt > Settings > Deploy keys > Add deploy key, collez cette clé (sans cocher « Allow write access ») :"
            echo
            cat "$TOOLS_HOME/.ssh/id_ed25519.pub"
            echo
            echo "Puis relancez la même commande."
            exit 1
        fi
    fi

    if [[ ! -d "$APP_DIR/.git" ]]; then
        [[ ! -e "$APP_DIR" ]] || [[ -z "$(ls -A "$APP_DIR")" ]] || fail "$APP_DIR existe déjà et n'est pas un dépôt Git."
        mkdir -p "$APP_DIR"
        chown "$APP_USER:" "$APP_DIR"
        as_user "git clone --branch '$BRANCH' '$REPO' '$APP_DIR'"
    else
        info "Déjà cloné : mise à jour."
        sync_code
    fi
}

# Aligne le code du serveur sur la branche de GitHub. Le serveur ne fait que suivre la
# branche : si elle a été réécrite (rebase, push forcé), on reprend la version de GitHub
# au lieu d'échouer. Les modifications locales de fichiers suivis bloquent la mise à jour.
sync_code() {
    as_app "git fetch origin '+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH'"
    if [[ -n "$(as_app "git status --porcelain --untracked-files=no")" ]]; then
        as_app "git status --short --untracked-files=no"
        fail "des fichiers du code ont été modifiés sur le serveur (ci-dessus) : annulez-les (cd $APP_DIR && git checkout -- .) puis relancez."
    fi
    as_app "git checkout -q '$BRANCH' && git reset -q --hard 'origin/$BRANCH'"
}

# .env de production (créé une seule fois, conservé ensuite)
write_env() {
    step "Configuration (.env)"
    if [[ -f "$APP_DIR/.env" ]]; then
        info ".env déjà présent : conservé."
        return
    fi
    [[ -n "$DB_PASSWORD" ]] || fail "le .env est absent mais l'utilisateur PostgreSQL « $DB_USER » existe déjà : supprimez-le (sudo -u postgres dropuser $DB_USER) ou fixez son mot de passe."

    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    env_set APP_ENV production
    env_set APP_DEBUG false
    env_set APP_URL "https://$DOMAIN"
    env_set LOG_STACK daily
    env_set LOG_LEVEL info
    env_set DB_DATABASE "$DB_NAME"
    env_set DB_USERNAME "$DB_USER"
    env_set DB_PASSWORD "$DB_PASSWORD"
    # Bases Redis dédiées (Redis peut déjà servir à d'autres logiciels du serveur)
    env_set REDIS_DB "${REDIS_DB:-0}"
    env_set REDIS_CACHE_DB "${REDIS_CACHE_DB:-1}"
    env_set REDIS_PREFIX "${INSTANCE//-/_}_"
    env_set HORIZON_PREFIX "${INSTANCE//-/_}_horizon:"
    # Temps réel : Reverb écoute en local, le serveur web le publie en HTTPS sur /app/
    env_set REVERB_APP_ID "$((RANDOM * RANDOM))"
    env_set REVERB_APP_KEY "$(random 10)"
    env_set REVERB_APP_SECRET "$(random 16)"
    env_set REVERB_HOST 127.0.0.1
    env_set REVERB_PORT "$REVERB_PORT"
    env_set REVERB_SCHEME http
    env_set REVERB_SERVER_HOST 127.0.0.1
    env_set REVERB_SERVER_PORT "$REVERB_PORT"
    env_set VITE_REVERB_APP_KEY "$(env_get REVERB_APP_KEY)"
    env_set VITE_REVERB_HOST "$DOMAIN"
    env_set VITE_REVERB_PORT 443
    env_set VITE_REVERB_SCHEME https
    env_set VAPID_SUBJECT "mailto:$EMAIL"
    env_set WHATSAPP_VERIFY_TOKEN "$(random 12)"
    chown "$APP_USER:" "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"
}

# Dépendances, clés, interface, base de données, caches
build_app() {
    step "Dépendances PHP"
    as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"

    [[ -n "$(env_get APP_KEY)" ]] || as_app "php artisan key:generate --force"

    # Clés des notifications push : une seule fois (les changer désabonne les livreurs)
    if [[ -z "$(env_get VAPID_PUBLIC_KEY)" ]]; then
        local vapid
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

    whatsapp_bridge_deps
    as_app "php artisan optimize"
}

# Connexion WhatsApp par QR code (service whatsapp-bridge, Node) : jeton et port propres à l'installation, puis
# dépendances npm (réinstallées seulement si elles ont changé). Avant « php artisan optimize » (réglages lus en cache).
whatsapp_bridge_deps() {
    step "Service WhatsApp par QR code (dépendances et réglages)"
    [[ -n "$(env_get WHATSAPP_BRIDGE_TOKEN)" ]] || env_set WHATSAPP_BRIDGE_TOKEN "$(random 32)"
    if [[ -z "$(env_get WHATSAPP_BRIDGE_PORT)" ]]; then
        local reverb
        reverb="$(env_get REVERB_SERVER_PORT)"
        reverb="${reverb:-$(env_get REVERB_PORT)}"
        env_set WHATSAPP_BRIDGE_PORT "$(( ${reverb:-6001} + 1000 ))"
    fi
    local lock_sum
    lock_sum="$(sha1sum "$APP_DIR/whatsapp-bridge/package-lock.json" | cut -d' ' -f1)"
    if [[ "$(cat "$APP_DIR/whatsapp-bridge/node_modules/.deploy-lock" 2>/dev/null || true)" != "$lock_sum" ]]; then
        as_app "cd whatsapp-bridge && npm ci --omit=dev --no-audit --no-fund && echo '$lock_sum' > node_modules/.deploy-lock"
    fi
}

# Processus du service WhatsApp sous Supervisor (fichier à part : ajouté aussi aux installations existantes)
install_whatsapp_bridge() {
    sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__NODE__|$(command -v node)|g" -e "s|__USER__|$APP_USER|g" \
        -e "s|__HOME__|$TOOLS_HOME|g" -e "s|__INSTANCE__|$INSTANCE|g" \
        "$APP_DIR/deploy/supervisor-whatsapp.conf" > "/etc/supervisor/conf.d/$INSTANCE-whatsapp.conf"
    supervisorctl reread >/dev/null
    supervisorctl update >/dev/null
    supervisorctl restart "$INSTANCE-whatsapp" || warn "service WhatsApp non démarré : voir storage/logs/whatsapp-bridge.log"
}

# Horizon (files d'attente) et Reverb (temps réel) sous Supervisor
install_supervisor() {
    step "Supervisor (files d'attente Horizon, temps réel Reverb sur le port local $REVERB_PORT)"
    sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHP__|$PHP_BIN|g" -e "s|__USER__|$APP_USER|g" \
        -e "s|__REVERB_PORT__|$REVERB_PORT|g" -e "s|__HOME__|$TOOLS_HOME|g" \
        -e "s|__INSTANCE__|$INSTANCE|g" "$APP_DIR/deploy/supervisor.conf" > "/etc/supervisor/conf.d/$INSTANCE.conf"
    systemctl enable --now supervisor
    supervisorctl reread
    supervisorctl update
    supervisorctl restart "$INSTANCE-horizon" "$INSTANCE-reverb"
    install_whatsapp_bridge
}

# Planificateur Laravel (relances, alertes, rapports, facturation) chaque minute
install_cron() {
    step "Tâches planifiées"
    echo "* * * * * $APP_USER cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1" > "/etc/cron.d/$INSTANCE"
    chmod 644 "/etc/cron.d/$INSTANCE"
}

# Dossier des sauvegardes de l'installation (lu aussi par la console : PLATFORM_BACKUP_PATH)
backup_dir() {
    local dir
    dir="$(env_get PLATFORM_BACKUP_PATH)"
    echo "${dir:-/var/backups/$INSTANCE}"
}

prepare_backup_dir() {
    local dir
    dir="$(backup_dir)"
    mkdir -p "$dir/quotidien" "$dir/hebdo" "$dir/mensuel"
    # Le dossier est traversable (la console lit etat-*.json) ; les sauvegardes ne sont lisibles que par root
    chmod 711 "$dir"
    chmod 700 "$dir/quotidien" "$dir/hebdo" "$dir/mensuel"
}

# Copie de la base avant les migrations d'une mise à jour ; en cas d'échec, la mise à jour s'arrête avant
# tout changement. La copie précédente est gardée à côté.
db_snapshot() {
    local db dir file
    db="$(env_get DB_DATABASE)"
    db="${db:-$DB_NAME}"
    prepare_backup_dir
    dir="$(backup_dir)"
    file="$dir/avant-mise-a-jour.dump"
    sudo -u postgres pg_dump -Fc "$db" > "$file.tmp" && pg_restore --list "$file.tmp" > /dev/null \
        || { rm -f "$file.tmp"; fail "copie de la base « $db » impossible avant la mise à jour : rien n'a été changé."; }
    [[ ! -f "$file" ]] || mv -f "$file" "$dir/avant-mise-a-jour-precedente.dump"
    mv "$file.tmp" "$file"
    chmod 600 "$file"
    info "Copie de la base avant mise à jour : $file ($(du -h "$file" | cut -f1))."
}

# Sauvegardes de la nuit, test de restauration du dimanche, entretien (deploy/backup.sh), rotation des journaux
# et mises à jour de sécurité du système. Relancé à chaque mise à jour : rien à installer à la main.
install_backup() {
    step "Sauvegardes et entretien"
    [[ -n "$(env_get PLATFORM_BACKUP_PATH)" ]] || env_set PLATFORM_BACKUP_PATH "/var/backups/$INSTANCE"
    prepare_backup_dir
    # Production à 2 h 30, autres installations (préproduction) à 3 h 15 : jamais deux en même temps
    local at="30 2" upkeep="50 3" verify="10 5" log="/var/log/$INSTANCE-backup.log" group
    [[ "$(env_get APP_ENV)" == "production" ]] || { at="15 3"; upkeep="20 4"; verify="40 5"; }
    cat > "/etc/cron.d/$INSTANCE-backup" <<EOF
# Sauvegardes et entretien de « $INSTANCE » (deploy/backup.sh, docs/SAUVEGARDES.md)
$at * * * root bash $APP_DIR/deploy/backup.sh >> $log 2>&1
$upkeep * * * root bash $APP_DIR/deploy/backup.sh --maintenance >> $log 2>&1
$verify * * 0 root bash $APP_DIR/deploy/backup.sh --verify >> $log 2>&1
EOF
    chmod 644 "/etc/cron.d/$INSTANCE-backup"

    group="$(id -gn "$APP_USER")"
    cat > "/etc/logrotate.d/$INSTANCE" <<EOF
# Journaux de « $INSTANCE » (les journaux datés laravel-AAAA-MM-JJ.log sont effacés par Laravel après 14 jours)
$APP_DIR/storage/logs/laravel.log {
    su $APP_USER $group
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
$log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
}
EOF
    chmod 644 "/etc/logrotate.d/$INSTANCE"
    install_security_updates
    info "Sauvegarde chaque nuit dans $(backup_dir), test de restauration le dimanche, journal : $log"
}

# Correctifs de sécurité du système installés chaque nuit (Debian : dépôt « security » seulement, sans
# redémarrage du serveur). AUTO_SECURITY_UPDATES=0 dans le .env pour ne pas les installer.
install_security_updates() {
    [[ "$(env_get AUTO_SECURITY_UPDATES)" != "0" ]] || return 0
    if ! dpkg -s unattended-upgrades >/dev/null 2>&1; then
        apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq unattended-upgrades >/dev/null \
            || { warn "mises à jour de sécurité automatiques non installées (apt)."; return 0; }
    fi
    cat > /etc/apt/apt.conf.d/20auto-upgrades <<EOF
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
    cat > /etc/apt/apt.conf.d/52jibiat-upgrades <<EOF
// Jibiat : correctifs de sécurité seulement, jamais de redémarrage automatique du serveur
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
EOF
}

# Réglages de l'installation qui contient ce dossier (update.sh lancé depuis APP_DIR/deploy) : chaque
# installation du serveur se met à jour depuis son propre dossier. Anciennes installations : « livraison ».
load_deploy_conf() {
    local dir conf
    dir="$(readlink -f "$1")"
    for conf in /etc/*-deploy.conf; do
        [[ -f "$conf" ]] || continue
        INSTANCE=livraison APP_DIR=""
        . "$conf"
        if [[ -n "$APP_DIR" && "$(readlink -f "$APP_DIR")" == "$dir" ]]; then
            DEPLOY_CONF="$conf"
            return 0
        fi
    done
    fail "aucune installation enregistrée pour $dir (/etc/*-deploy.conf) : installez d'abord l'application (ispconfig.sh, install.sh ou preprod.sh)."
}

# Paramètres relus par update.sh
save_deploy_conf() {
    cat > "$DEPLOY_CONF" <<EOF
INSTANCE=$INSTANCE
DOMAIN=${DOMAIN:-}
APP_DIR=$APP_DIR
APP_USER=$APP_USER
TOOLS_HOME=$TOOLS_HOME
PHP_BIN=$PHP_BIN
EOF
}

summary() {
    step "Terminé"
    cat <<EOF
    Application      : https://$DOMAIN
    E-commerçants    : https://$DOMAIN  (page d'accueil ; logo et accroche dans Paramètres)
    Back-office      : https://$DOMAIN/admin
    Livreurs         : https://$DOMAIN/livreur
    Dossier          : $APP_DIR (journaux : storage/logs)
    Mise à jour      : bash $APP_DIR/deploy/update.sh

EOF
    if [[ "$DEMO" == "1" ]]; then
        cat <<EOF
    Comptes de démonstration (mot de passe « password », à changer) :
      07 00 00 00 01 administrateur · 07 00 00 00 02 dispatcher · 07 00 00 00 03 caissier
      07 00 00 00 04 et 05 livreurs · 05 00 00 00 01 marchand · 07 00 00 00 06 agent d'entrepôt
      Super administrateur : 07 00 00 00 00
    Avant la mise en service : php artisan user:password 0700000000, puis
    php artisan app:demo-accounts --suspend (comptes au mot de passe « password »)
EOF
    else
        echo "    Créez le premier compte : cd $APP_DIR && sudo -u $APP_USER php artisan app:create-super-admin"
    fi
}
