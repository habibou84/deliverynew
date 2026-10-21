// E-commerçants : la boutique en ligne et la gestion dans Jibiat Marchand
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Vous vendez sur *WhatsApp* ?' },
    {
      type: 'phone', dur: 4.4, app: 'marchand', src: 'assets/marchand-boutique.png',
      title: 'Ayez aussi *votre boutique en ligne*', sub: 'Votre nom, vos produits, la commande en quelques secondes.',
      zoom: [1, 1.35], focus: [195, 320],
    },
    {
      type: 'phone', dur: 4.2, app: 'marchand', src: 'assets/marchand-commandes.png',
      title: 'Toutes vos commandes *au même endroit*', sub: 'Boutique, WhatsApp, Facebook, TikTok.',
      zoom: [1, 1.6], focus: [195, 370], ring: { at: 1.5, x: 16, y: 331, w: 358, h: 74 },
      pill: { at: 2.2, text: '40 000 F à encaisser', x: 195, y: 290 },
    },
    {
      type: 'phone', dur: 4, app: 'marchand', src: 'assets/marchand-catalogue.png',
      title: 'Votre stock *se tient tout seul*', sub: 'Chaque vente le met à jour.',
      zoom: [1, 1.6], focus: [195, 300],
    },
    {
      type: 'phone', dur: 4, app: 'marchand', src: 'assets/marchand-accueil.png',
      title: 'Vos ventes du jour, *d’un coup d’œil*',
      zoom: [1, 1.6], focus: [110, 425], ring: { at: 1.4, x: 16, y: 362, w: 173, h: 127 },
    },
    { type: 'end', dur: 3.8, brands: ['marchand'], tagline: 'Vendez partout. *Gérez tout ici.*', cta: 'Créer ma boutique gratuitement' },
  ],
}
