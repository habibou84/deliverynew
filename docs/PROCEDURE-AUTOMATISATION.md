# Automatiser entièrement les assignations, de A à Z

Objectif : chaque course est validée, ramassée et livrée par le bon livreur sans que l'équipe ne la confie à la main.
L'équipe ne fait plus que superviser et traiter les exceptions.

Suivre les étapes dans l'ordre. Les noms entre guillemets sont ceux des écrans.

---

## Étape 1 — Zones et tarifs

**Réglages → Zones et tarifs**

1. **Zones** : vérifier que les communes et quartiers où vous travaillez sont actifs.
2. **Tarifs et retours** : chaque trajet doit avoir un prix (même commune, entre communes, périphérie).

Une course sans zone connue ne peut pas partir seule.

## Étape 2 — Les livreurs

**Équipe → Livreurs → Modifier**, pour chaque livreur :

| Champ | Quoi mettre | Pourquoi |
|---|---|---|
| Véhicule | moto, voiture, tricycle… | un gros colis ne va pas sur une moto |
| Zones desservies | ses communes en **Principale**, une ou deux en **De secours** | la zone de secours sert quand personne de la zone principale n'est libre |
| Colis au plus | par exemple 15 | au-delà, il ne reçoit plus de livraison |
| Argent en main au plus | par exemple 300 000 F | au-delà, il ne reçoit plus de livraison |
| Fin de service | par exemple 19:00 | après cette heure, plus rien ne lui est confié |
| Acceptation des missions | laisser vide pour suivre le réglage de l'entreprise | un livreur peut avoir sa propre règle |

Côté livreur :
- installer l'application **Jibiat Livreur** (Android) et activer la **sonnerie des missions** ;
- se mettre **Disponible** en début de journée ; garder l'application ouverte (positions et signe de vie).

## Étape 3 — Le planning

**Activité des livreurs → Planning → + Nouvelle règle**

1. **Ramassages** : une règle par secteur.
   - Exemple : « Awa ramasse à **Cocody**, tous les jours, remplaçant **Fatou** ».
   - Toujours mettre un **remplaçant**.
   - Une commune couvre ses quartiers ; un quartier précis l'emporte sur sa commune.
2. **Clients particuliers** : une règle sur un client l'emporte sur la zone.
   - Exemple : « Koffi ramasse chez **Tech Store**, le **lundi et le jeudi** ».
3. **Livraisons** : une règle par secteur (facultatif mais conseillé), avec remplaçant.
4. **Absences** (bloc **Absences** de la même page) : livreur, dates, motif. Son remplaçant prend tout seul ses courses.
5. Vérifier le bloc **« Clients sans ramasseur ce jour-là »** : il doit être vide, ou ne contenir que des clients
   habituels sans colis. Chaque client listé a un bouton **Créer une règle** déjà rempli.
6. Passer d'un jour à l'autre avec les flèches pour vérifier la semaine (samedi, dimanche).

## Étape 4 — Les réglages des courses

**Réglages → Courses et livraison**

