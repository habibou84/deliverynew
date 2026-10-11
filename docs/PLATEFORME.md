# Mise en service de la plateforme jibiat.com

Ce guide fait passer le serveur actuel (une seule entreprise, installé avec `deploy/ispconfig.sh`)
en **plateforme multi-entreprises** :

| Adresse | Pour qui |
|---|---|
| `https://jibiat.com` | Votre site de présentation (site à part dans ISPConfig) |
| `https://admin.jibiat.com` | Console du super administrateur : entreprises, assistance |
| `https://express.jibiat.com` | Une entreprise : marchands sur `/`, équipe sur `/admin`, livreurs sur `/livreur` |

Une seule installation sert toutes les entreprises : un seul code, une seule base, une seule
mise à jour. Chaque nouvelle entreprise créée dans la console reçoit aussitôt son adresse, sans
rien toucher sur le serveur ni dans les DNS.

Comptez environ une heure, dont l'attente de la propagation DNS. L'application quitte son
ancienne adresse (ex. `test.jibiat.com`) à l'étape 4, quand chaque entreprise reçoit la sienne.

---

## Étape 1 — DNS chez Cloudflare

### 1.1 Le domaine dans Cloudflare (si ce n'est pas déjà fait)

1. Cloudflare > **Add a site** > `jibiat.com` > formule **Free**.
2. Cloudflare importe les enregistrements existants de LWS. **Gardez tout ce qui concerne la
   messagerie** (MX, `mail`, `autoconfig`, SPF/DKIM en TXT…) en mode **DNS only** (nuage gris).
3. Chez LWS (espace client > Domaines > `jibiat.com` > **Serveurs DNS**), remplacez les serveurs
   de noms par les deux indiqués par Cloudflare (`xxx.ns.cloudflare.com`).
4. Attendez que Cloudflare affiche le domaine **Active** (de quelques minutes à quelques heures).

### 1.2 Les enregistrements de la plateforme

Cloudflare > `jibiat.com` > **DNS** > **Records**. Supprimez les anciens enregistrements A/AAAA/CNAME
de `jibiat.com` et `www` qui pointent vers l'hébergement LWS, puis ajoutez :

| Type | Nom | Contenu | Proxy |
|---|---|---|---|
| A | `@` | adresse IP du serveur | Proxied (nuage orange) |
| A | `*` | adresse IP du serveur | Proxied (nuage orange) |

L'enregistrement `*` couvre `admin.jibiat.com` et toutes les entreprises. Un enregistrement
plus précis (ex. `mail`) reste prioritaire : la messagerie n'est pas touchée.

### 1.3 Réglages Cloudflare

- **SSL/TLS** > **Overview** : mode **Full (strict)**.
- **SSL/TLS** > **Edge Certificates** : **Always Use HTTPS** activé.
- **Network** : **WebSockets** activé (c'est le cas par défaut) — nécessaire au temps réel.

> Le mode proxy (nuage orange) masque l'adresse du serveur et le protège des attaques. Le
> serveur lit l'adresse réelle des visiteurs dans les en-têtes de Cloudflare (`TRUSTED_PROXIES`,
> réglé à l'étape 4).

---

## Étape 2 — Certificat d'origine Cloudflare

Le certificat « d'origine » chiffre la liaison Cloudflare ↔ serveur. Il couvre `jibiat.com` et
`*.jibiat.com`, vaut 15 ans et **ne demande aucun renouvellement**.

1. Cloudflare > **SSL/TLS** > **Origin Server** > **Create Certificate**.
2. « Generate private key and CSR with Cloudflare », type **RSA (2048)**.
3. Noms : `jibiat.com` et `*.jibiat.com` (proposés par défaut). Validité : **15 years**.
4. **Create**. Format **PEM**. Copiez dans deux fichiers texte sur votre ordinateur :
   - **Origin Certificate** → `jibiat-cert.pem`
   - **Private Key** → `jibiat-key.pem` — **elle n'est affichée qu'une seule fois.**

Gardez la clé privée pour vous : elle ne doit circuler ni par e-mail ni par messagerie.

---

## Étape 3 — Les sites dans ISPConfig

Deux sites web, chacun avec son rôle :

| Site ISPConfig | Adresses servies | Contenu |
|---|---|---|
| `jibiat.com` | `jibiat.com`, `www.jibiat.com` | Votre site de présentation (WordPress ou autre) |
| Le site de l'application, sous son nom actuel (ex. `test.jibiat.com`) | toutes les autres : `admin.jibiat.com`, `express.jibiat.com`… | L'application |

**Ne renommez pas le site de l'application** : il reçoit les sous-domaines par la directive
ci-dessous. (Apache lit les sites par ordre alphabétique de leur nom : `jibiat.com` passe avant
`test.jibiat.com`, donc `www.jibiat.com` reste au site de présentation.)

### 3.1 Le site de l'application

ISPConfig > **Sites** > **Website** > le site de l'application :

1. Onglet **Domain** :
   - **SSL** : coché ;
   - **Let's Encrypt SSL** : **décoché** (Let's Encrypt via ISPConfig ne sait pas couvrir
     `*.jibiat.com` ; le certificat Cloudflare le remplace).
