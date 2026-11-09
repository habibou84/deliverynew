# Diagnostic de sécurité de Jibiat (octobre 2026)

Audit du code : comptes et accès, isolement entre entreprises, entrées externes (webhooks, imports, fichiers), console
du super administrateur, dépendances. Chaque point a été vérifié dans le code avant d'être corrigé, et chaque
correction a son test. À rejouer chaque trimestre (voir `docs/MAINTENANCE.md`).

## Corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Critique | En production, avec les SMS en mode « journal », l'API renvoyait le code de vérification SMS (`demo_code`) : on pouvait ouvrir n'importe quel compte avec son seul numéro. | Le mode démonstration est coupé en production (`PhoneVerifier::demoMode`). |
| Haute | Webhooks sortants vers le réseau interne (SSRF) : adresses IPv4 cachées dans une IPv6 (`::ffff:127.0.0.1`…), plages CGNAT et réservées, double résolution DNS. | Toutes ces plages sont refusées, les adresses IPv6 du nom sont contrôlées, et l'envoi se fait vers l'adresse vérifiée (pas de seconde résolution). |
| Haute | Un livreur pouvait déclarer des frais (débités au marchand) sur une course qu'il avait refusée ou qui lui avait été retirée, et voir ces courses. | Frais et photos : seulement pour la course en main ou terminée depuis moins de 24 h. Les courses refusées ou retirées ne sont plus visibles. La synchronisation hors connexion ne touche plus que ses courses. |
| Haute | Aucun journal des actions du super administrateur. | Journal des actions (Console › Journal), sans aucun secret ; les sessions d'assistance sont attribuées à leur auteur. |
| Moyenne | Le simulateur WhatsApp des réglages acceptait un numéro choisi librement et validait les codes de connexion de ce numéro. | Le simulateur ne traite plus ni les codes de vérification ni les avis. |
| Moyenne | Un administrateur pouvait changer le mot de passe ou le téléphone d'un autre administrateur, ou s'envoyer son lien d'invitation. | Refusé ; chacun garde la main sur ses propres identifiants. |
| Moyenne | Clé de bascule de l'ancienne application valable sans fin. | La bascule s'arrête 60 jours après sa date ; limite d'appels réduite. |
| Moyenne | Code de livraison à 4 chiffres essayable sans limite. | 5 essais par course et par demi-heure. |
| Moyenne | Aucun en-tête de sécurité HTTP. | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, et HSTS en production. |
| Moyenne | Session de la console valable 30 jours, mot de passe de 8 caractères, réglages Wave modifiables d'un clic. | Session de 12 h, mot de passe de 12 caractères, mot de passe redemandé pour les réglages Wave, code du validateur de 6 chiffres, essais limités par paiement, lien de validation de 48 h. |
| Moyenne | Session d'assistance sans motif et impossible à fermer. | Motif obligatoire, bouton « Fermer maintenant ». |
| Basse | Domaine personnalisé servi avant d'être vérifié. | Avant vérification, seule l'adresse de contrôle répond. |
| Basse | Codes (vérification, livraison) écrits en clair dans les journaux. | Masqués en production. |
| Basse | Listes paginées sans plafond (`per_page`). | Plafond : 500 dans l'application, 100 dans l'API publique. |
| Basse | Export CSV : un nom commençant par `=` était lu comme une formule par Excel. | Neutralisé. |
| Basse | Connexion par WhatsApp d'un compte de la console : erreur 500. | Refusée proprement (mot de passe seulement). |
| Haute | Console ouverte avec le seul mot de passe. | Code de connexion à 6 chiffres envoyé par e-mail (WhatsApp en secours), 10 min, 5 essais ; appareil retenu 30 jours au choix, oublié au changement de mot de passe. La page Santé signale un compte qui ne peut pas recevoir le code. |
| Basse | Dépendances JavaScript avec des failles connues (vue, postcss). | Mises à jour (`npm audit` : 0 faille). `composer audit` : 0 faille. |

