// E-commerçantes clientes d'une agence Jibiat : « Comment ça marche » du live, montré avant « Préparer mon live »
// dans l'application e-commerçant. L'agence de livraison est mise en avant, Jibiat reste discret (« Propulsé par »).
// Voix off « Tony » dans voix/live/l01.mp3 à l13.mp3 (une phrase par plan) ; chaque « caption » est son texte.
window.SCENE = {
  brand: 'agence',
  captionsLow: true,
  shots: [
    {
      type: 'hook', dur: 7.7, text: 'Pendant un live, *tout va trop vite*',
      caption: 'Pendant un live, les messages arrivent de partout. Vous répondez, vous notez à la main… et vous perdez des commandes.',
    },
    {
      type: 'hook', dur: 5.1, text: 'Et le soir, *tout recopier*…',
      caption: 'Et le soir, il faut tout recopier : chaque commande, chaque livraison.',
    },
    {
      type: 'hook', dur: 5.5, text: 'Avec votre agence, *le live travaille pour vous*',
      caption: 'Avec votre agence de livraison, c’est fini. Voici comment ça marche, en 4 étapes.',
    },
    {
      type: 'phone', dur: 4.8, app: 'live', src: 'assets/live-produits.png',
      title: '1. Choisissez *les produits du live*',
      caption: 'Un : dans votre catalogue, cochez les produits que vous allez montrer.',
      zoom: [1, 1.45], focus: [195, 330], ring: { at: 1.4, x: 16, y: 205, w: 358, h: 205 },
    },
    {
      type: 'phone', dur: 8.1, app: 'live', src: 'assets/live-carte.png',
      title: '2. La photo à épingler *est prête*',
      caption: 'Deux : la photo à épingler est préparée toute seule, avec un code pour chaque produit : A1, A2, A3…',
      zoom: [1, 1.4], focus: [195, 480], ring: { at: 2.2, x: 186, y: 350, w: 162, h: 208 },
    },
    {
      type: 'phone', dur: 7.5, app: 'whatsapp', src: 'assets/live-whatsapp-1.png',
      title: '3. La cliente envoie *le code*',
      caption: 'Trois : pendant le live, la cliente envoie simplement le code au WhatsApp de votre agence. Par exemple : A2.',
      zoom: [1, 1.6], focus: [260, 200], ring: { at: 3.4, x: 304, y: 136, w: 80, h: 58 },
    },
    {
      type: 'phone', dur: 6.8, app: 'whatsapp', src: 'assets/live-whatsapp-4.png',
      title: 'La réponse *part toute seule*',
      caption: 'La réponse part toute seule. Elle donne son nom et son quartier, et c’est noté. Vous n’avez rien à répondre.',
      zoom: [1, 1.3], focus: [195, 360], ring: { at: 2.6, x: 8, y: 408, w: 300, h: 164 },
    },
    {
      type: 'phone', dur: 8.1, app: 'live', src: 'assets/live-ecran.png',
      title: '4. Votre agence *reçoit les commandes*',
      caption: 'Quatre : votre agence reçoit les commandes directement, avec le nom, le numéro et le quartier. Vous n’enregistrez rien.',
      zoom: [1, 1.4], focus: [195, 220], ring: { at: 2.2, x: 16, y: 253, w: 358, h: 100 },
    },
    {
      type: 'phone', dur: 4.5, app: 'live', src: 'assets/live-commandes.png',
      title: 'Chaque commande *arrive toute seule*',
      caption: 'Chaque commande arrive toute seule, pendant que vous restez devant la caméra.',
      zoom: [1, 1.3], focus: [195, 330], ring: { at: 1.4, x: 16, y: 198, w: 358, h: 118 },
    },
    {
      type: 'hook', dur: 6.6, size: 0.6, text: '*0* message à répondre\n*0* commande à enregistrer\n*0* commande oubliée',
      caption: 'Résultat : zéro message à répondre. Zéro commande à enregistrer. Zéro commande oubliée.',
    },
    {
      type: 'phone', dur: 6.5, app: 'live', src: 'assets/live-ecran.png',
      title: 'Le stock *à jour en direct*',
      caption: 'Et vous voyez le stock en direct : « il en reste 2 », « épuisé ». Vous le dites au bon moment.',
      zoom: [1, 1.4], focus: [195, 630], ring: { at: 1.8, x: 16, y: 538, w: 358, h: 194 },
    },
    {
      type: 'phone', dur: 6.4, app: 'live', src: 'assets/live-bilan.png',
      title: 'Le bilan *de votre live*',
      caption: 'Après le live, votre bilan est prêt : ce que vous avez vendu, et votre meilleur produit.',
      zoom: [1, 1.35], focus: [195, 400], ring: { at: 1.8, x: 16, y: 432, w: 358, h: 214 },
    },
    {
      type: 'end', dur: 6.2, brands: ['agence'], tagline: 'Vous vendez. *Votre agence s’occupe du reste.*', cta: 'Préparez votre live',
      caption: 'Vous vendez, votre agence s’occupe du reste. Préparez votre premier live dès maintenant !',
    },
  ],
}
