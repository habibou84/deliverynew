# Applications Android et iOS

Deux applications, publiées au nom de Jibiat et communes à toutes les entreprises de livraison :

| Application | Identifiant | Comptes acceptés |
|---|---|---|
| **Jibiat Livreur** | `com.jibiat.livreur` | livreurs |
| **Jibiat E-commerçant** | `com.jibiat.ecommercant` | e-commerçants (propriétaire et employés) |

Elles reprennent les écrans du site (`resources/js`) avec [Capacitor](https://capacitorjs.com) : une évolution
des écrans profite au site et aux applications.

## Fonctionnement

1. **Premier lancement : code de l'entreprise.** L'utilisateur saisit le code de son entreprise de livraison
   (le début de son adresse, ex. `rapide-express` pour `rapide-express.jibiat.com`). L'application le vérifie
   auprès de la plateforme (`GET https://jibiat.com/api/public/apps/companies/{code}`), qui renvoie le nom, le
   logo et l'adresse du site de l'entreprise (domaine personnalisé s'il est actif).
2. **Ensuite, tout passe par le site de l'entreprise** : connexion, API (`/api/v1`), temps réel, liens de suivi.
   Le code est gardé sur le téléphone ; « Changer d'entreprise » sur la page de connexion permet d'en choisir une autre.
3. **Chaque application n'ouvre que son espace** : un e-commerçant qui se connecte à l'application livreur
   (ou l'inverse) est renvoyé vers la bonne application ; les pages de l'administration ne s'y ouvrent pas.

Côté serveur : origines des applications autorisées par CORS (`https://localhost` pour Android,
`capacitor://localhost` pour iOS), dans `config/cors.php`.

## Fichiers

- `resources/js/native/` : application en cours (fixée à la compilation), entreprise choisie, adresses absolues.
- `resources/js/views/auth/NativeCompany.vue` : écran « Code de votre entreprise ».
- `mobile/index.html`, `mobile/main.js` : entrée des applications (bouton retour d'Android).
- `vite.mobile.config.js` : compilation des écrans dans `mobile/<application>/www`.
- `mobile/livreur/`, `mobile/ecommercant/` : projets Capacitor, Android (`android/`) et iOS (`ios/`).
- `mobile/assets.py` : icônes et écrans de démarrage, depuis `public/app-icons/*-maskable-512.png`.

## Obtenir une application Android de test (APK)

À chaque modification fusionnée (ou à la demande : onglet **Actions** → « Applications mobiles » →
**Run workflow**), GitHub compile les deux APK. Dans l'exécution terminée, section **Artifacts** :
`jibiat-livreur-apk` et `jibiat-ecommercant-apk` (fichiers zip contenant `app-debug.apk`, gardés 30 jours).

Installation sur un téléphone Android : copier l'APK sur le téléphone, l'ouvrir, autoriser l'installation
depuis cette source si Android le demande. Ces APK de test ne passent pas par le Play Store.

## Compiler soi-même

```bash
npm ci
npm run mobile:livreur        # écrans + synchronisation des projets Android et iOS
cd mobile/livreur/android && ./gradlew assembleDebug   # APK (Android Studio ou SDK Android requis)
```

`VITE_PLATFORM_URL` (par défaut `https://jibiat.com`) désigne la plateforme qui retrouve les entreprises.

Après avoir changé une icône du site : `python3 mobile/assets.py`.

## iOS

Les projets iOS sont prêts (`mobile/*/ios`). Leur compilation demande un Mac : elle se fera par un service
en ligne (Codemagic ou GitHub Actions macOS) avec le compte Apple Developer de Jibiat SARL.

## Applications installées depuis le navigateur (PWA)

Les deux applications s'installent aussi depuis Chrome, chacune limitée à son espace :

| Application | Lien d'installation | Portée (`scope`) | Connexion |
|---|---|---|---|
| Livreur | `/livreur` | `/livreur` | `/livreur/connexion` |
| E-commerçant | `/marchand` (ou `/`) | `/marchand` | `/marchand/connexion` (même écran que `/`) |

Les portées ne doivent jamais se recouvrir : une application dont la portée est `/` couvre aussi
`/livreur`, et Chrome affiche alors « application déjà installée » sur le lien de l'autre application.
Une application installée avant ce réglage (portée `/`) se met à jour d'elle-même sous quelques jours ;
pour aller plus vite, la désinstaller puis la réinstaller.

## Applications à leur nom (Android)

Une entreprise peut avoir ses deux applications (e-commerçant et livreur) à son nom, son icône et sa couleur,
publiées sur son propre compte Google Play. Tout se fait dans la console : **Applications à leur nom**.

1. **Compte Google Play de l'entreprise.** L'entreprise ouvre son compte développeur Google Play (25 $ une fois)
   et invite Jibiat comme administrateur (Utilisateurs et autorisations). Aucun mot de passe n'est échangé.
   Dans la console, cocher « Compte Google Play relié ».
2. **Ajouter l'entreprise.** La console propose les identifiants (`ci.<entreprise>.ecommercant`,
   `ci.<entreprise>.livreur`) et les noms, et crée la clé de signature (PKCS12, alias `upload`, valable 25 ans).
   La clé est gardée chiffrée par Jibiat, elle ne sort jamais de la plateforme sauf vers le workflow de
   fabrication. Les identifiants ne changent plus après la première version fabriquée.
3. **Fabriquer.** Le bouton lance le workflow `.github/workflows/branded-app.yml` (GitHub Actions). Il lit la fiche
   de la version (`/api/public/apps/builds/{id}/manifest`) avec un jeton propre à la version (3 heures, effacé
   une fois la version prête ou en échec), applique le nom, l'identifiant, la couleur et l'icône
   (`mobile/brand.mjs`, `mobile/assets.py`), intègre l'entreprise (`VITE_BRANDED_COMPANY` : l'écran
   « code de l'entreprise » disparaît), signe et renvoie les fichiers à la console.
4. **Fichiers.** AAB à envoyer au Play Store, APK pour essayer sur un téléphone. Après l'envoi au Play Store,
   « Marquer publiée ».

**Jeton GitHub** : à créer sur GitHub (« fine-grained », limité au dépôt, droit **Actions : lecture et écriture**
seulement, avec une date d'expiration), puis à coller dans **console › Applications à leur nom › Jeton GitHub**
(Propriétaire). Il y est gardé chiffré avec la clé du serveur (`APP_KEY`), ne s'affiche plus jamais (seulement
ses 4 derniers caractères) et n'est pas écrit au journal. « Vérifier » interroge GitHub et note sa date
d'expiration ; la santé de la plateforme prévient 30 jours avant. On peut aussi le mettre dans le `.env`
(`GITHUB_APPS_TOKEN`), qui est alors prioritaire et ne se modifie pas depuis la console.

Réglages du serveur (`.env`, facultatifs) :

| Variable | Rôle |
|---|---|
| `GITHUB_APPS_TOKEN` | Jeton GitHub (sinon celui saisi dans la console) |
| `GITHUB_APPS_REPO` | Dépôt du workflow (par défaut `habibou84/deliverynew`) |
| `GITHUB_APPS_REF` | Branche fabriquée (par défaut `production`) |
| `GITHUB_APPS_VERSION` | Début du numéro de version affiché (par défaut `1.0`, donne `1.0.<n>`) |

Le jeton de la version apparaît dans les paramètres de l'exécution GitHub (visibles des personnes ayant accès
au dépôt) : il ne sert qu'à cette version et expire vite.

### Suppression du compte

Exigée par Google Play. Dans l'application (onglet **Moi**), un e-commerçant ou un livreur peut supprimer son
compte : il est fermé tout de suite (déconnecté partout), les administrateurs de l'entreprise sont prévenus et
peuvent l'annuler depuis **Utilisateurs** pendant 30 jours. Ensuite la tâche `accounts:erase-deleted`
(chaque nuit à 3 h 40) efface le nom, le téléphone, l'adresse e-mail et le mot de passe ; les courses et la
comptabilité restent. Page publique à donner à Google : `/suppression-du-compte` (liée depuis `/confidentialite`).

## Étapes suivantes

- Notifications natives (Firebase pour Android, Apple pour iOS) branchées sur les notifications existantes.
- Position du livreur en arrière-plan, appareil photo, ouverture des liens de suivi dans l'application.
- Envoi automatique au Play Store, versions iPhone à leur nom (App Store).
