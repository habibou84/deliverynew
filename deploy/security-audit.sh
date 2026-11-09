#!/usr/bin/env bash
# Audit de sécurité du serveur — LECTURE SEULE : ce script ne modifie rien.
#
# Contrôle le système (mises à jour, SSH, pare-feu, fail2ban, comptes), les services (PostgreSQL, MySQL, Redis,
# Reverb liés à la machine seulement), PHP, chaque application Laravel (.env, droits des fichiers, fichiers PHP
# suspects, code modifié sur le serveur, sauvegardes) et, de l'extérieur, chaque domaine (fichiers sensibles
# exposés, en-têtes, certificat, redirection https).
#
# Usage (en root, pour tout voir) :
#   sudo bash deploy/security-audit.sh                                     # application du dossier courant
#   sudo APPS="/var/www/livraison /var/www/marchand" \
#        DOMAINS="jibiat.com marchand.jibiat.com" bash deploy/security-audit.sh
#   ... --report /root/audit-$(date +%F).txt                               # copie du rapport dans un fichier
#
# Code de sortie : 0 tout va bien, 1 points à surveiller, 2 au moins un danger.
# À lancer après chaque mise en production importante et au moins une fois par mois (docs/SECURITE-OUTILS.md).

set -uo pipefail

REPORT=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --report) REPORT="${2:-}"; shift 2 ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
        *) echo "Option inconnue : $1" >&2; exit 64 ;;
    esac
done
if [[ -n "$REPORT" ]]; then
    umask 077
    exec > >(tee "$REPORT") 2>&1
fi

# Applications : APPS, sinon le dossier qui contient ce script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPS="${APPS:-$(dirname "$SCRIPT_DIR")}"
DOMAINS="${DOMAINS:-}"
# Ports qu'on accepte d'exposer à Internet (ISPConfig : ajoutez par exemple 8080 si la console doit rester ouverte)
ALLOWED_PORTS="${ALLOWED_PORTS:-22 80 443}"

OK=0; WARN=0; BAD=0
ok()   { printf '  \033[32m[OK]\033[0m        %s\n' "$*"; OK=$((OK + 1)); }
warn() { printf '  \033[33m[ATTENTION]\033[0m %s\n' "$*"; WARN=$((WARN + 1)); }
bad()  { printf '  \033[31m[DANGER]\033[0m    %s\n' "$*"; BAD=$((BAD + 1)); }
info() { printf '  \033[2m%s\033[0m\n' "$*"; }
section() { printf '\n\033[1;34m== %s ==\033[0m\n' "$*"; }
have() { command -v "$1" > /dev/null 2>&1; }

printf 'Audit de sécurité — %s — %s\n' "$(hostname -f 2>/dev/null || hostname)" "$(date '+%F %T %Z')"
[[ $EUID -eq 0 ]] || warn "Lancé sans root : une partie des contrôles est incomplète (relancer avec sudo)."

# ---------------------------------------------------------------------------------------------------------------
section "Système"
if [[ -r /etc/os-release ]]; then . /etc/os-release; info "Système : ${PRETTY_NAME:-inconnu}, noyau $(uname -r)"; fi

if have apt-get; then
    upgradable="$(apt list --upgradable 2>/dev/null | grep -c -- '-security' || true)"
    if [[ "${upgradable:-0}" -gt 0 ]]; then
        bad "$upgradable mise(s) à jour de sécurité en attente : apt update && apt upgrade"
    else
        ok "Aucune mise à jour de sécurité en attente (selon le dernier apt update)."
    fi
    last_update="$(stat -c %Y /var/cache/apt/pkgcache.bin 2>/dev/null || echo 0)"
    if (( $(date +%s) - last_update > 7 * 86400 )); then
        warn "La liste des paquets n'a pas été rafraîchie depuis plus de 7 jours (apt update)."
    fi
    if dpkg -l unattended-upgrades 2>/dev/null | grep -q '^ii' \
        && grep -qs 'Unattended-Upgrade "1"' /etc/apt/apt.conf.d/20auto-upgrades; then
        ok "Mises à jour de sécurité automatiques actives (unattended-upgrades)."
    else
        warn "Mises à jour automatiques absentes : apt install unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades"
    fi
fi
[[ -f /var/run/reboot-required ]] && warn "Un redémarrage est demandé (noyau ou bibliothèque mis à jour)."

