# Page Utilisateurs (Équipe › Utilisateurs)

- **Compteurs** : équipe (par rôle), livreurs (actifs / suspendus), comptes e-commerçants, « À vérifier ».
- **Onglets** : Équipe, Livreurs, E-commerçants, Suspendus, À vérifier (équipe et livreurs actifs jamais connectés
  ou sans connexion depuis 30 jours). Recherche par nom, téléphone ou e-mail, filtre par rôle, 50 comptes par page.
- **Menu ⋯ par compte** (selon les droits renvoyés par le serveur dans `abilities`) : Modifier, fiche du livreur ou
  de la boutique, Envoyer un lien de connexion, Déconnecter de tous ses appareils, Historique, Suspendre / Réactiver.
- **Autre administrateur** : seul son nom se corrige ; téléphone, mot de passe, rôle et statut restent au propriétaire
  de la plateforme (ou à lui-même). Le formulaire n'envoie que les champs modifiés.
- **Historique** (`user_account_events`) : création, changement de rôle, suspension, réactivation, nom, accès
  (téléphone, e-mail, mot de passe), lien de connexion préparé, déconnexion de tous les appareils, avec l'auteur.

API : `GET users?scope=team|couriers|merchants|suspended|inactive`, `GET users/summary`,
`POST users/{user}/logout`, `GET users/{user}/history`.