## Audit n° 2 (31 octobre 2026), lot 1 corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Critique | Un livreur pouvait déclarer un paiement Wave ou Mobile Money « reçu par l'entreprise » sans contrôle : l'argent n'était plus à sa charge. | Le paiement reste à sa charge tant que la caisse ne l'a pas confirmé (bouton « Reçu ? Confirmer » au versement). Référence obligatoire et jamais deux fois la même. La clôture compte ces paiements le jour de leur confirmation. |
| Haute | Montant encaissé libre à la livraison. | Jamais plus que le montant à encaisser ; moins seulement avec une explication, et le dispatch est prévenu. |
| Haute | Frais déclarés par un livreur (et frais de gare) appliqués tout de suite, sans plafond. | « À valider » : aucun effet tant que la caisse ou le dispatch ne les a pas validés ; 200 000 F au plus par déclaration du livreur. |
| Haute | Vol de la clé API d'un e-commerçant par une adresse de retour piégée (antislash) dans la connexion en un clic. | Adresses avec antislash, espace ou caractère de contrôle refusées ; hôte limité aux lettres, chiffres, points et tirets. |
| Haute | Inscription : numéro « vérifié » par un code envoyé à un e-mail choisi par l'inscrit. | Pas de vérification par e-mail à l'inscription. |
| Haute | Codes à 6 chiffres essayables en masse (compteur remis à zéro, requêtes simultanées). | Compteur d'essais atomique, non remis à zéro au renvoi ; 10 codes faux par numéro et par heure au plus ; idem pour la console. |
| Haute | Hameçonnage du message WhatsApp de connexion. | Message prérempli explicite (« Je me connecte à mon compte … ») ; connexion et mot de passe oublié demandent une confirmation « OUI » avec un avertissement. |
| Haute | Lien « Ce n'était pas moi » de la console : nouveau mot de passe avec la seule boîte mail. | Le lien ne fait plus que verrouiller le compte ; réouverture par un autre propriétaire. |
| Haute | Un administrateur pouvait rétrograder un autre administrateur puis changer ses identifiants. | Rôle et statut d'un administrateur modifiables par le super administrateur seulement. |

## Audit n° 2, lot 2 corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Moyenne | Hors connexion : livraison avec un code client faux sans limite, action antidatée jusqu'à 72 h. | 3 livraisons « code à vérifier » par livreur et par jour au plus ; rien n'est reversé à l'e-commerçant pour elles avant la vérification du dispatch ; l'heure déclarée ne remonte jamais avant la dernière étape de la course. |
| Moyenne | API publique : modification du montant ou de la zone sans la validation demandée par le réglage « Modifications des e-commerçants ». | Même validation que dans l'application. |
| Moyenne | Versement ou avance annulable après la clôture de caisse du jour. | Annulation refusée après la clôture : faire un mouvement contraire daté du jour. |
| Moyenne | Un caissier pouvait tout faire seul (ajustement sans plafond, moyen de paiement saisi et vérifié par lui). | Ajustement plafonné à 5 000 000 F, et au-delà de 500 000 F par un administrateur ; moyen de paiement saisi par l'équipe « à vérifier » (sauf administrateur), jamais vérifié par celui qui l'a saisi. |
| Moyenne | Console : si l'envoi du code échouait, la session s'ouvrait avec le seul mot de passe. | Connexion bloquée ; procédure de secours sur le serveur : `php artisan console:code {identifiant}`. |
| Moyenne | Limite des essais de mot de passe contournable en écrivant le numéro autrement. | Limite sur le numéro normalisé, et par compte toutes adresses IP confondues (10 par minute, 60 par heure). |
| Moyenne | Session d'assistance : création possible de clés, invitations, comptes ou mots de passe ; jetons gardés après suspension du membre. | Ces actions sont refusées pendant l'assistance ; suspendre un membre de l'équipe Jibiat coupe ses sessions d'assistance. |
| Moyenne | Adresse de l'ancienne application contrôlée une seule fois (redirection, changement DNS). | Contrôle à chaque appel, adresse IP fixée, sans redirection (comme les webhooks). |
| Moyenne | Notifications push : adresse d'envoi libre. | Seulement les services push des navigateurs (Google, Mozilla, Apple, Microsoft), sans redirection. |
| Moyenne | WhatsApp : identifiant du numéro d'une autre entreprise réservable. | Un numéro vérifié ne peut pas être repris ; un compte non vérifié auprès de Meta ne reçoit aucun message, et son identifiant est libéré pour le vrai titulaire. |
| Moyenne | `symfony/mime` avec une faille connue. | Mise à jour (`composer audit` : 0 faille). |