# ---------------------------------------------------------------------------------------------------------------
section "Comptes"
others_uid0="$(awk -F: '$3 == 0 && $1 != "root" {print $1}' /etc/passwd)"
[[ -n "$others_uid0" ]] && bad "Comptes avec les droits root (UID 0) autres que root : $others_uid0" || ok "Seul root a l'UID 0."
if [[ -r /etc/shadow ]]; then
    empty="$(awk -F: '$2 == "" {print $1}' /etc/shadow)"
    [[ -n "$empty" ]] && bad "Comptes sans mot de passe : $empty" || ok "Aucun compte sans mot de passe."
fi
if [[ $EUID -eq 0 ]]; then
    nopasswd="$(grep -rhs 'NOPASSWD' /etc/sudoers /etc/sudoers.d 2>/dev/null | grep -v '^\s*#' || true)"
    [[ -n "$nopasswd" ]] && warn "Règles sudo sans mot de passe (à justifier) : $(echo "$nopasswd" | tr '\n' ';' | cut -c1-200)"
    for home in /root /home/*; do
        keys="$home/.ssh/authorized_keys"
        [[ -f "$keys" ]] && info "Clés SSH autorisées pour $(basename "$home") : $(grep -cv '^\s*\(#\|$\)' "$keys") (vérifier qu'on les connaît toutes)"
    done
fi

# ---------------------------------------------------------------------------------------------------------------
section "SSH"
if have sshd && [[ $EUID -eq 0 ]]; then
    sshcfg="$(sshd -T 2>/dev/null)"
    get() { awk -v k="$1" '$1 == k {print $2; exit}' <<< "$sshcfg"; }
    case "$(get permitrootlogin)" in
        no) ok "Connexion SSH directe de root interdite." ;;
        prohibit-password|without-password) ok "root seulement par clé SSH (PermitRootLogin prohibit-password)." ;;
        *) bad "root peut se connecter en SSH avec un mot de passe : PermitRootLogin prohibit-password (ou no)" ;;
    esac
    if [[ "$(get passwordauthentication)" == "no" ]]; then
        ok "Connexion SSH par clé uniquement."
    else
        warn "Connexion SSH par mot de passe permise : PasswordAuthentication no (après avoir posé votre clé)"
    fi
    [[ "$(get maxauthtries)" -le 4 ]] 2>/dev/null && ok "MaxAuthTries $(get maxauthtries)." || warn "MaxAuthTries $(get maxauthtries) : 3 ou 4 suffisent."
    [[ "$(get x11forwarding)" == "yes" ]] && warn "X11Forwarding activé (inutile sur un serveur)."
else
    info "sshd non contrôlé (non installé ou pas root)."
fi

# ---------------------------------------------------------------------------------------------------------------
section "Pare-feu et ports ouverts"
fw="aucun"
if have ufw && ufw status 2>/dev/null | grep -q 'Status: active'; then fw="ufw"
elif have nft && [[ -n "$(nft list ruleset 2>/dev/null | grep -E 'hook input.*policy drop')" ]]; then fw="nftables"
elif have iptables && iptables -S INPUT 2>/dev/null | grep -q -- '-P INPUT DROP'; then fw="iptables"
fi
[[ "$fw" == "aucun" ]] && bad "Aucun pare-feu actif qui bloque par défaut (ufw default deny incoming ; ufw allow 22,80,443/tcp ; ufw enable)" || ok "Pare-feu actif ($fw)."

if have ss; then
    while read -r addr proc; do
        port="${addr##*:}"
        host="${addr%:*}"
        case "$host" in 127.*|'[::1]'|'::1'|localhost) continue ;; esac
        if [[ " $ALLOWED_PORTS " == *" $port "* ]]; then continue; fi
        warn "Port $port ouvert sur $host ($proc) : le fermer au pare-feu ou le lier à 127.0.0.1, ou l'ajouter à ALLOWED_PORTS s'il est voulu."
    done < <(ss -Htlnp 2>/dev/null | awk '{p=$6; sub(/.*\(\("/, "", p); sub(/".*/, "", p); print $4, p}' | sort -u)
    ok "Ports en écoute vérifiés (autorisés : $ALLOWED_PORTS)."
fi

if systemctl is-active --quiet fail2ban 2>/dev/null; then
    ok "fail2ban actif ($(fail2ban-client status 2>/dev/null | awk -F: '/Jail list/ {gsub(/^[ \t]+/, "", $2); print $2}'))."
else
    warn "fail2ban absent ou arrêté : apt install fail2ban (prison sshd au minimum)."
fi