2. **Save**.
3. Onglet **SSL** :
   - **SSL Key** : contenu de `jibiat-key.pem` ;
   - **SSL Certificate** : contenu de `jibiat-cert.pem` ;
   - **SSL Bundle** : vide ;
   - **SSL Action** : **Save Certificate**.
4. Onglet **Options**, champ **Apache Directives** : ajoutez en **première ligne**, sans toucher
   au reste :
   ```
   ServerAlias *.jibiat.com
   ```
5. **Save**.

### 3.2 Le site de présentation `jibiat.com`

Onglet **Domain** : **Auto-Subdomain** `www.`, **SSL** coché. Pour le certificat : Let's Encrypt
(case cochée), ou le même certificat d'origine que l'application, collé dans l'onglet **SSL**.

Attendez une minute qu'ISPConfig régénère Apache.

> Sans site de présentation, un seul site suffit : celui de l'application, renommé `jibiat.com`
> avec **Auto-Subdomain** `*.` (et sans la directive `ServerAlias`). La page `jibiat.com` est
> alors celle de la plateforme, qui invite les entreprises à vous contacter.

---

## Étape 4 — L'application

En SSH sur le serveur, en root. `<dossier>` est le dossier de l'application (affiché à
l'installation, ex. `/var/www/clients/client1/web1/web/livraison`) :

```bash
# Dernière version (contient ce script)
bash <dossier>/deploy/update.sh

# Passage en plateforme
PLATFORM_DOMAIN=jibiat.com bash <dossier>/deploy/platform.sh
```

Le script :

- vérifie que `jibiat.com`, `admin.jibiat.com` et une adresse d'entreprise au hasard répondent ;
- règle le `.env` : `PLATFORM_DOMAIN=jibiat.com`, `APP_URL=https://admin.jibiat.com`,
  `TRUSTED_PROXIES=cloudflare`, temps réel sur l'adresse ouverte ;
- recompile l'interface et redémarre les processus ;
- affiche les adresses de la console et des entreprises, et ce qu'il reste à corriger.

### Adresse de votre entreprise actuelle

Elle vient de son nom (ex. `livraison-express-ci.jibiat.com`). Pour la raccourcir :

```bash
cd <dossier>
sudo -u <utilisateur du site> php artisan platform:status --rename=livraison-express-ci:express
```

