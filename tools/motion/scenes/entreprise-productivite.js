// Entreprises de livraison : la productivité de Jibiat en un coup d'œil (vidéo principale de jibiat.com)
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.8, text: 'Votre entreprise de livraison *tourne toute seule*' },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-planning.png',
      title: 'Les courses partent *seules au bon livreur*', sub: 'Le planning confie chaque ramassage et chaque livraison.',
      zoom: [1, 1.45], focus: [195, 640], ring: { at: 1.3, x: 14, y: 538, w: 362, h: 302 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-alertes.png',
      title: 'Un problème ? *Vous êtes prévenu*', sub: 'Ramassage en retard, livreur injoignable, colis en échec.',
      zoom: [1, 1.5], focus: [195, 380], ring: { at: 1.3, x: 18, y: 268, w: 354, h: 188 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-caisse-versement.png',
      title: 'Le point de caisse *en deux minutes*', sub: 'Chaque encaissement coché, le montant attendu calculé.',
      zoom: [1, 1.45], focus: [195, 470], ring: { at: 1.3, x: 14, y: 316, w: 362, h: 248 },
      pill: { at: 2.3, text: '542 500 F attendus', x: 195, y: 640 },
    },
    {
      type: 'phone', dur: 4, app: 'suivi', src: 'assets/j-suivi.png',
      title: 'Chaque colis *suivi en direct*', sub: 'Vos clients et leurs destinataires n’appellent plus.',
      zoom: [1, 1.45], focus: [195, 400], ring: { at: 1.3, x: 14, y: 210, w: 362, h: 380 },
    },
    { type: 'end', dur: 4, brands: ['jibiat'], tagline: 'Moins d’appels. *Plus de colis livrés.*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
