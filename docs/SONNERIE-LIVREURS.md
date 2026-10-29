# Sonnerie des missions (application Android du livreur)

Pour un nouveau ramassage ou une nouvelle livraison, le téléphone du livreur sonne comme un appel : sonnerie du
téléphone en boucle, vibration, écran « appel entrant » par-dessus l'écran verrouillé, avec Accepter et Refuser.

- Les missions confiées ensemble (une mission ramassage de plusieurs colis) sonnent **une seule fois**.
- Sans réponse, **deuxième sonnerie deux minutes après** (écran orange « 2e appel »), puis l'alerte « injoignable »
  de l'agence prend le relais.
- Accepter, refuser ou « J'y vais » arrête la sonnerie sur tous ses téléphones et annule le rappel.
- Le reste (messages, relais, modifications) reste une notification normale.
- iPhone et navigateur : pas de sonnerie d'appel (Apple l'interdit hors appels), notification normale.

## Mise en place (une fois)

1. **Projet Firebase** (gratuit) sur console.firebase.google.com : ajouter une application Android de nom de
   paquet `com.jibiat.livreur`, télécharger son `google-services.json`.
2. **Dépôt GitHub** › Settings › Secrets and variables › Actions : secret `GOOGLE_SERVICES_JSON` = le contenu du
   fichier. Le workflow « Applications mobiles » le pose à la compilation de l'APK livreur (jamais dans le dépôt).
3. **Serveur** : dans Firebase › Paramètres du projet › Comptes de service, « Générer une nouvelle clé privée ».
   Déposer le fichier JSON sur le serveur hors du dossier public : avec ISPConfig, dans le dossier `private` du
   site (par exemple `/var/www/clients/client0/web2/private/firebase.json`, propriétaire root, groupe celui du
   site, droits 640), puis dans le `.env` : `FCM_CREDENTIALS=<ce chemin>`, et
   `sudo -u $(stat -c %U .) env HOME=/tmp php artisan optimize` puis `... php artisan horizon:terminate` depuis le
   dossier de l'application. Ne jamais envoyer ce fichier par message.
4. Installer la nouvelle APK sur les téléphones depuis la page de téléchargement (ci-dessous). À la première
   ouverture, l'écran « Ne ratez aucune mission » demande les autorisations (notifications, écran verrouillé, « Ne pas déranger ») ; il reste dans
   Profil › Sonnerie des missions, avec « Tester la sonnerie ».

Les applications au nom d'une entreprise (workflow branded-app.yml, autre nom de paquet) ne sonnent pas tant que
leur paquet n'est pas ajouté au projet Firebase.

## Page de téléchargement de l'APK

Les livreurs installent l'application depuis **`<adresse du site>/app/livreur`** (bouton « Télécharger
l'application », étapes d'installation, autorisations). Dans l'espace livreur ouvert dans le navigateur d'un
téléphone Android, un bandeau propose l'installation ; dans l'application, un bandeau signale une nouvelle version.

L'APK est fabriquée et signée par le workflow « Application livreur (téléchargement) »
(`.github/workflows/courier-app-release.yml`) à chaque fusion sur `main` (préproduction) ou `production`, puis
déposée sur le serveur (`POST /api/public/apps/livreur`, protégé par un jeton). Toujours la même clé de signature,
pour que chaque version s'installe par-dessus la précédente.

Mise en place (une fois) :

1. **Clé de signature** (sur un ordinateur, à garder précieusement : sans elle, plus de mise à jour possible) :
   `keytool -genkeypair -v -storetype PKCS12 -keystore livreur.p12 -alias livreur -keyalg RSA -keysize 2048 -validity 10000`
   puis `base64 -w0 livreur.p12` pour obtenir le texte à coller dans GitHub.
2. **GitHub** › Settings › Secrets and variables › Actions :
   - secrets : `LIVREUR_KEYSTORE` (le texte base64), `LIVREUR_KEYSTORE_PASSWORD`, `LIVREUR_KEY_ALIAS` (`livreur`),
     `APP_UPLOAD_TOKEN` (un long mot de passe au hasard, par exemple `openssl rand -hex 32`),
     `GOOGLE_SERVICES_JSON` (voir plus haut) ;
   - variables : `PRODUCTION_URL` (par défaut `https://jibiat.com`) et `PREPROD_URL`.
3. **Serveurs** : dans le `.env`, `APP_UPLOAD_TOKEN=` le même jeton, puis `php artisan optimize`. PHP doit accepter
   les gros envois : `upload_max_filesize` et `post_max_size` à 64M au moins (ISPConfig › site › Options › PHP).
4. Relancer le workflow (Actions › Application livreur › Run workflow) : la page affiche la version.

Les téléphones qui ont l'ancienne APK de test (signée autrement) doivent la désinstaller une fois avant
d'installer celle de la page. Sans jeton ou sans clé, le workflow ne publie rien et le signale.
