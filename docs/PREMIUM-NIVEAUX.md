# Niveaux Premium des e-commerçants

Chaque entreprise récompense ses meilleurs e-commerçants avec des niveaux (par exemple Argent, Or, Platine).

## Côté entreprise (Programme Premium › Avantages et niveaux)

- Jusqu'à 3 niveaux (limite réglée dans la console). Pour chacun : nom, conditions (courses livrées, taux de
  réussite, ancienneté du client, sur la période du programme, 90 jours par défaut).
- Avantages livraison, appliqués tout seuls :
  - **réduction sur les frais de livraison** : retirée du tarif à la création de la course (et dans les devis),
    gardée sur la course (`orders.premium_discount`) ;
  - **paiement plus rapide** (2 fois par semaine, chaque jour) : ces e-commerçants passent en tête de la liste
    des paiements de la caisse, avec un badge ;
  - **ramassage prioritaire** : leurs colis passent en tête des ramassages à prévoir, avec un badge ;
  - **heure limite plus tardive** pour la livraison du jour (de 30 min à 2 h) ;
  - **retours moins chers ou gratuits** : réduction sur les frais de retour.
- **Jibiat Marchand offert** : formule (Starter, Pro, Business si permise), mois offerts, réduction ensuite, dans
  les limites de la console. Quand un e-commerçant atteint ce niveau et n'a pas encore de carte, la carte Membre
  Premium lui est offerte avec cette formule (socle 1.8, champ `offer`), au prix de la formule si l'entreprise paie.
- Bilan le 1er de chaque mois (`php artisan premium:levels`), ou tout de suite avec « Faire le bilan
  maintenant ». Un e-commerçant qui doit de l'argent depuis trop longtemps n'a pas de niveau. Celui qui monte est
  prévenu (modèle WhatsApp `niveau_premium_atteint`, à faire valider chez Meta).

## Côté console (Programme Premium)

- Niveaux au plus, réduction livraison au plus.
- Formules Jibiat Marchand permises : permise ou non, mois offerts au plus, réduction ensuite au plus, prix d'une
  carte (quand l'entreprise paie).
- Liste des entreprises avec leurs niveaux.

## Côté e-commerçant (accueil de l'application)

Son niveau, ses avantages, ce qu'il a gagné ce mois grâce aux réductions, la date du prochain bilan, et ce qui
lui manque pour le niveau suivant.
