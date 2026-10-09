# Déployer sur un VPS LWS

Ce guide met l'application en ligne sur un **VPS Linux LWS** (ou tout autre VPS) pour la tester avec
de vrais téléphones : back-office, applications livreur et marchand (PWA), temps réel, notifications push.

Le script `deploy/install.sh` installe et configure tout en une commande :
Nginx, PHP 8.3, PostgreSQL, Redis, Node.js (compilation de l'interface), Horizon (files d'attente),
Reverb (temps réel), les tâches planifiées, le pare-feu et le certificat HTTPS.

## 1. Avant de commencer

| Il faut | Pourquoi |
|---|---|
| Un VPS LWS sous **Ubuntu 24.04** (ou 22.04, ou Debian 12), 2 Go de RAM minimum (4 Go conseillés) | La compilation de l'interface et PostgreSQL ont besoin de mémoire |
| L'accès **root en SSH** (identifiants envoyés par LWS à la livraison du VPS) | Le script installe des paquets système |
| Un **nom de domaine ou sous-domaine**, par ex. `test.mondomaine.ci` | Le HTTPS est obligatoire pour la géolocalisation, l'installation des applications sur téléphone et les notifications push : impossible avec une simple adresse IP |
| L'accès au dépôt GitHub `habibou84/deliverynew` | Le serveur télécharge le code depuis GitHub |

### Faire pointer le domaine vers le VPS

Dans l'espace client LWS (ou chez votre registraire) : **Domaines > Zone DNS**, ajoutez un enregistrement
**A** : nom `test` (pour `test.mondomaine.ci`), valeur = **adresse IP du VPS**. Comptez de quelques
minutes à quelques heures pour la propagation. Pour vérifier depuis votre ordinateur :

```bash
ping test.mondomaine.ci      # doit répondre avec l'adresse IP du VPS
```

## 2. Se connecter au VPS

Depuis un terminal (PowerShell sous Windows, Terminal sous macOS/Linux) :

```bash
ssh root@ADRESSE_IP_DU_VPS
```

## 3. Donner au serveur l'accès au dépôt (clé de déploiement)

Le dépôt est privé : on crée une **clé de déploiement en lecture seule** pour le serveur.

```bash
apt-get update && apt-get install -y git
mkdir -p /var/www/.ssh /var/www/livraison
chown -R www-data:www-data /var/www/.ssh /var/www/livraison && chmod 700 /var/www/.ssh
sudo -u www-data ssh-keygen -t ed25519 -N "" -C "deploy-livraison" -f /var/www/.ssh/id_ed25519
cat /var/www/.ssh/id_ed25519.pub
```

Copiez la ligne affichée (elle commence par `ssh-ed25519`), puis dans GitHub :
**dépôt `deliverynew` > Settings > Deploy keys > Add deploy key**, collez-la, laissez
« Allow write access » **décoché**, enregistrez.

Téléchargez ensuite le code :

```bash
sudo -u www-data -H env GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new" \
  git clone git@github.com:habibou84/deliverynew.git /var/www/livraison
```

> Dépôt public ? Clonez simplement en HTTPS et ajoutez `REPO=https://github.com/habibou84/deliverynew.git`
> à la commande de l'étape 4.

## 4. Lancer l'installation

Remplacez le domaine et l'adresse e-mail (utilisée pour le certificat HTTPS) :

```bash
cd /var/www/livraison
DOMAIN=test.mondomaine.ci EMAIL=moi@mondomaine.ci bash deploy/install.sh
```

Comptez **10 à 15 minutes**. Options (à ajouter devant `bash`) :

| Option | Effet |
|---|---|
| `BRANCH=ccr-e22d186a-wdlowk` | Déployer une branche pas encore fusionnée (par défaut : `main`) |
| `DEMO=0` | Base vide, sans les données et comptes de démonstration (par défaut : avec) |

Le script peut être **relancé sans risque** (par ex. si le certificat HTTPS a échoué parce que le
domaine ne pointait pas encore vers le VPS) : il conserve la base, le `.env` et les clés.

