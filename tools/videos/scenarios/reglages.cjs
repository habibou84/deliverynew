// « Bien démarrer : les réglages » — 16:9 sur ordinateur (ce fichier) ou 9:16 sur téléphone (reglages-telephone.cjs)
// Avant : base de démonstration (php tools/videos/demo-data.php). Rien n'est enregistré, sauf la règle du planning.
// Voix off : tools/videos/voix/reglages (une phrase par étape, textes dans voix-off.md).
function build(device) {
  const phone = device === 'phone';
  const spec = {
    name: phone ? 'jibiat-bien-demarrer-les-reglages-telephone' : 'jibiat-bien-demarrer-les-reglages',
    format: phone ? 'vertical' : 'horizontal',
    intro: [['title', 'Bien démarrer : les réglages'], ['subtitle', 'Ce qu\'il faut régler pour que Jibiat travaille pour vous']],
    steps: {
      company: 'Étape 1', zones: 'Étape 2', couriers: 'Étape 3', merchants: 'Étape 4', whatsapp: 'Étape 5',
      orders: 'Étape 6', planning: 'Étape 7', autoPickup: 'Étape 8', finance: 'Étape 9', test: 'Étape 10',
    },
    captions: {
      guide: 'La page « Mise en route » vous guide pas à pas',
      menu: 'Tous les réglages sont aussi dans le menu « Réglages »',
      hub: 'Rangés par thème, avec une recherche',
      company: 'Nom, téléphone, adresse et logo de l\'entreprise',
      zones: 'Les zones que vous desservez',
      pricing: 'Vos tarifs de livraison, zone par zone',
      couriers: 'Vos livreurs, avec leurs zones et leur capacité',
      merchants: 'Vos e-commerçants, invités par un simple lien',
      whatsapp: 'Votre numéro WhatsApp pour les messages',
      orders: 'Validation des courses et heure limite du jour',
      alerts: 'Délai d\'alerte « sans livreur » et autres alertes',
      planning: 'Le planning : qui ramasse où, et son remplaçant',
      rule: 'Exemple : un livreur ramasse à Cocody tous les jours',
      autoPickup: 'Ramassage automatique selon le planning',
      autoDelivery: 'Livraison du jour même confiée automatiquement',
      trial: 'Conseil : commencez par « À l\'essai » pour vérifier',
      finance: 'La caisse : versements des livreurs, reversements',
      pay: 'La paie des livreurs : salaire, commission, primes',
      test: 'Pour finir, une course d\'essai pour tout vérifier',
    },
    voice: {
      dir: 'tools/videos/voix/reglages',
      intro: 'reglages-00.mp3',
      outro: 'reglages-99.mp3',
      marks: {
        guide: 'reglages-01.mp3', menu: 'reglages-18.mp3', hub: 'reglages-19.mp3', company: 'reglages-02.mp3', zones: 'reglages-03.mp3', pricing: 'reglages-04.mp3',
        couriers: 'reglages-05.mp3', merchants: 'reglages-06.mp3', whatsapp: 'reglages-07.mp3', orders: 'reglages-08.mp3',
        alerts: 'reglages-09.mp3', planning: 'reglages-10.mp3', rule: 'reglages-11.mp3', autoPickup: 'reglages-12.mp3',
        autoDelivery: 'reglages-13.mp3', trial: 'reglages-14.mp3', finance: 'reglages-15.mp3', pay: 'reglages-16.mp3',
        test: 'reglages-17.mp3',
      },
    },
  };

  const record = async (rec) => {
    const s = await rec.segment('reglages', {
      device, account: 'admin', path: '/admin/mise-en-route', address: phone ? null : 'livraison-express-ci.jibiat.com/admin',
    });
    const page = s.page;
    // Réglages modifiés sans les enregistrer : on quitte la page quand même (rien n'est enregistré)
    page.on('dialog', (d) => d.accept().catch(() => {}));
    // Changement de page sans rechargement (pas d'écran blanc), retour à l'écran entier
    const go = async (path) => {
      s.unfocus();
      const routed = await page.evaluate(async (p) => {
        const router = document.querySelector('#app')?.__vue_app__?.config.globalProperties.$router;
        if (!router) return false;
        await router.push(p);
        window.scrollTo(0, 0);
        return location.pathname === p;
      }, path);
      if (!routed) await page.goto(new URL(path, page.url()).href);
      await page.waitForLoadState('networkidle');
      await s.wait(700);
    };
    const scroll = async (dy, ms = 1200) => {
      s.unfocus();
      await page.evaluate((y) => window.scrollBy({ top: y, behavior: 'smooth' }), phone ? Math.round(dy * 1.4) : dy);
      await s.wait(ms);
    };
    // Montrer un élément : la caméra zoome dessus
    const show = async (locator, ms = 1500) => {
      await s.focusOn(locator);
      await s.wait(ms);
    };
    const toHeading = async (text) => {
      await page.evaluate((t) => [...document.querySelectorAll('h2')].find((h) => h.textContent.includes(t))?.scrollIntoView({ block: 'start' }), text);
      await s.wait(300);
    };

    // Mise en route
    s.mark('guide');
    await s.wait(700);
    await show('text=Étapes essentielles :', 1800);
    await show('text=Prochaine étape', 1600);
    await scroll(360, 1200);

    // Le menu « Réglages » : tous les réglages, rangés par thème
    s.mark('menu');
    s.unfocus();
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
    await s.wait(500);
    const settingsLink = page.locator('a[href="/admin/reglages"]').first();
    if (phone) {
      // Téléphone : le menu complet s'ouvre depuis la barre du bas
      await s.tap('button[aria-label="Menu complet"]');
      await s.wait(900);
    }
    await show(settingsLink, 1300);
    await s.tap(settingsLink);
    await page.waitForLoadState('networkidle');
    await s.wait(600);
    s.mark('hub');
    s.unfocus();
    await s.wait(900);
    for (const title of ['Entreprise et marque', 'Courses et livraison', 'Zones et tarifs', 'Alertes et rappels', 'Messages aux clients', 'E-commerçants', 'Équipe et paie']) {
      await show(page.getByText(title, { exact: true }).first(), 1250);
    }
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
    await s.wait(400);
    await show('input[placeholder^="Chercher un réglage"]', 1600);

    // 1. Entreprise
    s.mark('company');
    await go('/admin/reglages/entreprise');
    await show('section:has-text("Identité") input', 1600);
    await show('text=Envoyer un logo', 1600);

    // 2. Zones et tarifs
    s.mark('zones');
    await go('/admin/zones');
    await show(page.locator('div.p-3.text-sm', { hasText: 'Cocody' }).first(), 1800);
    await scroll(250, 900);
    s.mark('pricing');
    await go('/admin/tarifs');
    await show('table', 1800);
    await scroll(300, 1000);

    // 3. Livreurs
    s.mark('couriers');
    await go('/admin/livreurs');
    await show('text=Awa Livreuse', 1500);
    await show('text=Zones :', 1600);

    // 4. E-commerçants
    s.mark('merchants');
    await go('/admin/marchands');
    await show('text=Boutique Chic Abidjan', 1800);
    await scroll(250, 1000);

    // 5. WhatsApp
    s.mark('whatsapp');
    await go('/admin/whatsapp');
    await show('h2:has-text("Numéro WhatsApp")', 1600);
    await show('button:has-text("Enregistrer et vérifier")', 1600);

    // 6. Courses, heure limite, alertes
    s.mark('orders');
    await go('/admin/reglages/courses');
    await show('text=Automatique avec règles', 1800);
    await show('#cutoff', 1800);
    s.mark('alerts');
    await go('/admin/reglages/alertes');
    await show('main h2', 1800);
    await scroll(300, 1200);

    // 7. Planning des livreurs : une règle de ramassage
    s.mark('planning');
    await go('/admin/planning');
    await show('text=Ramassage automatique désactivé', 1400);
    await show('text=Clients sans ramasseur ce jour-là', 1600);
    s.mark('rule');
    await s.tap('button:has-text("Nouvelle règle")');
    await s.wait(800);
    const modal = page.locator('form:has(#r-courier)');
    await show(modal.locator('#r-courier'), 400);
    await modal.locator('#r-courier').selectOption({ index: 1 });
    await s.wait(700);
    await show(modal.locator('#r-backup'), 400);
    await modal.locator('#r-backup').selectOption({ index: 2 }).catch(() => {});
    await s.wait(700);
    await s.tap(modal.locator('label:has-text("Cocody") input').first());
    await s.wait(700);
    await s.tap(modal.locator('button:has-text("Tous les jours")'));
    await s.wait(600);
    await s.tap(modal.locator('button:has-text("Enregistrer")'));
    await page.waitForLoadState('networkidle');
    await s.wait(500);
    s.unfocus();
    await show('text=Règles', 1800);

    // 8. Ramassage et livraison automatiques
    await go('/admin/reglages/courses');
    await toHeading('Ramassage automatique');
    s.mark('autoPickup');
    await s.wait(500);
    await s.tap('[aria-label="Ramassage automatique"] label:has-text("Active")');
    await s.wait(1500);
    s.mark('autoDelivery');
    await s.tap('[aria-label="Livraison automatique"] label:has-text("Active")');
    await s.wait(900);
    await show('#max-cod', 1300);
    s.mark('trial');
    await s.tap('[aria-label="Ramassage automatique"] label:has-text("À l\'essai")');
    await s.wait(800);
    await s.tap('[aria-label="Livraison automatique"] label:has-text("À l\'essai")');
    await s.wait(1600);

    // 9. Caisse et paie
    s.mark('finance');
    await go('/admin/caisse');
    await show('text=Recevoir le versement', 1800);
    await scroll(300, 1000);
    s.mark('pay');
    await go('/admin/paie-livreurs');
    await show('h2:has-text("Salaire et période de paie")', 1800);
    await scroll(300, 1000);

    // 10. Course d'essai
    s.mark('test');
    await go('/admin/mise-en-route');
    await show('button:has-text("course d\'essai")', 3000);
    await s.stop();
  };

  return { spec, record };
}

module.exports = build('desktop');
module.exports.build = build;
