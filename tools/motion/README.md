# Vidéos en motion design (Jibiat et Jibiat Marchand)

Vidéos courtes pour TikTok, Facebook et les deux sites, faites avec les **vrais écrans** des deux applications,
filmés de près, avec de gros textes lisibles sans le son. Plan de publication : document « Plan de publication
Jibiat · Facebook et TikTok ».

| Scène | Cible | Durée |
|---|---|---|
| `deux-applications` | Les deux : la boutique et le livreur reliés | 23 s |
| `entreprise-caisse` | Entreprises de livraison : caisse et chiffres | 20 s |
| `entreprise-nouveaux-clients` | Entreprises de livraison : l'annuaire leur amène des clients | 20 s |
| `marchand-livreur-fiable` | E-commerçants : un livreur fiable en un clic | 24 s |
| `marchand-suivi-argent` | E-commerçants clients d'une entreprise Jibiat : colis et argent | 20 s |
| `marchand-boutique` | E-commerçants : boutique en ligne et gestion | 23 s |
| `marchand-assistant` | E-commerçants : l'assistant en un coup d'œil (vidéo principale) | 25 s |
| `marchand-ia-commande` | E-commerçants : un message WhatsApp devient une commande | 24 s |
| `marchand-ia-photo` | E-commerçants : une photo devient une fiche produit | 20 s |
| `marchand-ia-reponses` | E-commerçants : les réponses aux clients préparées | 19 s |
| `marchand-ia-reseaux` | E-commerçants : publications écrites, ce que rapporte chaque réseau | 20 s |
| `marchand-ia-chiffres` | E-commerçants : une question sur ses chiffres, les conseils du jour | 15 s |
| `marchand-essai-pro` | E-commerçants : un mois de l'offre Pro offert | 19 s |

Trois formats par scène, dans `out/` (non versionné) :

- `-v` : 1080 × 1920, TikTok, Reels, Stories (textes hors des zones couvertes par les boutons de TikTok) ;
- `-f` : 1080 × 1350, fil Facebook et Instagram ;
- `-w` : 1920 × 1080, sites.

## Fichiers

- `scenes/<scène>.js` : les plans (accroche, téléphone, fin). Un plan téléphone donne la capture, le titre
  (`*mots*` en couleur de la marque, `_mots_` en couleur de l'autre), l'approche (`zoom`, `focus`), un cadre
  (`ring`), un toucher (`tap`) et des pastilles (`pill`/`pills`), en coordonnées de la capture (390 × 844).
- `motion.js`, `motion.css`, `player.html` : le moteur ; `player.html?scene=<scène>&f=v&play` montre la scène
  dans un navigateur.
- `capture.cjs` : captures des deux applications (téléphone, échelle 3) dans `assets/` ; `probe.cjs` : position
  des éléments à montrer (`assets/positions.json`).
- `capture-ia.cjs` : écrans de l'assistant de Jibiat Marchand et écrans « vendre plus » (`assets/ia-*`,
  `assets/vendre-*`, positions dans `assets/positions-ia.json`). Le serveur local est lancé avec une clé factice
  (`ANTHROPIC_API_KEY=demo-local`) pour afficher l'assistant ; ses réponses sont jouées dans le navigateur, aucun
  appel n'atteint Claude. La photo du produit est `props/sac-wax.jpg` (dessinée dans `props/sac-wax.html`).
- `render.cjs` : rendu image par image puis MP4 (H.264, 30 images/s) ; `render-all.sh` : tout.

## Refaire les vidéos

```bash
# Les deux applications en local avec les données de démonstration (jamais la production)
php artisan migrate:fresh --seed && php tools/videos/demo-data.php && php tools/videos/prepare.php courier
APP_URL=http://127.0.0.1:8001 php artisan serve --port=8001          # Jibiat
# Jibiat Marchand : php artisan migrate:fresh --seed, puis
# DELIVERY_APP_DOMAINS=127.0.0.1 DELIVERY_APP_SCHEME=http DELIVERY_APP_DIRECTORY_URL=http://127.0.0.1:8001/api/public/directory php artisan serve --port=8000

node tools/motion/capture.cjs                 # captures (ou seulement certaines : node … marchand-commandes)
node tools/motion/render.cjs deux-applications v f w
tools/motion/render-all.sh                    # tout (environ 3 minutes par vidéo et par format)
```

