# Outils de sécurité

Trois outils surveillent la sécurité de Jibiat (et de Jibiat Marchand, sur le même serveur). Aucun ne modifie quoi
que ce soit : ils signalent, vous décidez.

## 1. Audit du serveur : `deploy/security-audit.sh`

Contrôle en lecture seule du serveur et de chaque application :

- **système** : mises à jour de sécurité en attente, mises à jour automatiques, redémarrage demandé ;
- **comptes** : autre compte root, compte sans mot de passe, règles sudo sans mot de passe, clés SSH autorisées ;
- **SSH** : connexion de root, connexion par mot de passe, nombre d'essais ;
- **pare-feu** : pare-feu actif, ports ouverts à Internet (seuls 22, 80 et 443 sont attendus), fail2ban ;
- **données** : PostgreSQL, MySQL et Redis liés à la machine seulement, Redis avec mot de passe ;
- **PHP** : `display_errors`, `expose_php`, `allow_url_include` ;
- **chaque application** : `.env` (droits, `APP_DEBUG`, `APP_KEY`, https, cookie sécurisé), fichiers PHP inattendus
  dans `public/` ou `storage/` (porte dérobée), code obfusqué, code modifié sur le serveur hors Git, sauvegarde de
  moins de 24 h, sauvegardes lisibles par tous ou non chiffrées ;
- **vu d'Internet** (pour chaque domaine) : `.env`, `.git`, journaux, `composer.json`… lisibles, en-têtes de
  sécurité, version du serveur annoncée, redirection http → https, certificat, anciens protocoles TLS.

```bash
# En root sur le serveur, depuis le dossier de l'application
sudo APPS="/chemin/livraison /chemin/marchand" DOMAINS="jibiat.com marchand.jibiat.com" \
     bash deploy/security-audit.sh --report /root/audit-$(date +%F).txt
```

Résultat : `[OK]`, `[ATTENTION]` (à surveiller) ou `[DANGER]` (à corriger tout de suite), avec la commande à lancer.
Code de sortie 0, 1 ou 2 : utilisable dans une tâche planifiée qui prévient en cas de danger. Ports supplémentaires
voulus (panneau ISPConfig par exemple) : `ALLOWED_PORTS="22 80 443 8080"`.

**Quand** : après chaque mise en production importante, et au moins une fois par mois.

## 2. Console › Santé › « Réglages de sécurité du serveur »

Contrôlé en continu par l'application, avec alerte aux super administrateurs (`platform:health --alert`) :
`APP_DEBUG`, https, jeton du service WhatsApp, cookie de session sécurisé, `TRUSTED_PROXIES=*` (adresse IP choisie
par le client, limites d'essais contournées), `.env` lisible par tous, PayDunya en mode test avec des clés (paiements
fictifs qui débloqueraient de vrais forfaits), comptes de la console restés avec le mot de passe de démonstration,
`LOG_LEVEL=debug`.

## 3. GitHub › Actions › « Sécurité » (`.github/workflows/security.yml`)

Chaque lundi matin, à la demande, et sur chaque PR qui change les dépendances :

- `composer audit` et `npm audit` (paquets livrés) : failles connues des dépendances ;
- `gitleaks` : clé, mot de passe ou jeton écrit par erreur dans le code ou l'historique Git (rapport sans les valeurs).

En cas d'échec, GitHub envoie un e-mail. Faille de dépendance : `composer update <paquet>` (ou `npm update`), tests,
PR. Secret trouvé : **le changer d'abord** chez le fournisseur (il est public pour qui a lu le dépôt), puis le retirer
du code. Faux positif : l'ajouter à `.gitleaks.toml`.

## Rythme conseillé

| Quand | Quoi |
|---|---|
| Chaque jour | Console › Santé (alertes automatiques) |
| Chaque semaine | Workflow « Sécurité » (automatique) |
| Chaque mois | `deploy/security-audit.sh` sur le serveur ; relire Console › Journal |
| Chaque trimestre | Audit du code (`docs/SECURITE.md`) ; test de restauration d'une sauvegarde |