# ---------------------------------------------------------------------------------------------------------------
section "Services de données"
check_local() { # nom port
    local listen
    listen="$(ss -Htln "sport = :$2" 2>/dev/null | awk '{print $4}' | sort -u | tr '\n' ' ')"
    [[ -z "$listen" ]] && { info "$1 : pas en écoute sur le port $2."; return; }
    if grep -qvE '^(127\.|\[::1\]|::1)' <<< "$(tr ' ' '\n' <<< "$listen" | sed '/^$/d')"; then
        bad "$1 écoute sur une adresse publique ($listen) : le lier à 127.0.0.1."
    else
        ok "$1 écoute seulement en local."
    fi
}
check_local PostgreSQL 5432
check_local MySQL 3306
check_local Redis 6379
if have redis-cli && ss -Htln 'sport = :6379' 2>/dev/null | grep -q .; then
    if redis-cli -h 127.0.0.1 ping 2>/dev/null | grep -q PONG; then
        warn "Redis répond sans mot de passe : requirepass dans /etc/redis/redis.conf et REDIS_PASSWORD dans les .env."
    else
        ok "Redis demande un mot de passe."
    fi
fi

# ---------------------------------------------------------------------------------------------------------------
section "PHP"
if have php; then
    for ini in expose_php display_errors allow_url_include; do
        val="$(php -r "echo ini_get('$ini');" 2>/dev/null)"
        case "$ini:$val" in
            expose_php:1|expose_php:On) warn "expose_php activé (la version de PHP est annoncée) : expose_php = Off" ;;
            display_errors:1|display_errors:On|display_errors:stdout) warn "display_errors activé en ligne de commande (vérifier aussi PHP-FPM)." ;;
            allow_url_include:1|allow_url_include:On) bad "allow_url_include activé : le désactiver." ;;
        esac
    done
    for fpm in /etc/php/*/fpm/php.ini; do
        [[ -f "$fpm" ]] || continue
        grep -qiE '^\s*display_errors\s*=\s*(on|1)' "$fpm" && bad "display_errors = On dans $fpm (les erreurs s'affichent aux visiteurs)."
        grep -qiE '^\s*expose_php\s*=\s*(on|1)' "$fpm" && warn "expose_php = On dans $fpm."
    done
    info "PHP $(php -r 'echo PHP_VERSION;')"
fi

# ---------------------------------------------------------------------------------------------------------------
for APP in $APPS; do
    section "Application $APP"
    if [[ ! -f "$APP/artisan" ]]; then warn "Pas d'application Laravel dans $APP."; continue; fi
    env="$APP/.env"
    if [[ ! -f "$env" ]]; then bad "Fichier .env absent."; continue; fi
    val() { { grep -E "^$1=" "$env" || true; } | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

    perms="$(stat -c '%a' "$env")"
    if [[ "${perms: -1}" != "0" ]]; then bad ".env lisible par tous (droits $perms) : chmod 640 $env"
    else ok ".env protégé (droits $perms, propriétaire $(stat -c '%U:%G' "$env"))."; fi

    [[ "$(val APP_ENV)" == "production" ]] && ok "APP_ENV=production." || warn "APP_ENV=$(val APP_ENV) (préproduction ?)."
    case "$(val APP_DEBUG | tr '[:upper:]' '[:lower:]')" in
        false|0|'') ok "APP_DEBUG coupé." ;;
        *) [[ "$(val APP_ENV)" == "production" ]] && bad "APP_DEBUG=true en production : les erreurs affichent les secrets." || warn "APP_DEBUG=true." ;;
    esac
    key="$(val APP_KEY)"; [[ ${#key} -ge 40 ]] && ok "APP_KEY présente." || bad "APP_KEY absente ou courte : php artisan key:generate"
    [[ "$(val APP_URL)" == https://* ]] && ok "APP_URL en https." || bad "APP_URL n'est pas en https."
    case "$(val LOG_LEVEL)" in debug) warn "LOG_LEVEL=debug : trop bavard en production (info)." ;; esac
    [[ "$(val SESSION_SECURE_COOKIE)" == "false" ]] && bad "SESSION_SECURE_COOKIE=false : le cookie de session part aussi en http."
    dbpass="$(val DB_PASSWORD)"; [[ ${#dbpass} -lt 16 ]] && warn "Mot de passe de la base court (${#dbpass} caractères)."
    for k in SMS_DRIVER; do [[ "$(val $k)" == "log" && "$(val APP_ENV)" == "production" ]] && warn "$k=log en production (aucun SMS ne part)."; done

    owner="$(stat -c '%U' "$APP/app" 2>/dev/null)"
    runner="$(ps -eo user,comm | awk '/php-fpm/ && $1 != "root" {print $1; exit}')"
    if [[ -n "$runner" && "$owner" == "$runner" ]]; then
        warn "Le code appartient à l'utilisateur de PHP ($runner) : une faille d'envoi de fichier pourrait réécrire le code. Idéal : code à un autre utilisateur, seuls storage/ et bootstrap/cache/ inscriptibles par $runner."
    fi
    ww="$(find "$APP" -path "$APP/vendor" -prune -o -path "$APP/node_modules" -prune -o -perm -0002 -type f -print 2>/dev/null | head -5)"
    [[ -n "$ww" ]] && bad "Fichiers modifiables par tous : $(echo "$ww" | tr '\n' ' ')" || ok "Aucun fichier modifiable par tous."

    # Fichiers PHP là où il ne devrait pas y en avoir (porte dérobée « webshell »)
    stray="$(find "$APP/public" "$APP/storage" -type f \( -name '*.php' -o -name '*.phtml' -o -name '*.phar' -o -name '.htaccess' \) \
        ! -path "$APP/public/index.php" ! -path "$APP/storage/framework/views/*" ! -path "$APP/public/.htaccess" 2>/dev/null | head -10)"
    [[ -n "$stray" ]] && bad "Fichiers PHP inattendus (porte dérobée ?) : $(echo "$stray" | tr '\n' ' ')" || ok "Aucun fichier PHP inattendu dans public/ et storage/."
    suspect="$(grep -rlE 'eval\s*\(\s*(base64_decode|gzinflate|str_rot13)|assert\s*\(\s*\$_(POST|GET|REQUEST)|preg_replace\s*\(.*/e' \
        "$APP/app" "$APP/public" "$APP/routes" "$APP/config" "$APP/bootstrap" 2>/dev/null | head -5)"
    [[ -n "$suspect" ]] && bad "Code obfusqué typique d'une porte dérobée : $suspect" || ok "Aucun code obfusqué suspect dans le code de l'application."

    if [[ -d "$APP/.git" ]] && have git; then
        changed="$(git -C "$APP" -c safe.directory="$APP" status --porcelain --untracked-files=normal 2>/dev/null \
            | grep -vE ' (public/build|storage/|bootstrap/cache/|\.env)' | head -10)"
        if [[ -n "$changed" ]]; then
            bad "Code modifié ou ajouté sur le serveur, hors Git (à examiner) : $(echo "$changed" | tr '\n' ';' | cut -c1-300)"
        else
            ok "Le code correspond à Git ($(git -C "$APP" -c safe.directory="$APP" rev-parse --short HEAD 2>/dev/null), branche $(git -C "$APP" -c safe.directory="$APP" branch --show-current 2>/dev/null))."
        fi
    fi
    recent="$(find "$APP/app" "$APP/config" "$APP/routes" "$APP/public" -type f -newer "$APP/.git/HEAD" -mmin -10080 2>/dev/null | grep -v '/public/build' | head -5)"
    [[ -n "$recent" ]] && warn "Fichiers modifiés après le dernier déploiement : $(echo "$recent" | tr '\n' ' ')"

    logsize="$(du -sm "$APP/storage/logs" 2>/dev/null | cut -f1)"
    [[ "${logsize:-0}" -gt 1024 ]] && warn "Journaux de $logsize Mo dans storage/logs (rotation ? LOG_STACK=daily)."
    if [[ -f "$APP/storage/logs/laravel.log" ]]; then
        hits="$(tail -n 20000 "$APP/storage/logs/laravel.log" 2>/dev/null | grep -cE 'SQLSTATE|TokenMismatch|ThrottleRequestsException|Unauthenticated' || true)"
        info "Dernières 20 000 lignes du journal : $hits erreurs SQL / jetons / limites d'essais (un pic soudain mérite un coup d'œil)."
    fi

    backup_dir="$(val PLATFORM_BACKUP_PATH)"; backup_dir="${backup_dir:-/var/backups/$(basename "$APP")}"
    if [[ -d "$backup_dir" ]]; then
        last="$(find "$backup_dir" -type f -mtime -1 2>/dev/null | head -1)"
        [[ -n "$last" ]] && ok "Sauvegarde de moins de 24 h dans $backup_dir." || bad "Aucune sauvegarde de moins de 24 h dans $backup_dir."
        loose="$(find "$backup_dir" -type f -perm /o+r 2>/dev/null | head -3)"
        [[ -n "$loose" ]] && bad "Sauvegardes lisibles par tous : $loose"
        plain="$(find "$backup_dir" -type f \( -name '*.sql' -o -name '*.sql.gz' -o -name '*.dump' \) -mtime -7 2>/dev/null | head -3)"
        [[ -n "$plain" ]] && warn "Sauvegardes récentes non chiffrées : $plain"
    else
        info "Dossier de sauvegarde $backup_dir introuvable (PLATFORM_BACKUP_PATH du .env ?)."
    fi
