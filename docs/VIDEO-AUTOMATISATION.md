# Vidéo « Jibiat en pilote automatique »

Montrer qu'une entreprise de livraison peut tourner sans que l'admin ne confie une seule course : les courses sont
validées, les ramassages et les livraisons partent tout seuls au bon livreur, avec remplaçant si besoin.

Durée visée : 3 min 30 environ. Format 16:9 (écran d'ordinateur) avec des plans de téléphone pour le livreur et
l'e-commerçant.

## Préparer le tournage (préproduction)

1. Sur le serveur de préproduction : `php artisan demo:automation`. La commande crée ou remet en place l'entreprise
   **Express Auto Démo** (adresse `auto-demo.preprod.jibiat.com`) : 6 livreurs avec zones, véhicule, capacité et
   fin de service, 5 e-commerçants, le planning des ramassages et des livraisons avec remplaçants, une absence, et tous
   les réglages de l'automatisation. Elle refuse de tourner en production.
2. Les comptes (mot de passe de démonstration) s'affichent sur la page de connexion de la préproduction :
   - administrateur `0711000010` (`/admin`) ;
   - livreurs `0711000001` à `0711000006` (`/livreur`) : Awa (Cocody), Yacouba (Yopougon), Serge (Marcory,
     Treichville), Fatou (Plateau, Adjamé), Ibrahim (Abobo, Anyama), Koffi (Koumassi, Port-Bouët) ;
   - e-commerçants `0511000001` à `0511000005` (`/`) : Boutique Chic (Cocody), Saveurs d'Ivoire (Yopougon), Tech
     Store (Marcory), Beauté Divine (Plateau), Mode Abobo (Abobo).
3. Ouvrir l'application d'Awa sur un téléphone avant de filmer (un livreur muet plus de 20 minutes perd ses missions
   au profit de son remplaçant : c'est une scène possible, mais pas par accident).
4. Tourner avant l'heure limite du jour (16 h, 12 h le samedi) : après, les livraisons du jour même ne partent plus.
5. Pour remplir l'écran de suivi pendant le tournage : `php artisan demo:automation --courses=10` crée dix courses
   comme les e-commerçants, validées et confiées aussitôt.

## Script

Chaque scène : ce qu'on voit à l'écran, puis le texte de la voix off.

### Scène 1 — Accroche (0:00 – 0:15)

**À l'écran :** le suivi du jour (Activité des livreurs), les cartes des livreurs qui avancent toutes seules, puis le
dispatch vide : aucune course « sans livreur ».

**Voix off :** « Imaginez une journée de livraison où vous ne confiez aucune course à la main. Les colis sont
validés, ramassés et livrés par le bon livreur, au bon moment. C'est ce qu'on va régler ensemble dans Jibiat. »

### Scène 2 — Les livreurs (0:15 – 0:45)

**À l'écran :** Livreurs → fiche d'Awa : zones (Cocody principale, Plateau en secours), moto, 15 colis maximum,
300 000 F maximum en main, fin de service 19 h.

**Voix off :** « Tout commence par les livreurs. Pour chacun, on indique ses zones : celle où il travaille, et une
zone de secours. Son véhicule, pour qu'un gros colis n'aille pas sur une moto. Combien de colis et combien d'argent
il peut avoir en même temps. Et l'heure où il finit : après, il ne reçoit plus rien. »

### Scène 3 — Le planning (0:45 – 1:25)

**À l'écran :** Activité des livreurs → Planning. Les règles : « Awa ramasse à Cocody, tous les jours, remplaçante
Fatou ». La règle de Tech Store, le lundi et le jeudi. Les règles de livraison par secteur. L'absence de Fatou, puis
la date de son congé : Awa la remplace. Le bloc « clients sans ramasseur », rangé par zone, et « Créer une règle ».

**Voix off :** « Ensuite, le planning : qui ramasse où, et quand. Awa ramasse à Cocody tous les jours, et si elle
est absente, Fatou prend le relais. Un client a de gros volumes le lundi et le jeudi ? On lui donne sa propre règle.
On fait pareil pour les livraisons, par secteur. Un livreur en congé ? On note son absence, et son remplaçant reprend
ses courses tout seul. Et si un client n'est couvert par personne, Jibiat vous le montre, avec un bouton pour créer
la règle. »

### Scène 4 — Les réglages qui automatisent (1:25 – 2:10)

**À l'écran :** Réglages, dans l'ordre :
- Validation des courses : automatique ;
- Ramassage automatique : actif (montrer qu'il existe « à l'essai » pour commencer) ;
- Livraison du jour même : active, plafond 150 000 F ;
- Acceptation des missions : acceptées d'office ;
- Délai injoignable : 20 minutes ;
- Heure limite : 16 h, 12 h le samedi ; fermeture du dépôt : 19 h ;
- Colis après ramassage : gardé par le livreur ; relais avec code.

**Voix off :** « Place aux réglages. Les courses des e-commerçants sont validées toutes seules. Le ramassage
automatique suit le planning : vous pouvez d'abord le mettre à l'essai, pour voir ses choix sans rien envoyer. La
livraison du jour même part seule, jusqu'au montant que vous fixez. Les missions sont acceptées d'office : le livreur
peut seulement signaler un empêchement. S'il ne donne pas signe de vie pendant vingt minutes, sa mission passe à son
remplaçant. Enfin, l'heure limite du jour, l'heure de fermeture du dépôt, et le relais avec code quand deux livreurs
se passent un colis. »

### Scène 5 — Une course arrive (2:10 – 2:40)

**À l'écran :** téléphone de Boutique Chic (`0511000001`) : nouvelle course pour Cocody. Écran partagé : la course
apparaît validée, puis le ramassage chez Awa. Téléphone d'Awa : la mission sonne et s'affiche, déjà acceptée.

**Voix off :** « Une boutique crée une course depuis son téléphone. Elle est validée aussitôt, et le ramassage part
chez Awa, la livreuse de Cocody. Personne au bureau n'a touché à rien. »

### Scène 6 — Le ramassage, puis la livraison (2:40 – 3:05)

**À l'écran :** Awa marque le colis ramassé. La livraison lui est confiée (ou au livreur du secteur, avec un relais à
code). Le destinataire reçoit le message « en route » avec son code. Awa livre, le code est vérifié.

**Voix off :** « Dès que le colis est ramassé, la livraison part au meilleur livreur : celui qui a le colis, celui
du secteur, le moins chargé. Le client reçoit un message avec son code. À la livraison, le code est vérifié, et on
lui demande son avis. »

### Scène 7 — Quand quelque chose coince (3:05 – 3:20)

**À l'écran :** Awa signale un empêchement sur une mission : la mission passe à Fatou. Notification à l'équipe.

**Voix off :** « Un imprévu ? Le livreur signale un empêchement, et la course passe au remplaçant. L'équipe est
prévenue, mais n'a rien à faire. »

### Scène 8 — Le suivi et la fin de journée (3:20 – 3:35)

**À l'écran :** suivi du jour (fin estimée, avancement, retards d'avant à part), l'écran mural du dépôt, le rappel de
clôture de la caisse.

**Voix off :** « Vous suivez tout en direct : qui a fini, qui risque de finir tard. Le soir, chaque livreur fait le
point à la caisse. Jibiat : la livraison en pilote automatique. »

## Ce qui reste à l'équipe (à ne pas promettre dans la vidéo)

- Une course qu'aucune règle du planning ne couvre ne part pas seule : l'équipe reçoit l'alerte « sans livreur ».
- Les échecs et les reports se décident à l'agence ou par l'e-commerçant (file « À décider »).
- Les retards : l'agence remet la course à la date du jour ou l'annule ; le livreur ne voit que les livraisons du jour.
- Les retours au marchand, et la réception de l'argent et des colis à la caisse.
- Pas de livraison automatique pour les expéditions ni au-dessus du plafond d'encaissement.
