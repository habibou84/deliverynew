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
   Déposer le fichier JSON sur le serveur hors du dossier public (par exemple `/etc/jibiat/firebase.json`,
   lisible par l'utilisateur de PHP seulement), puis dans le `.env` : `FCM_CREDENTIALS=/etc/jibiat/firebase.json`,
   et `php artisan config:clear`. Ne jamais envoyer ce fichier par message.
4. Installer la nouvelle APK sur les téléphones. À la première ouverture, l'écran « Ne ratez aucune mission »
   demande les autorisations (notifications, écran verrouillé, « Ne pas déranger ») ; il reste dans
   Profil › Sonnerie des missions, avec « Tester la sonnerie ».

Les applications au nom d'une entreprise (workflow branded-app.yml, autre nom de paquet) ne sonnent pas tant que
leur paquet n'est pas ajouté au projet Firebase.