| Bloc | Réglage | Valeur conseillée |
|---|---|---|
| Validation des nouvelles courses | | **Automatique** (ou « Automatique avec règles » pour garder les courses tardives au lendemain) |
| Modifications des e-commerçants | | **À valider par l'équipe si le prix change** |
| Journée | Heure limite du jour | **16:00** en semaine ; ajouter **12:00** le samedi si besoin |
| Journée | Nombre maximal de tentatives de livraison | **3** |
| Livreurs | Exiger le code de livraison du destinataire | **oui** |
| Livreurs | Mode hors connexion des livreurs | **oui** |
| Livreurs | Relais obligatoire entre livreurs | **oui** (deux livreurs se passent le colis avec un code) |
| Livreurs | Après une mission ramassage, les colis… | **Restent chez le livreur** (sa livraison lui est confiée le jour venu) |
| Livreurs | Heure de fermeture du dépôt | **19:00** |
| Ramassage automatique | | **À l'essai** d'abord, puis **Active** (voir étape 6) |
| Livraison du jour même automatique | | **À l'essai** d'abord, puis **Active** |
| Livraison du jour même automatique | Montant à encaisser au plus | par exemple **150 000 F** (au-delà, l'équipe choisit) |
| Livraison du jour même automatique | Le livreur qui a fait le ramassage livre aussi | selon votre organisation |
| Acceptation des missions | | **Acceptées d'office, empêchement possible** |
| Acceptation des missions | Livreur injoignable après (minutes) | **20** (sa mission passe à son remplaçant) |
| Acceptation des missions | Ramassage pas commencé : alerte (minutes avant l'heure limite) | **60** |
| Acceptation des missions | Alerte « livreur libre » (une par livreur et par jour) | **oui** |

Cliquer sur **Enregistrer** en bas de la page.

## Étape 5 — Les alertes de supervision

**Réglages → Alertes et rappels**

| Bloc | Valeur conseillée |
|---|---|
| Courses sans livreur | alerte ramassage **30 min**, alerte livraison **30 min** |
| Colis resté chez un livreur | **24 h** |
| Relance des remontées terrain | **15 min** |
| Caisse : argent gardé et clôture | alerte après **6 h** ou au-delà d'un montant ; rappel de clôture **19:30** ; récapitulatif envoyé |
| Point de la semaine | **envoyé le lundi** |

**Réglages → Messages aux clients** : brancher WhatsApp pour que l'e-commerçant et le destinataire soient prévenus à
chaque étape (validation, ramassage, « en route » avec le code, livré, avis).

## Étape 6 — La mise à l'essai (2 ou 3 jours)

1. Laisser **Ramassage automatique** et **Livraison du jour même** sur **À l'essai**.
2. Continuer à confier les courses à la main comme avant.
3. Sur chaque course, Jibiat note le livreur qu'il **aurait** choisi, et pourquoi.
4. Comparer avec vos choix : si un choix ne vous convient pas, corriger le planning (règle, remplaçant, zone de
   secours) ou la fiche du livreur (capacité, véhicule).
5. Quand les choix sont bons, passer les deux réglages sur **Active**.

## Étape 7 — Ce qui se passe tout seul chaque jour

| Moment | Ce que fait Jibiat |
|---|---|
| 6 h 45 | Chaque livreur reçoit son **message du matin** : ce qu'il couvre ou remplace aujourd'hui |
| 6 h 50 | Les courses du jour sont préparées, rappel des courses dues aujourd'hui |
| À chaque course créée | Validation, puis ramassage au livreur du planning (ou à son remplaçant) |
| Colis tardif chez une boutique déjà prévue | Ajouté au livreur qui est déjà en route |
| Colis ramassé | Livraison confiée au meilleur livreur : celui qui a le colis, le planning, la zone, le secteur, le moins chargé |
| Le jour de livraison d'un colis gardé | Livraison confiée au livreur qui l'a |
| Livreur qui signale un empêchement | La mission passe au remplaçant |
| Livreur muet 20 min avec une mission | La mission passe à un autre livreur, l'équipe est prévenue |
| Toutes les 5 min | Rattrapage des courses restées sans livreur |
| Chaque minute | Contrôle des courses sans livreur et des missions en retard |

L'application du livreur ne montre que les **livraisons du jour** : il ne peut pas faire une course en retard ou prévue
un autre jour.

## Étape 8 — La supervision

### Le matin (5 minutes)

1. **Activité des livreurs → Planning** : jour affiché = aujourd'hui. Vérifier les absences et les « Clients sans
   ramasseur ».
2. **Activité des livreurs → Disponibles** : qui est en service.
3. **Courses → ⏳ En retard** : pour chaque course, **remettre à la date du jour** ou **annuler**.

### Pendant la journée

- **Activité des livreurs → Suivi du jour** (en direct) : avancement, fin estimée, livreurs **à surveiller**
  (mission en retard, pas de position, argent à surveiller, risque de finir après la fermeture), bandeau des
  **colis en retard d'avant**.
- Depuis la fiche d'un livreur : **Appeler**, **WhatsApp**, **Envoyer une consigne**, **Réassigner** une mission.
- **Écran mural** (bouton en haut du suivi) : à afficher sur une télévision au dépôt.
- **La cloche** 🔔 : courses sans livreur, livreur injoignable, ramassage pas commencé, colis gardé trop longtemps,
  argent gardé trop longtemps, remontée terrain.
- Files de **Courses** à regarder quand la cloche le signale :
  - **🛵 Sans livreur** : aucune règle ne couvre la course → l'assigner, puis créer la règle qui manque ;
  - **À décider** : échecs de livraison → nouvelle date, retour ou annulation ;
  - **↩️ Retours demandés** : organiser le retour au marchand ;
  - **✏️ Modifications à valider** : modifications d'e-commerçants qui changent le prix ;
  - **⚠️ Codes à vérifier** : livraisons faites hors connexion avec un code incorrect.
- **Activité des livreurs → Remontées terrain** : problèmes signalés par les livreurs.

### Le soir

1. **Caisse** : chaque livreur fait le point (argent et colis rendus) ; bouton **Faire le point** depuis le suivi.
2. **Activité des livreurs → Colis en main** : colis encore chez les livreurs.
3. **Caisse → Clôture du jour** au rappel.

### En cas de souci

**Activité des livreurs → Planning → ⏸ Suspendre l'automatisation** : plus rien n'est confié automatiquement, les
alertes continuent. Reprendre avec le même bouton.

## Ce qui reste à l'équipe

- Les courses qu'aucune règle ne couvre (alerte « sans livreur »).
- Les échecs et reports (file « À décider »), les retards (remettre à date ou annuler).
- Les retours au marchand.
- La réception de l'argent et des colis à la caisse.
- Les expéditions et les colis au-dessus du plafond d'encaissement : pas de livraison automatique.
