# Gestion et maintenance de Jibiat

Planning d'exploitation du serveur (LWS : Nginx, PHP-FPM, PostgreSQL, Redis, Horizon, Reverb, whatsapp-bridge sous
Supervisor) et de l'application. Objectif : aucune perte de données, une panne vue en moins de 5 minutes, un serveur
toujours à jour.

## Chaque jour (5 minutes, le matin)

| Quoi | Où | Ce qu'on regarde |
|---|---|---|
| Santé des services | Console › Santé | Files d'attente, tâches planifiées, WhatsApp, e-mail, SMS, espace disque, dernière sauvegarde : tout doit être vert. |
| Paiements Wave à valider | Console › Paiements | Aucun paiement en attente depuis plus de 24 h. |
| WhatsApp | Console › WhatsApp et chaque entreprise | Numéro de Jibiat relié ; une entreprise « déconnectée » doit rescanner son QR code. La connexion par QR code (non officielle) est éteinte par défaut : à allumer entreprise par entreprise dans Console › Entreprises › fiche. |
| Erreurs | `storage/logs/laravel.log` (ou Console › Santé) | Une erreur qui revient plusieurs fois = un ticket. |
| Sauvegarde de la nuit | `/var/backups/livraison/` | Le fichier du jour existe et n'est pas vide. |

## Chaque semaine (30 minutes, le lundi)

- **Journal des actions de la console** (Console › Journal) : relire les actions sensibles (suspensions, changements de
  formule, réglages Wave, interrupteur SMS). Une action inconnue = changer le mot de passe et enquêter.
- **Comptes** : retirer les comptes de l'équipe qui ont quitté l'entreprise, vérifier qu'aucun compte super
  administrateur inutile n'existe.
- **Mises à jour de sécurité du système** : `apt update && apt list --upgradable` puis `apt upgrade` (PHP, Nginx,
  PostgreSQL, Redis, OpenSSL). Redémarrer PHP-FPM et `supervisorctl restart all` ensuite.
- **Dépendances** : la CI lance les tests à chaque PR ; lancer en plus `composer audit` et `npm audit --omit=dev`
  (dans le projet et dans `whatsapp-bridge/`). Une faille « high » ou « critical » se corrige dans la semaine.
- **Performance** : temps de réponse des pages lentes (Horizon › Metrics, journal des requêtes lentes de PostgreSQL),
  taille des files d'attente aux heures de pointe.
- **Espace disque** : alerte à 80 %. Les preuves de livraison et les sauvegardes sont les plus gros dossiers.

## Chaque mois (1 heure, le premier lundi)

- **Test de restauration** : restaurer la sauvegarde de la veille dans une base de test
  (`gunzip -c sauvegarde.sql.gz | psql livraison_restore`) et ouvrir quelques courses. Une sauvegarde jamais
  restaurée n'est pas une sauvegarde.
- **Copie hors serveur** : garder au moins une sauvegarde par semaine ailleurs que sur le serveur (stockage externe,
  chiffrée). Si le serveur disparaît, les données restent.
- **Certificats HTTPS** : vérifier les dates d'expiration (renouvellement automatique Let's Encrypt, domaines
  personnalisés des entreprises compris).
- **Jetons et clés** : jetons WhatsApp (Meta) des entreprises, clés API publiques inutilisées, clés de reprise et de
  bascule de l'ancienne application à désactiver une fois la bascule finie.
- **Nettoyage** : comptes de test, entreprises d'essai expirées, courses d'essai.
- **Factures de la plateforme** : impayés, relances, entreprises à suspendre.

## Chaque trimestre (une demi-journée)

- **Revue de sécurité** : rejouer l'audit (`docs/SECURITE.md`), vérifier les en-têtes de sécurité
  (securityheaders.com), les ports ouverts du serveur (`ss -tlnp` : seuls 22, 80 et 443 depuis l'extérieur ;
  PostgreSQL, Redis, Reverb interne et whatsapp-bridge seulement sur 127.0.0.1).
- **Accès au serveur** : connexion SSH par clé seulement (pas de mot de passe), `fail2ban` actif, liste des clés
  autorisées à jour, mot de passe de la base et `APP_KEY` jamais partagés.
- **Changer les secrets** dont on n'est plus sûr : mot de passe de la base, `WHATSAPP_BRIDGE_TOKEN`, code d'accès de
  la préproduction, code du validateur Wave.
- **Montées de version** : Laravel (versions mineures), Node.js et PHP selon leur calendrier de fin de support.
- **Exercice de panne** : arrêter un service sur la préproduction (Redis, whatsapp-bridge) et vérifier que la console
  le signale et que l'application continue sans lui.

## Bonnes pratiques

### Sécurité
- **Moindre privilège** : chaque personne a son compte et le rôle le plus bas qui lui suffit ; pas de compte partagé.
- **Super administrateur** : un ou deux comptes au plus, mot de passe long et unique, jamais utilisé sur un
  ordinateur public. Toute action sensible est inscrite au journal de la console.
- **Secrets** : uniquement dans le `.env` du serveur (droits 600), jamais dans le dépôt, jamais dans une conversation,
  un e-mail ou une capture d'écran. Un secret exposé se change aussitôt.
- **Préproduction** : fermée par le code d'accès, jamais avec les vraies données des clients sans nécessité. Les
  changements réservés à la préproduction (voir `CLAUDE.md`) ne partent jamais en production.
- **Mises en production** : toujours par PR avec la CI verte, jamais de modification directe sur le serveur.

### Performance
- **Files d'attente** : les envois (WhatsApp, SMS, e-mails, webhooks) passent par Horizon ; une file qui grossit
  signale un fournisseur en panne ou un worker arrêté.
- **Cache** : `php artisan optimize` à chaque déploiement (fait par `deploy/update.sh`) ; Redis pour le cache, les
  sessions et les files.
- **Base de données** : `VACUUM ANALYZE` automatique de PostgreSQL actif ; ajouter un index quand une page de liste
  devient lente ; les positions GPS et anciennes données sont purgées par les tâches planifiées (`model:prune`).
- **Fichiers** : images compressées au téléversement, tailles limitées ; surveiller la croissance du dossier
  `storage/app`.

## En cas d'incident

1. **Constater** : Console › Santé, journal `laravel.log`, `supervisorctl status`.
2. **Limiter** : suspendre l'entreprise ou le compte en cause, couper l'interrupteur SMS si les envois s'emballent,
   déconnecter un numéro WhatsApp.
3. **Corriger** : correctif par PR, tests, mise en production.
4. **Après une fuite possible** : changer les secrets concernés, déconnecter les sessions (jetons Sanctum), prévenir
   les entreprises touchées.
5. **Noter** ce qui s'est passé et ce qu'on change pour que ça ne revienne pas.