(ou plus tard, depuis la console : fiche de l'entreprise > Adresse). Lettres minuscules,
chiffres et tirets, 3 à 40 caractères ; `www`, `admin`, `api`… sont réservés.

### Compte super administrateur

`platform:status` indique s'il en existe un. Sinon :

```bash
cd <dossier>
sudo -u <utilisateur du site> php artisan app:create-super-admin
```

Ce compte ne se connecte **que** sur `https://admin.jibiat.com`.

### Mots de passe et comptes de démonstration

Un serveur installé avec les données de démonstration (`DEMO=1`, par défaut) contient des comptes
dont le mot de passe est `password`, dont un super administrateur (`07 00 00 00 00`). À faire
avant d'accueillir de vraies entreprises :

```bash
cd <dossier>
# Changer un mot de passe (demandé deux fois, jamais écrit dans l'historique) ; le compte est déconnecté
sudo -u <utilisateur du site> php artisan user:password 0700000000

# Lister les comptes qui ont encore le mot de passe « password », puis les suspendre
sudo -u <utilisateur du site> php artisan app:demo-accounts
sudo -u <utilisateur du site> php artisan app:demo-accounts --suspend
```

`--suspend` ne touche jamais aux super administrateurs (ce serait perdre l'accès à la console) :
changez leur mot de passe avec `user:password`. Un compte suspendu se réactive depuis le
back-office (Utilisateurs). Si un numéro a un compte dans plusieurs entreprises, `user:password`
demande laquelle : `--company=express`, ou `--company=console` pour un super administrateur.

---

## Étape 5 — Vérifications

1. `https://jibiat.com` et `https://www.jibiat.com` : votre site de présentation.
2. `https://admin.jibiat.com` : connexion du super administrateur, liste des entreprises.
3. `https://express.jibiat.com/admin` : connexion de l'équipe de l'entreprise.
4. `https://express.jibiat.com/livreur` et `https://express.jibiat.com` : livreurs et marchands.
5. Temps réel : ouvrez la carte des livreurs et, sur un autre appareil, faites avancer une
   course : la carte et le tableau de bord se mettent à jour sans recharger.
6. `https://inconnue.jibiat.com` : « Aucune entreprise à cette adresse ».

---

## Ce qui change pour les utilisateurs de l'entreprise actuelle

- **Nouvelle adresse** : l'ancienne ne fonctionne plus. Prévenez l'équipe, les livreurs et les
  marchands (un message WhatsApp avec le nouveau lien suffit).
- **Applications installées** (livreurs, marchands) : à réinstaller depuis la nouvelle adresse,
  puis **réactiver les notifications** (une autorisation de notifications est liée à l'adresse).
- **Liens de suivi déjà envoyés** aux destinataires : ils pointent vers l'ancienne adresse et ne
  s'ouvrent plus. Les nouveaux messages utilisent la nouvelle adresse.
- **WhatsApp (Meta)** : dans le tableau de bord Meta, l'URL du webhook devient
  `https://admin.jibiat.com/api/webhooks/whatsapp` (elle répond à toutes les adresses de
  l'application, mais pas à `jibiat.com` quand c'est un site de présentation à part ; le jeton de
  vérification ne change pas).
- **API publique** : les marchands qui l'utilisent changent seulement l'adresse de base
  (`https://express.jibiat.com/api/public/v1`) ; leurs clés restent valables.

---

## Ajouter une entreprise

Console `https://admin.jibiat.com` > **Nouvelle entreprise** : nom, adresse (ex. `eclair`),
premier administrateur. L'adresse `https://eclair.jibiat.com` fonctionne immédiatement :
l'enregistrement DNS `*` et le certificat `*.jibiat.com` la couvrent déjà.

---

## Limites par entreprise

Pour qu'une entreprise très active ne ralentisse pas les autres, l'espace connecté accepte par
minute (réglables dans le `.env`, puis `php artisan optimize`) :

| Réglage | Défaut | Portée |
|---|---|---|
| `PLATFORM_USER_RATE_LIMIT` | 300 | par compte |
| `PLATFORM_COMPANY_RATE_LIMIT` | 3000 | pour toute une entreprise |

Au-delà, l'application répond « Trop de tentatives. Réessayez dans quelques instants. » pendant
quelques secondes. La console du super administrateur n'a pas de limite d'entreprise.

---

## Dépannage

| Symptôme | Cause probable |
|---|---|
| Erreur Cloudflare **526** | Certificat absent ou incorrect dans ISPConfig (étape 3), ou mode SSL autre que Full (strict) |
| Erreur Cloudflare **521** / **522** | Serveur injoignable : Apache arrêté, pare-feu, mauvaise adresse IP dans le DNS |
| « Aucune entreprise à cette adresse » | Adresse mal saisie, ou entreprise renommée : `php artisan platform:status` |
| « Service suspendu » | Entreprise suspendue dans la console |
| Pas de temps réel (carte figée) | WebSockets désactivés chez Cloudflare, ou `livraison-reverb` arrêté : `supervisorctl status` |
| « Trop de tentatives » pour tout le monde | `TRUSTED_PROXIES=cloudflare` absent du `.env` : tous les visiteurs semblent venir de Cloudflare |
| Mise à jour de l'application non visible sur les téléphones | Fermer et rouvrir l'application ; le fichier `sw.js` n'est jamais mis en cache |

Revenir à une seule entreprise : videz `PLATFORM_DOMAIN` dans le `.env`, retirez la directive
d'origine dans ISPConfig, puis `bash <dossier>/deploy/update.sh`.

---

## Serveur sans ISPConfig (Nginx, `deploy/install.sh`)

Mêmes étapes 1 et 2, puis, avec les deux fichiers du certificat copiés sur le serveur :

```bash
PLATFORM_DOMAIN=jibiat.com ORIGIN_CERT=/root/jibiat-cert.pem ORIGIN_KEY=/root/jibiat-key.pem \
    bash /var/www/livraison/deploy/platform.sh
```

Le script réécrit le site Nginx pour `jibiat.com` et `*.jibiat.com` (l'ancienne configuration
est gardée dans `/etc/nginx/sites-available/livraison.avant-plateforme`).

Sans le proxy Cloudflare (nuage gris), utilisez Let's Encrypt par validation DNS : créez un jeton
Cloudflare (**My Profile** > **API Tokens** > modèle **Edit zone DNS**, zone `jibiat.com`), puis :

```bash
PLATFORM_DOMAIN=jibiat.com CLOUDFLARE=0 CF_API_TOKEN=… EMAIL=moi@jibiat.com \
    bash /var/www/livraison/deploy/platform.sh
```

Le certificat se renouvelle ensuite tout seul.