done

# ---------------------------------------------------------------------------------------------------------------
if [[ -n "$DOMAINS" ]] && have curl; then
    for DOMAIN in $DOMAINS; do
        section "Vu d'Internet : $DOMAIN"
        base="https://$DOMAIN"
        headers="$(curl -sS -m 15 -o /dev/null -D - "$base/" 2>/dev/null | tr -d '\r')"
        if [[ -z "$headers" ]]; then bad "$base ne répond pas."; continue; fi
        hdr() { grep -i "^$1:" <<< "$headers" | head -1 | cut -d: -f2- | sed 's/^ //'; }
        [[ -n "$(hdr strict-transport-security)" ]] && ok "HSTS présent." || warn "En-tête HSTS absent."
        [[ -n "$(hdr x-frame-options)$(hdr content-security-policy | grep -o frame-ancestors)" ]] && ok "Protection contre l'affichage dans un cadre." || warn "X-Frame-Options absent."
        [[ -n "$(hdr x-content-type-options)" ]] && ok "X-Content-Type-Options présent." || warn "X-Content-Type-Options absent."
        server="$(hdr server)"; [[ "$server" =~ [0-9] ]] && warn "Version du serveur annoncée ($server) : server_tokens off (Nginx) / ServerTokens Prod (Apache)."
        [[ -n "$(hdr x-powered-by)" ]] && warn "X-Powered-By annonce $(hdr x-powered-by) : expose_php = Off."

        redirect="$(curl -sS -m 10 -o /dev/null -w '%{http_code} %{redirect_url}' "http://$DOMAIN/" 2>/dev/null)"
        [[ "$redirect" =~ ^30[1278]\ https:// ]] && ok "http redirige vers https." || warn "http ne redirige pas vers https ($redirect)."

        for path in /.env /.env.backup /.git/HEAD /.git/config /storage/logs/laravel.log /composer.json /composer.lock \
            /package.json /artisan /phpinfo.php /info.php /vendor/autoload.php /livraison/.env /deploy/backup.sh /.DS_Store \
            /telescope /horizon /_ignition/health-check /storage/app/private /database/database.sqlite /backup.sql; do
            code="$(curl -sS -m 10 -o /tmp/.audit-body.$$ -w '%{http_code}' "$base$path" 2>/dev/null)"
            if [[ "$code" == "200" ]]; then
                if grep -qaE 'APP_KEY|DB_PASSWORD|repositoryformatversion|ref: refs/|"require"|<\?php|phpinfo\(\)|SQLSTATE|local\.ERROR|production\.ERROR' /tmp/.audit-body.$$; then
                    bad "$base$path est lisible depuis Internet (fichier sensible) !"
                else
                    info "$base$path répond 200 (page de l'application ? à vérifier)."
                fi
            fi
        done
        rm -f /tmp/.audit-body.$$
        ok "Recherche des fichiers sensibles exposés terminée."

        if have openssl; then
            end="$(echo | openssl s_client -servername "$DOMAIN" -connect "$DOMAIN:443" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)"
            if [[ -n "$end" ]]; then
                days=$(( ($(date -d "$end" +%s) - $(date +%s)) / 86400 ))
                (( days < 0 )) && bad "Certificat expiré." || { (( days < 15 )) && warn "Certificat expire dans $days jours." || ok "Certificat valable encore $days jours."; }
            fi
            for proto in tls1 tls1_1; do
                if echo | openssl s_client -"$proto" -servername "$DOMAIN" -connect "$DOMAIN:443" > /dev/null 2>&1; then
                    warn "Ancien protocole accepté ($proto) : ssl_protocols TLSv1.2 TLSv1.3;"
                fi
            done
        fi
    done
fi

# ---------------------------------------------------------------------------------------------------------------
section "Résumé"
printf '  %d OK, %d à surveiller, %d danger(s)\n' "$OK" "$WARN" "$BAD"
(( BAD > 0 )) && exit 2
(( WARN > 0 )) && exit 1
exit 0
