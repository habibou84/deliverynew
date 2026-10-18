# Mise à jour automatique des serveurs

Après chaque fusion, GitHub met lui-même à jour le serveur concerné, une fois les tests réussis (CI) :

- fusion dans `main` → **préproduction** (`bash /var/www/jibiat-preprod/deploy/update.sh`) ;
- fusion dans `production` → **production** (`bash /var/www/clients/client0/web2/web/livraison/deploy/update.sh`).

Si les tests échouent, rien n'est mis à jour.

GitHub se connecte au serveur avec l'utilisateur `lvdeploy` et une clé qui ne peut lancer **que** cette commande : pas
de terminal, pas d'autre commande, pas de redirection. La clé est rangée dans les secrets du dépôt GitHub, et personne
ne peut la relire.

Le déroulement et le résultat se lisent dans GitHub › Actions › « Mise à jour des serveurs ».

Tant que les secrets ne sont pas enregistrés, l'automatisme ne fait rien. La commande manuelle reste toujours possible.

C'est le même principe que pour Jibiat Marchand, mais avec un utilisateur et des clés à part (`lvdeploy`, et non
`jmdeploy`). Les deux applications ne se touchent pas.

## Mise en place (une seule fois)

1. **Préproduction à jour.** C'est elle qui contient le script :

   ```bash
   bash /var/www/jibiat-preprod/deploy/update.sh
   ```

2. **Sur le serveur, en root :**

   ```bash
   bash /var/www/jibiat-preprod/deploy/setup-auto-deploy.sh
   ```

   Le script :
   - crée l'utilisateur `lvdeploy` ;
   - l'autorise à lancer les deux mises à jour, et rien d'autre ;
   - crée une clé pour la préproduction et une pour la production ;
   - affiche les valeurs à recopier.

   S'il signale une ligne `AllowUsers`, ajoutez `lvdeploy` à cette ligne de `/etc/ssh/sshd_config`, puis lancez
   `systemctl reload ssh`.

3. **Dans GitHub** › dépôt `deliverynew` › Settings › Secrets and variables › Actions › New repository secret.
   Créez un secret par valeur :

   | Secret | Valeur |
   |---|---|
   | `DEPLOY_HOST` | adresse IP publique du serveur (espace client LWS) |
   | `DEPLOY_PORT` | port SSH affiché (souvent 22) |
   | `DEPLOY_USER` | `lvdeploy` |
   | `DEPLOY_HOST_KEY` | ligne `ssh-ed25519 …` affichée. C'est l'empreinte du serveur : GitHub ne se connectera qu'à lui. |
   | `DEPLOY_KEY_PREPROD` | bloc de la clé de la préproduction, de `-----BEGIN` à `END … KEY-----` inclus |
   | `DEPLOY_KEY_PRODUCTION` | bloc de la clé de la production |

4. **Effacez les copies des clés** sur le serveur :

   ```bash
   rm -r /root/.livraison-deploy
   ```

5. **Faites un essai** : GitHub › Actions › « Mise à jour des serveurs » › Run workflow, branche `main`.
   - Le résultat se termine par la version installée.
   - Il vérifie aussi que `https://preprod.jibiat.com/up` répond.

Les clés ne s'envoient à personne : ni par message, ni dans une conversation.

## Au quotidien

1. Une PR est fusionnée dans `main` : la préproduction se met à jour toute seule, quelques minutes plus tard.
2. Vous testez sur `https://preprod.jibiat.com`.
3. « Passe en production » : la fusion de `main` vers `production` met la production à jour toute seule.

## En cas de problème

- **« Permission denied (publickey) »** : le secret `DEPLOY_KEY_…` est incomplet (copiez tout le bloc), ou `lvdeploy`
  manque dans `AllowUsers`.
- **« Host key verification failed »** : `DEPLOY_HOST_KEY` ne correspond pas au serveur. Relancez le script et
  recopiez la ligne `ssh-ed25519 …`.
- **Erreur pendant la mise à jour** : le message est dans le journal de l'action. Le site sort du mode maintenance
  même en cas d'échec. Relancez la mise à jour à la main sur le serveur si besoin.
- **Aucune mise à jour après une fusion** : regardez d'abord si les tests (CI) ont réussi. La mise à jour ne part
  qu'après eux.
- **Changer les clés** : relancez `setup-auto-deploy.sh` (les anciennes clés cessent de fonctionner), puis remplacez
  les secrets.
- **Couper l'automatisme** : supprimez le secret `DEPLOY_HOST`, ou lancez `rm /etc/sudoers.d/livraison-deploy` sur
  le serveur.
