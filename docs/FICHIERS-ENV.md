# Fichiers .env des serveurs

Où se trouve le fichier `.env` de chaque installation, et quoi lancer après l'avoir modifié.
Ce fichier ne contient **aucun secret** : les clés et mots de passe restent dans les `.env` du serveur.

## Tableau

| Application | Environnement | Fichier .env | Utilisateur système |
|---|---|---|---|
| Jibiat (livraison) | Préproduction | `/var/www/jibiat-preprod/.env` | `jibiat_preprod` |
| Jibiat (livraison) | Production (ISPConfig) | `/var/www/clients/client0/web2/web/livraison/.env` | `web2` |
| Jibiat Marchand | Préproduction | `/var/www/jibiat-marchand/.env` | `jibiat` |
| Jibiat Marchand | Production | `/var/www/jibiat-production/.env` | `jibiat_production` |
| Ancienne application (Laravel 5.8) | Production | à compléter : dossier connu de vous seul | à compléter |

Le service WhatsApp par QR code (whatsapp-bridge) n'a pas de `.env` à lui : il lit celui de l'application
(`WHATSAPP_BRIDGE_TOKEN`, `WHATSAPP_BRIDGE_PORT`).

## Après une modification du .env

**Jibiat, préproduction**
```bash
nano /var/www/jibiat-preprod/.env
cd /var/www/jibiat-preprod && sudo -u jibiat_preprod php artisan optimize && sudo -u jibiat_preprod php artisan horizon:terminate
```

**Jibiat, production**
```bash
nano /var/www/clients/client0/web2/web/livraison/.env
cd /var/www/clients/client0/web2/web/livraison
sudo -u $(stat -c %U .) env HOME=/tmp php artisan optimize
sudo -u $(stat -c %U .) env HOME=/tmp php artisan horizon:terminate
```
Clé Firebase : `/var/www/clients/client0/web2/private/firebase.json` (hors du dossier public).

**Jibiat Marchand, préproduction**
```bash
nano /var/www/jibiat-marchand/.env
cd /var/www/jibiat-marchand && sudo -u jibiat php artisan optimize
```
Clé Firebase : `/var/www/jibiat-marchand/storage/app/firebase.json`.

**Jibiat Marchand, production**
```bash
nano /var/www/jibiat-production/.env
cd /var/www/jibiat-production && sudo -u jibiat_production php artisan optimize
```
Clé Firebase : `/var/www/jibiat-production/storage/app/firebase.json`.

**Ancienne application** : après modification (par exemple `JIBIAT_SYNC_KEY` ou la clé de bascule), lancer
`php artisan config:cache` dans son dossier.

## Rappels

- Ne jamais copier un `.env` dans une conversation, un ticket ou un dépôt Git.
- Une valeur modifiée n'est prise en compte qu'après `php artisan optimize` (et `horizon:terminate` pour les files).
- Sauvegarde avant modification : `cp .env .env.sauvegarde-$(date +%F)`.