Musique ou voix off : `node tools/motion/render.cjs <scène> v --audio=fichier.mp3` (le son est coupé à la fin
de la vidéo). Copies légères pour les sites (sans le son, avec leur image d'attente) :
`tools/motion/site-copies.sh public/marketing/videos deux-applications-v deux-applications-w …`.

## Textes de la voix off

À lire calmement, une phrase par plan. Les durées sont celles des plans.

**deux-applications**
1. (2,8 s) Vous vendez. Ils livrent. Et tout est relié.
2. (4,6 s) La commande arrive dans Jibiat Marchand, d'où qu'elle vienne.
3. (4,2 s) Elle part chez l'entreprise de livraison en un clic, sans rien ressaisir.
4. (3,8 s) Le livreur la reçoit sur son téléphone.
5. (4,2 s) Et les statuts et l'argent reviennent tout seuls.
6. (3,8 s) Jibiat Marchand et Jibiat. Essai gratuit.

**entreprise-caisse**
1. (3 s) Vos livreurs ont encaissé combien aujourd'hui ?
2. (4,6 s) Jibiat le sait, au franc près, livreur par livreur.
3. (4,6 s) Le livreur verse, vous validez : le point de caisse prend deux minutes.
4. (4,2 s) Et chaque soir, vous avez vos vrais chiffres.
5. (3,8 s) Jibiat : toute votre entreprise de livraison dans une seule application.

**entreprise-nouveaux-clients**
1. (2,8 s) Et si les e-commerçants venaient à vous ?
2. (5 s) Dans l'annuaire Jibiat, les boutiques voient vos vrais résultats.
3. (4,8 s) Elles vous demandent d'ouvrir un compte. Un clic pour accepter.
4. (3,8 s) Et leurs colis arrivent tout seuls.
5. (3,8 s) Plus de clients, sans démarcher. Jibiat.

**marchand-livreur-fiable**
1. (2,6 s) Votre livreur ne répond plus ?
2. (5 s) Choisissez une entreprise fiable, d'après ses vrais résultats.
3. (3,8 s) Touchez « Connecter ». Pas de clé, pas de technicien.
4. (4 s) Vous autorisez, c'est connecté.
5. (4,2 s) Vos commandes partent en un clic, et vous suivez chaque colis.
6. (3,8 s) Des livreurs fiables, en un clic. Jibiat Marchand.

**marchand-suivi-argent**
1. (2,8 s) Où est mon colis ? Où est mon argent ?
2. (4,4 s) Chaque colis, en direct : ramassé, en route, livré.
3. (4,6 s) Votre argent, au franc près.
4. (4 s) Payé par Wave ou Orange Money, avec son relevé.
5. (3,8 s) Demandez l'application Jibiat à votre livreur.

**marchand-boutique**
1. (2,6 s) Vous vendez sur WhatsApp ?
2. (4,4 s) Ayez aussi votre boutique en ligne, à votre nom.
3. (4,2 s) Toutes vos commandes au même endroit.
4. (4 s) Votre stock se tient tout seul.
5. (4 s) Vos ventes du jour, d'un coup d'œil.
6. (3,8 s) Vendez partout, gérez tout ici. Jibiat Marchand.

**marchand-assistant**
1. (2,6 s) Un assistant dans votre boutique.
2. (3,6 s) Un message WhatsApp devient une commande.
3. (3,6 s) Une photo devient une fiche produit.
4. (3,6 s) Il prépare vos réponses aux clients.
5. (3,6 s) Il écrit vos publications.
6. (3,6 s) Il répond sur vos chiffres.
7. (4 s) Vous vendez, il fait le reste. Un mois de Pro offert.

**marchand-ia-commande**
1. (2,6 s) Un client vous écrit sur WhatsApp ?
2. (4,6 s) Collez son message, même mal écrit.
3. (4,4 s) L'assistant remplit la commande : numéro, nom, quartier, article.
4. (4,2 s) Et les frais de livraison de son quartier.
5. (4,2 s) Vous vérifiez, c'est enregistré.
6. (3,8 s) Collez, vérifiez, c'est commandé. Jibiat Marchand.

**marchand-ia-photo**
1. (2,6 s) Une photo, une fiche produit.
2. (4,4 s) Prenez le produit en photo.
3. (4,6 s) L'assistant écrit le nom, la description et les mots-clés.
4. (4,2 s) Même les couleurs et les tailles. Vous fixez le prix.
5. (3,8 s) Votre catalogue en quelques minutes. Jibiat Marchand.

**marchand-ia-reponses**
1. (2,6 s) Cinquante messages sans réponse ?
2. (4,2 s) Le client demande la taille, le prix, la livraison.
3. (5 s) L'assistant prépare la réponse, avec vos vrais prix et votre stock.
4. (3,6 s) Vous relisez, vous envoyez.
5. (3,8 s) Répondez vite, vendez plus. Jibiat Marchand.

**marchand-ia-reseaux**
1. (2,6 s) Pas d'idée pour votre publication ?
2. (5 s) Trois textes prêts à publier, au bon prix, avec les hashtags.
3. (4,4 s) Facebook, Instagram, TikTok, en un clic.
4. (4,6 s) Et vous voyez ce que rapporte chaque réseau.
5. (3,8 s) Publiez mieux, sachez ce qui vend. Jibiat Marchand.

**marchand-ia-chiffres**
1. (2,6 s) Qu'est-ce qui se vend le mieux ?
2. (5 s) Posez la question, comme à un ami.
3. (4,6 s) Et chaque matin, vos conseils du jour.
4. (3,8 s) Vos chiffres vous parlent enfin. Jibiat Marchand.

**marchand-essai-pro**
1. (2,6 s) Un mois de Pro offert.
2. (4,4 s) Toute l'offre Pro, gratuite pendant un mois, sans carte bancaire.
3. (4,4 s) L'assistant, WhatsApp automatique, les statistiques.
4. (4 s) Vos commandes en dix secondes.
5. (4 s) Essayez Pro gratuitement, sur jibiat-marchand.com.