À la fin, il affiche l'adresse de l'application et les comptes de démonstration.

## 5. Tester

| Qui | Adresse | Compte de démonstration (mot de passe `password`) |
|---|---|---|
| Back-office | `https://test.mondomaine.ci/login` | 07 00 00 00 01 (administrateur), 02 (dispatcher), 03 (caissier) |
| Livreur | `https://test.mondomaine.ci/livreur` | 07 00 00 00 04 ou 05 |
| E-commerçant | `https://test.mondomaine.ci/marchand` | 05 00 00 00 01 |

Sur le téléphone, ouvrez l'adresse dans **Chrome** (Android) ou **Safari** (iPhone), connectez-vous,
puis **Ajouter à l'écran d'accueil** pour installer l'application. Le livreur active ensuite les
notifications depuis le bandeau de ses missions (sur iPhone : iOS 16.4 minimum, application installée).

> Serveur de test accessible à tous : changez les mots de passe des comptes de démonstration (menu
> Utilisateurs), ou installez avec `DEMO=0` puis créez le premier compte :
> `cd /var/www/livraison && sudo -u www-data php artisan app:create-super-admin`.

## 6. Mettre à jour après une modification du code

```bash
bash /var/www/livraison/deploy/update.sh
```

Le script met le site en maintenance quelques secondes, récupère la dernière version de la branche,
met à jour les dépendances, recompile l'interface, applique les migrations et redémarre Horizon et Reverb.

## 7. Facultatif

- **WhatsApp réel** : dans `/var/www/livraison/.env`, mettez `WHATSAPP_DRIVER=meta` et
  `WHATSAPP_APP_SECRET`, puis dans Meta configurez le webhook
  `https://test.mondomaine.ci/api/webhooks/whatsapp` avec le jeton `WHATSAPP_VERIFY_TOKEN` du `.env`
  (généré à l'installation). Le numéro se saisit ensuite dans **Paramètres > WhatsApp**.
- **Commandes WhatsApp comprises par Claude** : `ANTHROPIC_API_KEY=…` dans le `.env`.
- Après toute modification du `.env` : `cd /var/www/livraison && sudo -u www-data php artisan optimize`
  puis `supervisorctl restart all`. Pour les variables `VITE_…`, relancez `deploy/update.sh`
  (l'interface doit être recompilée).
- **Sauvegarde quotidienne de la base** :
  ```bash
  mkdir -p /var/backups/livraison
  echo '30 2 * * * postgres pg_dump livraison | gzip > /var/backups/livraison/$(date +\%F).sql.gz && find /var/backups/livraison -mtime +14 -delete' > /etc/cron.d/livraison-backup
  ```

## 8. En cas de problème

| Symptôme | À vérifier |
|---|---|
| Page blanche ou erreur 500 | `tail -50 /var/www/livraison/storage/logs/laravel-*.log` |
| Pas de temps réel (pas de son, carte figée) | `supervisorctl status` (livraison-reverb doit être `RUNNING`), `tail storage/logs/reverb.log` |
| Notifications ou WhatsApp qui ne partent pas | `supervisorctl status` (livraison-horizon), `sudo -u www-data php artisan horizon:status`, `tail storage/logs/horizon.log` |
| Relances et alertes automatiques absentes | `cat /etc/cron.d/livraison`, `sudo -u www-data php artisan schedule:list` |
| Erreur Nginx | `nginx -t`, `tail /var/log/nginx/error.log` |
| Certificat HTTPS | Le domaine doit pointer vers le VPS, puis `certbot --nginx -d test.mondomaine.ci` |

Architecture installée :

```
Internet ──HTTPS──▶ Nginx ──▶ PHP-FPM 8.3 ──▶ Laravel ──▶ PostgreSQL
                     │                          │
                     └─ /app (WebSocket) ──▶ Reverb (127.0.0.1:8080)
                                                │
                     Redis ◀── files d'attente ─┴─ Horizon (Supervisor)
                     cron : schedule:run chaque minute (relances, alertes, rapports)
```
