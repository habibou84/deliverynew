# Fichiers sur Cloudflare R2

Les fichiers de l'application quittent le disque du serveur pour un espace privé Cloudflare R2. C'est la condition
pour ajouter un second serveur web : chacun voit les mêmes fichiers.

Sont concernés : photos de preuve et pièces jointes des courses, signatures et photos des retours, logos et icônes
envoyés, photos des produits, documents et photos des véhicules, QR codes de paiement des e-commerçants,
applications Android (livreur, applications à la marque).

Restent sur le serveur : les versions réduites des images et les icônes calculées (`variants/`, recalculées au
besoin sur chaque serveur), les médias du WhatsApp relié par QR code (`storage/app/whatsapp-bridge`), le QR Wave de
la plateforme (disque `public`), les journaux.

Tous ces fichiers restent servis par l'application, après contrôle des droits : l'espace R2 n'est jamais public.

Cloudflare en mode proxy est déjà en place pour Jibiat (docs/PLATEFORME.md, `TRUSTED_PROXIES=cloudflare`).

## 1. L'espace (Cloudflare › R2)

1. **Create bucket** : `jibiat-fichiers` (privé : ne rien ouvrir au public). La préproduction a le sien
   (`jibiat-preprod-fichiers`) : ne jamais partager celui de la production.
2. **R2 › Manage API Tokens › Create API Token** : permission **Object Read & Write**, limitée à cet espace. Noter
   l'**Access Key ID**, le **Secret Access Key** et l'adresse **S3** du compte
   (`https://<identifiant>.r2.cloudflarestorage.com`).

Cet espace est différent de celui des sauvegardes (`BACKUP_S3_*`).

## 2. Réglages (`.env`), sans encore changer de disque

```
R2_ACCESS_KEY_ID=…
R2_SECRET_ACCESS_KEY=…
R2_ENDPOINT=https://<identifiant>.r2.cloudflarestorage.com
R2_PRIVATE_BUCKET=jibiat-fichiers
```

Puis `php artisan optimize` (avec l'utilisateur de l'installation).

## 3. Copie des fichiers existants

```bash
php artisan files:copy local r2-private --except=variants --except=tmp --dry-run   # compte, sans rien copier
php artisan files:copy local r2-private --except=variants --except=tmp
```

La commande vérifie d'abord qu'elle peut écrire sur R2, garde les mêmes chemins et peut être relancée : ce qui est
déjà copié (même taille) est passé. Quand tout est copié sans erreur, les pièces jointes des courses (qui retiennent
leur disque) sont rattachées à R2.

## 4. Bascule

Dans le `.env` : `PRIVATE_DISK=r2-private`. Puis relancer la même commande `files:copy` (fichiers ajoutés pendant la
copie), `php artisan optimize` et `php artisan horizon:terminate` (Horizon redémarre avec le nouveau réglage).

Vérifications : le logo de l'entreprise s'affiche ; une photo de preuve s'ouvre dans la fiche d'une course ; une
nouvelle photo de livraison envoyée par un livreur s'ouvre ; la page `/app/livreur` télécharge l'application.

Retour en arrière : `PRIVATE_DISK=local`, puis `php artisan files:copy r2-private local` (fichiers ajoutés depuis la
bascule, pièces jointes rattachées de nouveau au disque local). Les fichiers du serveur n'ont pas été effacés. Une
fois la bascule confirmée depuis quelques semaines, `storage/app/private` peut être vidé, sauf `variants`.

## 5. Sauvegardes

La sauvegarde de la nuit copie `storage/app` : après la bascule, elle ne contient plus ces fichiers. R2 les garde sur
plusieurs centres de données, mais ne protège pas d'un effacement par erreur. À prévoir : une copie régulière de
l'espace vers celui des sauvegardes (rclone, déjà installé par `backup.sh`).

Même fonctionnement dans Jibiat Marchand (habibou84/ecommerce, docs/FICHIERS-R2.md).
