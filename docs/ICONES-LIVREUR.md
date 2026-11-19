# Icônes de l'application livreur

L'application livreur n'utilise plus d'emojis : chaque événement d'une mission a son icône, toujours la même. Les
emojis restent réservés aux messages WhatsApp envoyés aux clients.

Deux familles, déjà utilisées dans l'application e-commerçant :

- **Icônes en couleur** (`components/mobile/ColorIcon.vue`, dessins dans `colorIcons.js`) : la grande tuile à gauche
  d'une carte de mission. Elle dit d'un coup d'œil de quoi il s'agit.
- **Icônes au trait** (`components/ui/Icone.vue`, dictionnaire `utils/icones.js`, bibliothèque Lucide) : les onglets,
  les boutons et les petites étiquettes. Elles prennent la couleur du texte voisin.

Une idée nouvelle reçoit son icône dans le dictionnaire avant d'entrer dans un écran.

## Tuiles en couleur (cartes de mission)

| Événement | Icône | Nom |
|---|---|---|
| Ramassage à faire | boutique (violet) | `boutique` |
| Livraison à faire | moto (bleu) | `moto` |
| Retour à faire | flèche de retour (rose) | `retour` |
| Mission faite (ramassé, livré, retourné) | coche (vert) | `livre` |
| Échec de livraison | croix (rouge) | `echec` |
| Itinéraire vide | trajet (indigo) | `itineraire` |

## Icônes au trait

| Événement ou action | Nom | Où |
|---|---|---|
| Mon itinéraire | `itineraire` | onglet |
| Ramasser | `ramasser` | onglet, tâche d'un arrêt |
| Ramassé | `ramasse` | |
| Livrer | `livrer` | onglet, tâche d'un arrêt |
| Retourner | `retourner` | onglet, tâche d'un arrêt |
| Livré / fait | `livre` | étiquette « Déjà… » |
| Échec | `echec` | étiquette « Échecs » |
| Organiser mon itinéraire | `organiser` | bouton |
| Modifier l'ordre | `modifier-ordre` | bouton |
| Monter / descendre un arrêt | `monter`, `descendre` | mode « Modifier l'ordre » |
| Trajet dans Google Maps | `trajet` | bouton |
| Adresse | `adresse` | ligne d'adresse |
| Retour tout près | `a-proximite` | rappel sur une livraison |
| Express | `rapide` | étiquette |
| Relais avec un autre livreur | `relais` | étiquette, bandeau |
| Course modifiée | `modifier` | étiquette |
| Consigne de l'agence | `message` | étiquette, bandeau |
| Action hors connexion à envoyer | `hors-ligne` | étiquette, bandeau |
| À expédier (gare) | `expedition` | étiquette |
| Gain prévu | `gain` | étiquette |
| Argent à verser | `argent` | carte Caisse |
| Bon de retour à faire signer | `bon-retour` | bandeau |
| Disponibilité | `disponible` | |
| Notifications | `notifications` | cloche, invitation |
| Ouvrir | `ouvrir` | chevron d'une carte |
