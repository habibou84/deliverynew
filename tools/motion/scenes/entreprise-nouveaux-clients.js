// Entreprises de livraison : l'annuaire leur amène des e-commerçants de Jibiat Marchand
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.8, text: 'Et si les e-commerçants *venaient à vous* ?' },
    {
      type: 'phone', dur: 5, app: 'marchand', src: 'assets/marchand-entreprises-long.png',
      title: 'Vous apparaissez dans *l’annuaire*', sub: 'Les boutiques de Jibiat Marchand voient vos vrais résultats.',
      zoom: [1, 1.55], focus: [195, 640], ring: { at: 1.6, x: 16, y: 533, w: 358, h: 279 },
      pills: [{ at: 2.3, text: '94 % de réussite', x: 195, y: 500 }],
    },
    {
      type: 'phone', dur: 4.8, app: 'jibiat', src: 'assets/jibiat-admin-marchands.png',
      title: 'Elles demandent à *devenir clientes*', sub: 'La demande arrive dans votre espace. Un clic pour accepter.',
      zoom: [1, 1.6], focus: [195, 440], ring: { at: 1.5, x: 34, y: 364, w: 322, h: 170 }, tap: { at: 3.2, x: 174, y: 503 },
      pill: { at: 3.6, text: 'Nouveau client', x: 195, y: 300 },
    },
    {
      type: 'phone', dur: 3.8, app: 'jibiat', src: 'assets/jibiat-admin-courses.png',
      title: 'Et leurs colis arrivent *tout seuls*',
      zoom: [1, 1.55], focus: [195, 420], ring: { at: 1.4, x: 16, y: 324, w: 358, h: 192 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Plus de clients, *sans démarcher*', cta: 'Essai gratuit 30 jours' },
  ],
}