## Audit n° 2, lot 3 corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Basse | Connexion WhatsApp et mot de passe oublié d'un numéro bloqués par 5 demandes. | 5 demandes par heure par numéro et par adresse IP ; 20 toutes adresses confondues. |
| Basse | On pouvait savoir si un numéro avait un compte, et de quel type. | Connexion par WhatsApp : même réponse dans tous les cas ; la raison (aucun compte, autre espace, compte suspendu) n'est donnée qu'après l'envoi du message depuis le numéro. |
| Basse | Accepter une invitation laissait ouvertes les autres sessions du compte. | Les autres sessions et appareils retenus sont fermés. |
| Basse | Bascule de l'ancienne application repoussable sans fin. | Une bascule commencée ne se repousse plus (annulation seule, pour revenir en arrière) ; une date passée commence tout de suite. |
| Basse | Le robot WhatsApp donnait le numéro lié à un code en attente. | Jamais de numéro dans la réponse ; 10 codes non reconnus par heure et par numéro. |
| Basse | Fichier Excel piégé pouvant bloquer l'import. | Taille décompressée (50 Mo), nombre de fichiers, lignes (2 000) et colonnes (100) limités avant et pendant la lecture. |
| Basse | Page boutique noyable sous de fausses commandes en changeant d'adresse IP. | Limite par boutique, toutes adresses IP confondues (20 par minute, 200 par heure). |
| Basse | Paiement à l'unité d'un e-commerçant sans contrôle du moyen de paiement. | Refusé si le moyen de paiement attend d'être vérifié, comme le paiement groupé. |
| Basse | Deux contrôles d'argent hors verrou (annulation de versement, paie gardée sur l'encaissé). | Contrôles faits sous verrou en base. |
| Basse | Pièces jointes internes lisibles par l'e-commerçant ; historique interne visible du livreur ; photos de produits par numéro. | Pièce jointe d'une étape interne refusée ; le livreur voit l'historique partagé et ses propres notes ; photo d'un produit non publié seulement avec le lien de l'application. |
| Basse | Configuration : cookie sécurisé non forcé, jetons de 30 jours pour tous, journal « debug » par défaut, temps réel ouvert à toutes les origines. | Cookie sécurisé par défaut en production ; session de 7 jours pour l'équipe d'une entreprise (30 jours pour livreurs et e-commerçants, 12 h pour la console) ; journal « info » par défaut en production ; origines du temps réel réglables (`REVERB_ALLOWED_ORIGINS`), laissées ouvertes par défaut car les canaux privés exigent le jeton et les applications et domaines personnalisés s'y connectent. |

## Audit n° 3 (9 novembre 2026) : code ajouté depuis le 1er novembre, serveur et CI

Corrigé :

