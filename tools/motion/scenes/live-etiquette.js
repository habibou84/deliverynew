// E-commerçantes clientes d'une agence Jibiat : l'étiquette du live (télécharger, publier et épingler, lien dans la
// bio, numéro en commentaire épinglé). Montrée dans « Préparer mon live », étape « L'étiquette ».
// Voix off « Tony » dans voix/etiquette/e01.mp3 à e08.mp3 (une phrase par plan) ; chaque « caption » est son texte.
// Images : node tools/motion/capture-etiquette.cjs (application) et props/live/reseau.html (le compte).
window.SCENE = {
  brand: 'agence',
  captionsLow: true,
  shots: [
    {
      type: 'hook', dur: 6.4, text: 'L’étiquette : *vos produits et leurs codes*',
      caption: 'Avant votre live, préparez l’étiquette : la photo de vos produits, avec un code pour chacun.',
    },
    {
      type: 'phone', dur: 7.7, app: 'live', src: 'assets/etiquette-fenetre.png',
      title: '1. Téléchargez *l’étiquette*',
      caption: 'Un : choisissez TikTok ou Facebook, puis touchez « Télécharger l’étiquette ». Elle arrive dans votre galerie.',
      zoom: [1, 1.3], focus: [195, 380], ring: { at: 1.6, x: 16, y: 287, w: 358, h: 46 },
    },
    {
      type: 'phone', dur: 6.0, app: 'reseau', src: 'assets/etiquette-profil.png',
      title: '2. Publiez-la et *épinglez-la*',
      caption: 'Deux : publiez l’étiquette sur votre compte, puis épinglez-la en haut de votre profil.',
      zoom: [1, 1.7], focus: [70, 560], ring: { at: 1.6, x: 2, y: 492, w: 128, h: 172 },
    },
    {
      type: 'phone', dur: 6.9, app: 'reseau', src: 'assets/etiquette-bio.png',
      title: '3. Le lien du live *dans votre bio*',
      caption: 'Trois : copiez le lien du live et mettez-le dans votre bio. Vos clientes peuvent aussi commander par ce lien.',
      zoom: [1, 1.5], focus: [195, 420], ring: { at: 1.8, x: 34, y: 436, w: 322, h: 40 },
    },
    {
      type: 'phone', dur: 6.3, app: 'reseau', src: 'assets/etiquette-live.png',
      title: '4. Votre numéro *en commentaire épinglé*',
      caption: 'Quatre : pendant le live, écrivez votre numéro WhatsApp en commentaire, et épinglez-le.',
      zoom: [1, 1.45], focus: [170, 700], ring: { at: 1.8, x: 10, y: 692, w: 322, h: 80 },
    },
    {
      type: 'phone', dur: 8.1, app: 'whatsapp', src: 'assets/live-whatsapp-1.png',
      title: 'La cliente envoie *le code*',
      caption: 'La cliente regarde l’étiquette et envoie le code sur votre WhatsApp, par exemple A2. La commande part à votre agence.',
      zoom: [1, 1.6], focus: [260, 200], ring: { at: 3.4, x: 304, y: 136, w: 80, h: 58 },
    },
    {
      type: 'hook', dur: 5.3, size: 0.8, text: 'Un produit épuisé ?\n*Téléchargez l’étiquette à jour*',
      caption: 'Un produit est épuisé ? Téléchargez l’étiquette à jour : il apparaît barré.',
    },
    {
      type: 'end', dur: 5.6, brands: ['agence'], tagline: 'Vous montrez. *Vos clientes commandent.*', cta: 'Touchez « C’est fait »',
      caption: 'Ensuite, dans l’application, touchez « C’est fait », et commencez votre live !',
    },
  ],
}
