# Envoyer les e-mails de Jibiat par Gmail, sans mot de passe d'application

Jibiat envoie ses e-mails (alertes de paiement Wave, codes) depuis votre compte Gmail grâce à la **connexion
Google** : vous autorisez Jibiat à **envoyer** des e-mails, rien d'autre (Jibiat ne peut pas lire votre boîte).
Aucun mot de passe n'est enregistré ; vous pouvez retirer l'autorisation à tout moment sur
myaccount.google.com › Sécurité › Applications tierces.

Il faut d'abord créer, une seule fois, un petit « projet Google » qui représente Jibiat (environ 15 minutes).

## 1. Créer le projet Google (une seule fois)

Sur **console.cloud.google.com**, connecté avec votre compte Google :

1. En haut, **Sélectionner un projet › Nouveau projet** : nom « Jibiat », puis **Créer**.
2. Menu **API et services › Bibliothèque** : cherchez **Gmail API**, puis **Activer**.
3. Menu **Google Auth Platform** (ou **Écran de consentement OAuth**) › **Commencer** :
   - nom de l'application : Jibiat ; e-mail d'assistance : votre adresse ;
   - audience : **Externe** ; coordonnées : votre adresse ; acceptez les conditions, puis **Créer**.
4. **Accès aux données › Ajouter ou supprimer des champs d'application** : cochez
   `.../auth/gmail.send` (« Envoyer des e-mails en votre nom »), plus `openid` et `.../auth/userinfo.email`,
   puis **Mettre à jour** et **Enregistrer**.
5. **Audience › Publier l'application** (statut « En production »). Important : en mode « Test », Google retire
   l'autorisation au bout de 7 jours. Aucune vérification par Google n'est nécessaire pour votre propre compte.
6. **Clients › Créer un client** : type **Application Web**, nom « Jibiat ». Dans **URI de redirection
   autorisés**, ajoutez :
   - `https://admin.jibiat.com/gmail/callback`
   - (préproduction) `https://admin.preprod.jibiat.com/gmail/callback`
   puis **Créer**. Google affiche l'**ID client** et le **code secret du client**.

## 2. Saisir l'application Google dans la console

Le plus simple : **reprendre le client Google de Jibiat Marchand** (même projet Google). Dans ce client, ajoutez
seulement les adresses de retour de Jibiat ci-dessus aux « URI de redirection autorisés ».

**Console › Paiements Wave › Envoi des alertes › Application Google** : collez l'**ID client** et le **code
secret du client**, puis **Enregistrer**. Le code secret est chiffré et n'est jamais réaffiché (laisser le champ
vide le garde). Ne le collez jamais dans une conversation, un e-mail ou une capture.

En secours, les mêmes valeurs peuvent être posées dans le `.env` du serveur (`GOOGLE_MAIL_CLIENT_ID` et
`GOOGLE_MAIL_CLIENT_SECRET`, ou les noms de Jibiat Marchand `GOOGLE_CLIENT_ID` et `GOOGLE_CLIENT_SECRET`) ; ce qui
est saisi dans la console a priorité.

## 3. Relier Gmail dans la console

**Console › Paiements Wave › Envoi des alertes › Connecter Gmail** : choisissez votre compte, puis autorisez.
Google affiche « Google n'a pas validé cette application » : c'est normal, c'est votre propre application ;
touchez **Paramètres avancés › Accéder à Jibiat**, puis **Continuer**. Enfin, **Envoyer une alerte d'essai**.

## Bon à savoir

- Gmail accepte environ 500 e-mails par jour depuis un compte personnel : largement assez pour les alertes.
- Si l'autorisation est retirée (dans le compte Google, ou après un changement de mot de passe Google), la console
  et la page **Santé** l'indiquent : il suffit de toucher à nouveau **Connecter Gmail**.
- Le réglage SMTP (« Autre serveur d'e-mail ») reste possible, mais n'est plus nécessaire.
