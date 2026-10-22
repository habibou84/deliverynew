// E-commerçants : présentation générale de Jibiat Marchand (voix off « Tony » dans voix/marchand-*.mp3, sous-titres)
// Chaque « caption » est le texte de la voix off du plan ; pas de pastilles, pour ne pas les cacher.
window.SCENE = {
  brand: 'marchand',
  shots: [
    {
      type: 'hook', dur: 6.1, text: 'Vos commandes *partout* ?',
      caption: 'Vous vendez sur WhatsApp, Facebook, TikTok… et vos commandes s’éparpillent dans vos messages ?',
    },
    {
      type: 'hook', dur: 5.7, text: '*Jibiat Marchand* met tout au même endroit.',
      caption: 'Jibiat Marchand réunit toute votre boutique dans une seule application. Voyons comment.',
    },
    {
      type: 'phone', dur: 6.3, app: 'marchand', src: 'assets/marchand-commandes.png',
      title: 'Toutes vos commandes *au même endroit*',
      caption: 'WhatsApp, Facebook, TikTok ou boutique en ligne : toutes vos commandes arrivent au même endroit.',
      zoom: [1, 1.6], focus: [195, 560], ring: { at: 1.5, x: 16, y: 456, w: 358, h: 196 },
    },
    {
      type: 'phone', dur: 5.9, app: 'marchand', src: 'assets/ia-commande-message.png',
      title: 'Collez le message, *c’est commandé*',
      caption: 'Un client vous écrit ? Collez son message : l’assistant remplit la commande à votre place.',
      zoom: [1, 1.5], focus: [195, 230], ring: { at: 1.4, x: 30, y: 72, w: 330, h: 174 }, tap: { at: 3.6, x: 142, y: 374 },
    },
    {
      type: 'phone', dur: 7.1, app: 'marchand', src: 'assets/ia-commande-remplie.png',
      title: 'Quartier, article, *frais de livraison*',
      caption: 'Numéro, nom, quartier, article et frais de livraison : vous vérifiez, c’est enregistré.',
      zoom: [1, 1.5], focus: [195, 420], ring: { at: 1.5, x: 14, y: 240, w: 362, h: 62 },
    },
    {
      type: 'phone', dur: 5.5, app: 'boutique', src: 'assets/bs-accueil.png',
      title: 'Votre boutique en ligne, *à votre image*',
      caption: 'Vous avez aussi votre boutique en ligne, avec votre nom, vos photos et vos produits.',
      zoom: [1, 1.3], focus: [195, 260], ring: { at: 1.2, x: 0, y: 94, w: 390, h: 218 },
    },
    {
      type: 'phone', dur: 4.8, app: 'boutique', src: 'assets/bs-recap.png',
      title: 'Le client commande, *même la nuit*',
      caption: 'Vos clients commandent eux-mêmes, jour et nuit, et paient à la livraison.',
      zoom: [1, 1.3], focus: [195, 400], ring: { at: 1, x: 24, y: 368, w: 342, h: 40 }, tap: { at: 3, x: 195, y: 540 },
    },
    {
      type: 'phone', dur: 6.4, app: 'marchand', src: 'assets/ia-produit-fiche.png',
      title: 'Une photo, *une fiche produit*',
      caption: 'Une photo suffit : l’assistant écrit le nom, la description, les couleurs et les tailles.',
      zoom: [1, 1.5], focus: [195, 640], ring: { at: 1.5, x: 30, y: 590, w: 330, h: 230 },
    },
    {
      type: 'phone', dur: 5.0, app: 'marchand', src: 'assets/ia-reponse.png',
      title: 'Vos réponses *déjà prêtes*',
      caption: 'Il prépare aussi vos réponses aux clients, avec vos vrais prix et votre stock.',
      zoom: [1, 1.55], focus: [195, 600], ring: { at: 1.5, x: 24, y: 488, w: 248, h: 222 },
    },
    {
      type: 'phone', dur: 4.8, app: 'marchand', src: 'assets/ia-reseaux.png',
      title: 'Vos publications *écrites pour vous*',
      caption: 'Et il écrit vos publications pour Facebook, Instagram et TikTok.',
      zoom: [1, 1.5], focus: [195, 330], ring: { at: 1.5, x: 30, y: 236, w: 330, h: 126 },
    },
    {
      type: 'phone', dur: 6.8, app: 'marchand', src: 'assets/marchand-entreprises-long.png',
      title: 'Un livreur fiable, *en un clic*',
      caption: 'Pour livrer, choisissez une entreprise de livraison d’après ses vrais résultats, et connectez-vous en un clic.',
      zoom: [1, 1.7], focus: [195, 690], ring: { at: 1.6, x: 30, y: 650, w: 330, h: 68 },
    },
    {
      type: 'phone', dur: 5.0, app: 'marchand', src: 'assets/marchand-commandes.png',
      title: 'Vos colis partent *en un toucher*',
      caption: 'Vos commandes partent chez le livreur en un toucher, sans rien ressaisir.',
      zoom: [1, 1.6], focus: [195, 560], tap: { at: 2.6, x: 243, y: 624 },
    },
    {
      type: 'phone', dur: 5.9, app: 'marchand', src: 'assets/marchand-accueil.png',
      title: 'Statuts et argent *reviennent seuls*',
      caption: 'Les statuts et l’argent reviennent tout seuls : vous savez ce qui est livré, et ce qu’on vous doit.',
      zoom: [1, 1.6], focus: [110, 425], ring: { at: 1.5, x: 16, y: 362, w: 173, h: 127 },
    },
    {
      type: 'phone', dur: 4.3, app: 'marchand', src: 'assets/vendre-reseaux.png',
      title: 'Ce que rapporte *chaque réseau*',
      caption: 'Vous voyez ce que rapporte chaque réseau, et ce qui se vend le mieux.',
      zoom: [1, 1.5], focus: [195, 300], ring: { at: 1.4, x: 30, y: 148, w: 330, h: 338 },
    },
    {
      type: 'phone', dur: 5.6, app: 'marchand', src: 'assets/vendre-conseils.png',
      title: 'Vos conseils *chaque matin*',
      caption: 'Et chaque matin, l’assistant vous dit quoi racheter, quoi relancer et quoi livrer.',
      zoom: [1, 1.5], focus: [195, 620], ring: { at: 1.4, x: 14, y: 574, w: 362, h: 176 },
    },
    {
      // Voix off : « Jibiat Marchand : toute votre boutique dans une seule application. Créez votre espace gratuitement sur jibiat-marchand.com. »
      type: 'end', dur: 8.1, brands: ['marchand'], tagline: 'Toute votre boutique, *une seule application.*', cta: 'Créer mon espace gratuitement',
    },
  ],
}
