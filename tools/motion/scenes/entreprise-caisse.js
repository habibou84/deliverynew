// Entreprises de livraison : la caisse et les chiffres, sans cahier ni calculatrice
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 3, text: 'Vos livreurs ont encaissé *combien* aujourd’hui ?' },
    {
      type: 'phone', dur: 4.6, app: 'jibiat', src: 'assets/jibiat-admin-caisse.png',
      title: 'Jibiat le sait, *au franc près*', sub: 'Chaque course encaissée est comptée, livreur par livreur.',
      zoom: [1, 1.8], focus: [195, 225], ring: { at: 1.7, x: 16, y: 185, w: 358, h: 82 },
      pill: { at: 2.4, text: 'Calculé tout seul', x: 195, y: 320 },
    },
    {
      type: 'phone', dur: 4.6, app: 'jibiat', src: 'assets/jibiat-admin-caisse.png',
      title: 'Le livreur verse, *vous validez*', sub: 'Le point de caisse prend deux minutes. Les écarts sautent aux yeux.',
      zoom: [1, 1.7], focus: [275, 640], ring: { at: 1.4, x: 16, y: 488, w: 358, h: 240 }, tap: { at: 3.0, x: 275, y: 700 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/jibiat-admin-statistiques.png',
      title: 'Et chaque soir, *vos vrais chiffres*',
      zoom: [1, 1.5], focus: [195, 520], ring: { at: 1.4, x: 16, y: 455, w: 358, h: 120 },
      pills: [{ at: 2.0, text: '94 % livrées', x: 110, y: 600 }, { at: 2.5, text: '252 colis ce mois', x: 280, y: 410 }],
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Toute votre entreprise de livraison *dans une seule application*', cta: 'Essai gratuit 30 jours' },
  ],
}
