// Entreprises de livraison : passer à Jibiat après la reprise de l'ancienne application (le ménage, puis le jour de
// la bascule). Voix off « Virginie » d'ElevenLabs (voix/bascule/b01 à b10.mp3), sous-titrée ; chaque plan dure le
// temps de ses phrases. Pages sur ordinateur : captures de tools/motion/capture-bascule.cjs (1280 × 800).
// Rendu : node tools/motion/render.cjs bascule w --audio=tools/motion/voix/bascule/voix.m4a
window.SCENE = {
  brand: 'jibiat',
  shots: [
    // 1. Accroche
    { type: 'hook', dur: 4.859, text: 'Vos données sont *déjà dans Jibiat*.',
      caption: 'Vos e-commerçants, vos livreurs et vos commandes sont déjà dans Jibiat.' },
    { type: 'hook', dur: 5.621, text: '1. Un peu de *ménage*',
      caption: 'Il reste deux choses à faire : un peu de ménage, pour partir avec des comptes justes.' },
    { type: 'hook', dur: 6.265, text: '2. Le jour de *la bascule*',
      caption: 'Puis choisir le jour où tout le monde passe à Jibiat. On le fait ensemble, pas à pas.' },

    // 2. Où on en est
    { type: 'screen', dur: 8.652, src: 'assets/bascule-accueil.png', title: 'Réglages › *Reprise de l’ancienne application*',
      caption: 'Tout se passe dans les Réglages, à la page Reprise de l’ancienne application. En haut, vous voyez où vous en êtes.',
      zoom: [1, 1.35], focus: [616, 330], ring: { at: 2.4, x: 280, y: 196, w: 672, h: 36 } },
    { type: 'screen', dur: 7.779, src: 'assets/bascule-accueil.png', title: 'L’encadré *À faire*',
      caption: 'Juste en dessous, l’encadré À faire vous dit ce qui reste à régler. On commence par l’argent.',
      zoom: [1.1, 1.5], zoomAt: [0.2, 1.8], focus: [616, 365], ring: { at: 1.6, x: 280, y: 248, w: 672, h: 234 }, tap: { at: 5.6, x: 620, y: 447 } },

    // 3. L'argent chez les livreurs
    { type: 'screen', dur: 11.727, src: 'assets/bascule-argent.png', title: 'Argent chez les livreurs : *point de départ*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/argent-livreurs',
      caption: 'Dans l’ancienne application, on ne notait pas toujours l’argent remis par les livreurs. Résultat : des mois de livraisons semblent encore chez eux.',
      zoom: [1, 1.3], zoomAt: [2, 4], focus: [768, 600], ring: { at: 4.4, x: 297, y: 520, w: 420, h: 300 } },
    { type: 'screen', dur: 10.48, src: 'assets/bascule-argent.png', title: 'Une date de départ : *le jour de la bascule*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/argent-livreurs',
      caption: 'Pour repartir à zéro, choisissez une date de départ. Le plus simple, c’est le jour de la bascule. Avant cette date, l’argent est compté comme remis.',
      zoom: [1.1, 1.7], zoomAt: [0.2, 2], focus: [560, 285], ring: { at: 2.2, x: 297, y: 245, w: 222, h: 80 }, tap: { at: 3.4, x: 408, y: 285 } },
    { type: 'screen', dur: 15.726, src: 'assets/bascule-argent-montant.png', title: 'Livreur par livreur : *Tout remis*, ou le montant',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/argent-livreurs',
      caption: 'Ensuite, livreur par livreur, comptez avec lui. S’il a tout rendu, cliquez sur Tout remis. S’il lui reste de l’argent, tapez le montant qu’il a vraiment en main. Jibiat garde ses encaissements les plus récents.',
      zoom: [1, 1.45], zoomAt: [0.6, 2.4], focus: [900, 380], ring: { at: 6, x: 905, y: 233, w: 222, h: 50 }, tap: { at: 4.2, x: 1019, y: 343 } },
    { type: 'screen', dur: 16.768, src: 'assets/bascule-argent-valider.png', title: 'Tapez *VALIDER*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/argent-livreurs',
      caption: 'Pour finir, tapez le mot VALIDER. Rassurez-vous : rien ne bouge dans la caisse, aucun message ne part, et ce que vous devez aux e-commerçants ne change pas. Et si vous vous êtes trompé, vous pouvez annuler pendant sept jours.',
      zoom: [1, 1.5], zoomAt: [0.3, 1.8], focus: [768, 650], ring: { at: 0.9, x: 804, y: 641, w: 245, h: 62 }, tap: { at: 2, x: 1150, y: 685 },
      pills: [{ at: 5, text: 'Rien ne bouge dans la caisse', pos: { w: [560, 300] } }, { at: 11.5, text: 'Annulable 7 jours', pos: { w: [1380, 300] } }] },

    // 4. L'argent dû aux e-commerçants
    { type: 'screen', dur: 6.944, src: 'assets/bascule-soldes.png', title: 'Contrôles et ménage › *Comparer les soldes*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/controles',
      caption: 'Maintenant, l’argent que vous devez à vos e-commerçants. Cliquez sur Comparer les soldes.',
      zoom: [1, 1.5], zoomAt: [0.3, 2], focus: [616, 170], ring: { at: 2.4, x: 772, y: 190, w: 163, h: 38 }, tap: { at: 4.2, x: 853, y: 209 } },
    { type: 'screen', dur: 10.207, src: 'assets/bascule-soldes-compare.png', title: 'Ancienne application et Jibiat, *côte à côte*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/controles',
      caption: 'Pour chaque e-commerçant, Jibiat met côte à côte ce que dit l’ancienne application et ce que dit Jibiat. Les deux doivent être les mêmes.',
      zoom: [1, 1.45], zoomAt: [0.3, 2.2], focus: [616, 420], ring: { at: 2.6, x: 297, y: 240, w: 313, h: 364 } },
    { type: 'screen', dur: 7.535, src: 'assets/bascule-soldes-compare.png', title: 'C’est bon ? *Valider ces soldes*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/controles',
      caption: 'Si c’est bon, cliquez sur Valider ces soldes. Vos e-commerçants retrouveront exactement ce qu’on leur doit.',
      zoom: [1.2, 1.6], zoomAt: [0.1, 1.6], focus: [616, 630], ring: { at: 1.2, x: 297, y: 612, w: 638, h: 28 }, tap: { at: 2.8, x: 370, y: 666 } },

    // 5. Les vieilles courses
    { type: 'screen', dur: 15.627, src: 'assets/bascule-nettoyage.png', title: 'Anciennes courses : *Simuler d’abord*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/controles',
      caption: 'Dans l’ancienne application, des courses sont restées ouvertes depuis longtemps. Jibiat vous montre celles qui ne sont pas terminées depuis plus de trente jours. Cliquez d’abord sur Simuler, pour voir ce qui va se passer, sans rien changer.',
      zoom: [1, 1.5], zoomAt: [5, 7], focus: [616, 230], ring: { at: 8.5, x: 430, y: 233, w: 77, h: 38 }, tap: { at: 10.4, x: 468, y: 252 } },
    { type: 'screen', dur: 8.892, src: 'assets/bascule-nettoyage.png', title: 'Annulées, ou *retournées à l’e-commerçant*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/controles',
      caption: 'Les courses jamais ramassées seront annulées. Celles qui ont été ramassées repartent chez l’e-commerçant.',
      zoom: [1.1, 1.55], zoomAt: [0.1, 1.6], focus: [616, 422], ring: { at: 1.2, x: 297, y: 319, w: 638, h: 206 } },
    { type: 'screen', dur: 10.528, src: 'assets/bascule-en-chemin.png', title: 'En chemin : *vous vérifiez*, livreur par livreur',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/a-verifier',
      caption: 'Et celles qui sont encore en chemin, Jibiat ne décide pas à votre place : vous les vérifiez livreur par livreur, livrées ou retournées.',
      zoom: [1, 1.3], zoomAt: [0.5, 2.3], focus: [768, 330], ring: { at: 2.6, x: 280, y: 172, w: 317, h: 70 }, tap: { at: 6.6, x: 911, y: 372 } },
    { type: 'screen', dur: 10.694, src: 'assets/bascule-nettoyage-cloturer.png', title: 'Tapez *CLÔTURER*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/controles',
      caption: 'Si le résultat vous convient, cliquez sur Clôturer, puis tapez le mot CLÔTURER. Aucun message ne part, et vous pouvez annuler pendant sept jours.',
      zoom: [1, 1.55], zoomAt: [0.3, 1.9], focus: [616, 680], ring: { at: 2, x: 677, y: 636, w: 245, h: 62 }, tap: { at: 3.4, x: 391, y: 728 },
      pills: [{ at: 6, text: 'Aucun message · annulable 7 jours', pos: { w: [960, 290] } }] },

    // 6. Les commandes supprimées
    { type: 'screen', dur: 16.144, src: 'assets/bascule-supprimees.png', title: 'Supprimées là-bas : *Garder ou Annuler*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/supprimees',
      caption: 'Dernier point du ménage : les commandes effacées dans l’ancienne application alors qu’il y avait de l’argent en jeu. Jibiat ne les supprime pas toute seule. Pour chacune, choisissez : Garder, ou Annuler.',
      zoom: [1, 1.4], zoomAt: [1, 3], focus: [768, 290], ring: { at: 11.5, x: 1080, y: 228, w: 170, h: 50 }, tap: { at: 13.6, x: 1123, y: 253 } },

    // 7. La bascule
    { type: 'hook', dur: 2.754, text: 'Place à *la bascule*.', caption: 'Le ménage est fait. Place à la bascule.' },
    { type: 'screen', dur: 15.153, src: 'assets/bascule-bascule.png', title: 'Bascule vers Jibiat : *le jour et l’heure*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/bascule',
      caption: 'Ouvrez l’étape Bascule vers Jibiat. Choisissez le jour et l’heure, par exemple lundi matin à six heures, avant les premiers ramassages. Jusqu’à cette heure-là, rien ne change pour personne.',
      zoom: [1, 1.6], zoomAt: [2, 4], focus: [600, 420], ring: { at: 4.4, x: 300, y: 365, w: 640, h: 104 },
      pills: [{ at: 6.5, text: 'Lundi 2 novembre à 6 h', pos: { w: [960, 290] } }] },
    { type: 'screen', dur: 8.915, src: 'assets/bascule-bascule.png', title: 'Déplacer, annuler, ou *basculer maintenant*',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/bascule',
      caption: 'Vous pouvez déplacer la date ou l’annuler quand vous voulez. Et si vous êtes prêt tout de suite, cliquez sur Basculer maintenant.',
      zoom: [1.3, 1.7], zoomAt: [0.1, 1.5], focus: [620, 450], ring: { at: 4.2, x: 677, y: 426, w: 163, h: 38 }, tap: { at: 6, x: 758, y: 445 } },
    { type: 'screen', dur: 5.674, src: 'assets/bascule-bascule-faite.png', title: 'Qui est *déjà passé* à Jibiat',
      url: 'express-ci.jibiat.com/admin/reglages/reprise/bascule',
      caption: 'Ici, vous suivez combien d’e-commerçants et de livreurs sont déjà passés à Jibiat.',
      zoom: [1.2, 1.7], zoomAt: [0.1, 1.5], focus: [616, 520], ring: { at: 1.5, x: 290, y: 540, w: 652, h: 38 } },

    // 8. Les livreurs et les e-commerçants
    { type: 'hook', dur: 5.459, text: 'Eux, *n’ont rien à faire*.',
      caption: 'Le jour venu, vos livreurs et vos e-commerçants n’ont rien à faire.' },
    { type: 'phone', dur: 11.691, app: 'livreur', src: 'assets/j-livreur.png', title: 'Ancienne application ouverte… *arrivé dans Jibiat*',
      caption: 'Ils ouvrent l’ancienne application, comme d’habitude, et ils arrivent directement dans Jibiat, déjà connectés. Pas de nouveau compte, pas de mot de passe à retaper.',
      zoom: [1, 1.35], zoomAt: [4, 6], focus: [195, 300] },
    { type: 'phone', dur: 5.55, app: 'ecommercant', src: 'assets/j-ecommercant-courses.png', title: 'Leurs courses et *leur argent*',
      caption: 'Le livreur retrouve ses missions, l’e-commerçant retrouve ses courses et son argent.',
      zoom: [1, 1.3], zoomAt: [1, 3], focus: [195, 300] },

    // 9. Fin
    { type: 'hook', dur: 11.987, text: 'Après la bascule, *Jibiat fait foi*.',
      caption: 'À partir de la bascule, c’est Jibiat qui fait foi. Si une commande arrive encore par l’ancienne application, elle est copiée dans Jibiat et votre équipe est prévenue.' },
    { type: 'end', dur: 4.8, tagline: 'Bienvenue dans *Jibiat*.', caption: 'Bienvenue dans Jibiat.' },
  ],
}