| Gravité | Problème | Correction |
|---|---|---|
| Haute | Dépendances Symfony avec sept failles connues (contournement de règles d'accès, proxys de confiance, URL vers un hôte choisi par le client). | Mises à jour en 7.4.23 (`composer audit` : 0 faille). |
| Moyenne | Packs de fiches IA offerts par l'agence achetables sans limite (jusqu'à 300 000 F par heure facturés à l'agence). | Plafond mensuel par e-commerçant (3 par défaut, réglable dans la console), compté sous verrou. |
| Moyenne | Crédit live : deux recharges PayDunya simultanées pouvaient s'écraser ; cadeau de la console et versement du crédit d'agence sans verrou. | Écritures atomiques ; cadeau et versement sous verrou, solde relu dedans. |
| Moyenne | Analyse des messages WhatsApp par l'IA sans limite, même après la pause au plafond du mois. | Pause respectée ; 30 appels par heure et par numéro, 300 par entreprise ; au-delà, analyse par règles. |
| Moyenne | Image piégée (20 000 × 20 000 pixels) : jusqu'à 512 Mo de mémoire à chaque affichage public. | Dimensions lues avant le décodage ; au-delà de 40 mégapixels, l'original est servi. |
| Moyenne | Lien public du live : fausses commandes en masse (en changeant d'adresse IP) qui font payer un palier plus cher. | 10 commandes par numéro et par live ; 2 000 par heure et par live ; auteur des courses toujours un compte actif. |
| Moyenne | Lien de report renvoyé à l'équipe : un dispatcher annulait des courses au nom de l'e-commerçant, hors quota. | Le lien n'est plus renvoyé à l'équipe ; un ancien lien n'agit que sur les courses de sa demande. |
| Moyenne | Bandeau de bascule sans date fixée : la clé de l'ancienne application ouvrait des sessions sans limite de durée. | Sans date de bascule, le bandeau n'ouvre rien. |
| Moyenne | Installation (`ispconfig.sh`, `install.sh`) avec les comptes de démonstration par défaut (mot de passe « password »). | Base vide par défaut ; la page Santé signale un compte de la console avec ce mot de passe. |
| Basse | Session d'assistance : abonnement IA, paiements du live, règle de paiement des reprises et déconnexion d'un membre possibles. | Refusés pendant l'assistance. |
| Basse | CI : jeton GitHub sans droits limités ; version et identifiant venus de la plateforme écrits dans `$GITHUB_ENV` sans filtre. | `permissions: contents: read` ; valeurs filtrées. |
| Basse | Réglages dangereux de production non surveillés (cookie non sécurisé, `TRUSTED_PROXIES=*`, `.env` lisible, PayDunya en test). | Signalés par Console › Santé. |

Outils ajoutés (voir `docs/SECURITE-OUTILS.md`) : audit du serveur `deploy/security-audit.sh`, workflow « Sécurité »
(dépendances et secrets chaque semaine), contrôles de la page Santé.

À faire sur le serveur (accès nécessaire) :

1. **Haute — scripts lancés par root et modifiables par l'utilisateur du site** : la sauvegarde (cron root) et la
   mise à jour (sudo) exécutent `deploy/*.sh` depuis le dossier de l'application, qui appartient à l'utilisateur de
   PHP. Une faille PHP deviendrait un accès root (et la préproduction, sur le même serveur, y mène aussi). Copier ces
   scripts dans un dossier de root (`/usr/local/lib/jibiat/`, 0755) et y faire pointer le cron et la règle sudo.
2. **Moyenne — copie distante des sauvegardes effaçable depuis le serveur** : clé R2 dans le `.env` (lisible par
   l'utilisateur du site) avec le droit de supprimer. Clé en écriture seule, verrouillage des objets ou règle de
   conservation côté R2, secrets dans un fichier de root.
3. Basse : chiffrement des sauvegardes sans contrôle d'intégrité (`openssl enc`) : passer à `age` ou `gpg`.

À décider :

- Jibiat IA des messages WhatsApp : exiger l'abonnement (aujourd'hui seule la formule compte).
- Part d'agence sur le crédit live offert par la console (seulement si `agency_share` > 0).
- Paiement d'un e-commerçant par période : les frais sans course (live, ajustements) ne sont pas déduits.
- Import des anciens comptes : ne plus changer le numéro de connexion d'un compte déjà utilisé.
- Limite de fiches IA du live dépassable par requêtes simultanées (faible).

## Vérifié et sain

Isolement entre entreprises (scope global, jeton lié à l'adresse de l'entreprise), isolement entre marchands d'une
même entreprise, API publique (clés hachées, idempotence), webhooks entrants signés (Meta, service WhatsApp),
fichiers téléversés (privés, types contrôlés, pas de SVG), requêtes SQL (aucune entrée utilisateur interpolée),
pages publiques (suivi, avis, boutique), secrets chiffrés ou hachés en base, `.env` hors du dépôt.

## Reste à faire (à décider)

1. **Politique de sécurité du contenu (CSP)** : à mettre d'abord en mode « rapport seulement », puis à imposer.
2. **Rejeu des événements du service WhatsApp** : ajouter un horodatage signé (risque faible, le service est local).
3. **Liste des appareils connectés** avec « tout déconnecter », pour chaque compte.

## À vérifier sur le serveur de production

- `.env` : `APP_ENV=production`, `APP_DEBUG=false`, `SMS_DRIVER` réglé sur le vrai fournisseur (ou `none`).
- Sauvegarde quotidienne de la base active (Console › Santé l'indique), et une copie hors du serveur.
- Ports ouverts : seulement 22, 80 et 443 depuis l'extérieur.
