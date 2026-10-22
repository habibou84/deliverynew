// Entreprises de livraison : présentation générale de Jibiat (environ 2 minutes, sous-titrée ; voix off à ajouter)
// Chaque « caption » est le texte de la voix off du plan (sous-titre) ; pas de pastilles, pour ne pas les cacher.
window.SCENE = {
  brand: 'jibiat',
  shots: [
    // 1. Ouverture
    {
      type: 'hook', dur: 6.4, text: 'Des courses *dans un cahier* ?',
      caption: 'Vous dirigez une entreprise de livraison ? Les courses notées dans un cahier, les appels toute la journée…',
    },
    {
      type: 'hook', dur: 4.2, text: 'L’argent du soir *à recompter* ?',
      caption: '… et l’argent du soir qu’on recompte, livreur par livreur.',
    },
    {
      type: 'hook', dur: 4.6, text: '*Jibiat* met tout dans une seule application.',
      caption: 'Jibiat met tout ça dans une seule application. Voyons comment.',
    },

    // 2. Trouver de nouveaux clients
    {
      type: 'phone', dur: 6.9, app: 'annuaire', src: 'assets/j-annuaire.png',
      title: 'Les e-commerçants *vous trouvent*',
      caption: 'Votre entreprise apparaît dans l’annuaire Jibiat, avec vos vrais résultats : les e-commerçants vous trouvent.',
      zoom: [1, 1.45], focus: [195, 200], ring: { at: 1.4, x: 24, y: 40, w: 342, h: 330 },
    },
    {
      type: 'phone', dur: 6.1, app: 'marchand', src: 'assets/marchand-entreprises-long.png',
      title: 'Ils se connectent *en un clic*',
      caption: 'Depuis Jibiat Marchand, ils se connectent à vous en un clic. Pas de clé, pas de technicien.',
      zoom: [1, 1.6], focus: [195, 700], tap: { at: 2.6, x: 195, y: 774 },
    },
    {
      type: 'phone', dur: 4.7, app: 'jibiat', src: 'assets/j-demandes.png',
      title: 'Un toucher *pour accepter*',
      caption: 'Vous recevez leur demande, et un toucher suffit pour les accepter.',
      zoom: [1, 1.5], focus: [195, 430], ring: { at: 1.3, x: 30, y: 360, w: 330, h: 178 }, tap: { at: 3, x: 174, y: 503 },
    },

    // 3. Les courses arrivent toutes seules
    {
      type: 'phone', dur: 5.9, app: 'client', src: 'assets/j-ecommercant-message.png',
      title: 'Un message collé, *la course est prête*',
      caption: 'Vos e-commerçants créent leurs courses eux-mêmes : un message WhatsApp collé, et la course est prête.',
      zoom: [1, 1.5], focus: [195, 210], ring: { at: 1.3, x: 28, y: 113, w: 334, h: 134 }, tap: { at: 3.6, x: 195, y: 275 },
    },
    {
      type: 'phone', dur: 5.9, app: 'jibiat', src: 'assets/j-integrations.png',
      title: 'Leur boutique *envoie les courses*',
      caption: 'Leur boutique en ligne vous envoie les commandes toute seule. Fini les appels pour noter une adresse.',
      zoom: [1, 1.45], focus: [195, 480], ring: { at: 1.3, x: 14, y: 352, w: 362, h: 230 },
    },
    {
      type: 'phone', dur: 4.5, app: 'jibiat', src: 'assets/j-import.png',
      title: 'Un fichier Excel ? *Cent courses d’un coup*',
      caption: 'Et pour les gros volumes, un fichier Excel : cent courses d’un coup.',
      zoom: [1, 1.45], focus: [195, 250], ring: { at: 1.3, x: 14, y: 130, w: 362, h: 200 },
    },

    // 4. L'assignation
    {
      type: 'phone', dur: 6.7, app: 'jibiat', src: 'assets/j-planning.png',
      title: 'Une règle par livreur, *avec son remplaçant*',
      caption: 'Votre planning décide qui ramasse et qui livre, commune par commune, avec un remplaçant pour chacun.',
      zoom: [1, 1.5], focus: [195, 640], ring: { at: 1.4, x: 14, y: 538, w: 362, h: 302 },
    },
    {
      type: 'phone', dur: 6.1, app: 'jibiat', src: 'assets/j-course-auto.png',
      title: 'La course part *toute seule*',
      caption: 'Chaque course est confiée au bon livreur, automatiquement. Et vous gardez la main à tout moment.',
      zoom: [1, 1.55], focus: [195, 330], ring: { at: 1.4, x: 46, y: 282, w: 316, h: 74 },
    },

    // 5. Le livreur et le destinataire
    {
      type: 'phone', dur: 6.2, app: 'livreur', src: 'assets/j-livreur.png',
      title: 'Ses missions, *dans l’ordre*',
      caption: 'Le livreur voit ses missions sur son téléphone, dans l’ordre. L’application marche même sans réseau.',
      zoom: [1, 1.4], focus: [195, 260], ring: { at: 1.3, x: 14, y: 240, w: 362, h: 160 },
    },
    {
      type: 'phone', dur: 6.1, app: 'suivi', src: 'assets/j-suivi.png',
      title: 'Le destinataire *suit son colis*',
      caption: 'Le destinataire est prévenu sur WhatsApp, suit son colis, et donne son code à la livraison.',
      zoom: [1, 1.45], focus: [195, 400], ring: { at: 1.3, x: 14, y: 210, w: 362, h: 380 },
    },

    // 6. Le suivi et les alertes
    {
      type: 'phone', dur: 6.1, app: 'jibiat', src: 'assets/j-course-route.png',
      title: 'Qui ramasse, qui livre, *où il en est*',
      caption: 'Au bureau, vous suivez chaque course en temps réel : qui ramasse, qui livre, où il en est.',
      zoom: [1, 1.45], focus: [195, 290], ring: { at: 1.3, x: 14, y: 148, w: 362, h: 244 },
    },
    {
      type: 'phone', dur: 6.5, app: 'jibiat', src: 'assets/j-alertes.png',
      title: 'Un problème ? *Vous êtes prévenu*',
      caption: 'Un ramassage en retard, un livreur injoignable : Jibiat vous alerte avant que ça coûte.',
      zoom: [1, 1.5], focus: [195, 380], ring: { at: 1.3, x: 18, y: 268, w: 354, h: 188 },
    },
    {
      type: 'phone', dur: 6.4, app: 'jibiat', src: 'assets/j-courses.png',
      title: 'Les échecs, *à décider d’un toucher*',
      caption: 'Un colis refusé ? Relivrer, retourner ou reporter : vous décidez d’un toucher.',
      zoom: [1, 1.5], focus: [195, 450], ring: { at: 1.3, x: 14, y: 322, w: 362, h: 262 }, tap: { at: 3.2, x: 59, y: 515 },
    },

    // 7. La caisse
    {
      type: 'phone', dur: 5.9, app: 'jibiat', src: 'assets/j-caisse.png',
      title: 'L’argent de chaque livreur, *au franc près*',
      caption: 'Le soir, l’argent que chaque livreur doit verser est déjà calculé, au franc près.',
      zoom: [1, 1.45], focus: [195, 400], ring: { at: 1.3, x: 14, y: 383, w: 362, h: 226 },
    },
    {
      type: 'phone', dur: 5.4, app: 'jibiat', src: 'assets/j-caisse-versement.png',
      title: 'Il verse, vous cochez, *c’est validé*',
      caption: 'Il verse, vous vérifiez, vous validez. Le point de caisse prend deux minutes.',
      zoom: [1, 1.45], focus: [195, 470], ring: { at: 1.3, x: 14, y: 316, w: 362, h: 248 },
      tap: { at: 3.4, x: 195, y: 700 },
    },
    {
      type: 'phone', dur: 6.8, app: 'ecommercant', src: 'assets/jibiat-marchand-argent.png',
      title: 'Payé par *Wave*, avec son relevé',
      caption: 'L’argent des e-commerçants est prêt : payé par Wave ou Orange Money, avec un relevé course par course.',
      zoom: [1, 1.6], focus: [195, 200], ring: { at: 1.5, x: 16, y: 72, w: 358, h: 244 },
    },

    // 8. Les chiffres
    {
      type: 'phone', dur: 5.3, app: 'jibiat', src: 'assets/j-stats.png',
      title: 'Vos *vrais chiffres*',
      caption: 'Vos chiffres du jour et du mois : courses, réussite, chiffre d’affaires.',
      zoom: [1, 1.45], focus: [195, 450], ring: { at: 1.3, x: 14, y: 316, w: 362, h: 420 },
    },
    {
      type: 'phone', dur: 7.0, app: 'jibiat', src: 'assets/j-stats-2.png',
      title: 'Par livreur, par client, *par zone*',
      caption: 'Par livreur, par client, par zone. Et chaque lundi, le rapport de la semaine arrive sur WhatsApp.',
      zoom: [1, 1.35], focus: [195, 420],
    },

    // Fin
    {
      // Voix off : « Jibiat : toute votre entreprise de livraison dans une seule application. Essayez-le gratuitement sur jibiat.com. »
      type: 'end', dur: 7.6, brands: ['jibiat'], tagline: 'Toute votre livraison, *une seule application.*', cta: 'Essai gratuit · jibiat.com',
    },
  ],
}
