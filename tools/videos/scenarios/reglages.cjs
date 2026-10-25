// « Bien démarrer : les réglages » — 16:9, administration sur ordinateur
// Avant : base de démonstration (php tools/videos/demo-data.php). Rien n'est enregistré, sauf la règle du planning.
exports.spec = {
  name: 'jibiat-bien-demarrer-les-reglages',
  format: 'horizontal',
  intro: [['title', 'Bien démarrer : les réglages'], ['subtitle', 'Ce qu\'il faut régler pour que Jibiat travaille pour vous']],
  steps: {
    company: 'Étape 1', zones: 'Étape 2', couriers: 'Étape 3', merchants: 'Étape 4', whatsapp: 'Étape 5',
    orders: 'Étape 6', planning: 'Étape 7', autoPickup: 'Étape 8', finance: 'Étape 9', test: 'Étape 10',
  },
  captions: {
    guide: 'La page « Mise en route » vous guide pas à pas',
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
};

exports.record = async (rec) => {
  const s = await rec.segment('reglages', { device: 'desktop', account: 'admin', path: '/admin/mise-en-route', address: 'livraison-express-ci.jibiat.com/admin' });
  const page = s.page;
  const go = async (path) => {
    await page.goto(new URL(path, page.url()).href);
    await page.waitForLoadState('networkidle');
    await s.wait(700);
  };
  const scroll = async (dy, ms = 1200) => {
    await page.mouse.wheel(0, dy);
    await s.wait(ms);
  };
  const hover = async (x, y, ms = 900) => {
    await page.mouse.move(x, y, { steps: 30 });
    await s.wait(ms);
  };
  const point = async (locator, ms = 900) => {
    const target = page.locator(locator).first();
    await target.scrollIntoViewIfNeeded().catch(() => {});
    const box = await target.boundingBox().catch(() => null);
    if (box) await hover(box.x + Math.min(box.width / 2, 160), box.y + box.height / 2, ms);
    else await s.wait(ms);
  };

  // Mise en route
  s.mark('guide');
  await s.wait(800);
  await hover(660, 200, 800);
  await hover(420, 283, 1200);
  await scroll(320, 1400);
  await scroll(-320, 600);

  // 1. Entreprise
  s.mark('company');
  await go('/admin/reglages/entreprise');
  await point('section:has-text("Identité") input', 900);
  await scroll(320, 1600);

  // 2. Zones et tarifs
  s.mark('zones');
  await go('/admin/zones');
  await hover(640, 300, 1200);
  await scroll(250, 1400);
  s.mark('pricing');
  await go('/admin/tarifs');
  await hover(600, 280, 1200);
  await scroll(300, 1600);

  // 3. Livreurs
  s.mark('couriers');
  await go('/admin/livreurs');
  await hover(640, 260, 1000);
  await scroll(250, 1600);

  // 4. E-commerçants
  s.mark('merchants');
  await go('/admin/marchands');
  await hover(640, 260, 1000);
  await scroll(250, 1600);

  // 5. WhatsApp
  s.mark('whatsapp');
  await go('/admin/whatsapp');
  await point('h2:has-text("Numéro WhatsApp")', 1200);
  await scroll(250, 1600);

  // 6. Courses, heure limite, alertes
  s.mark('orders');
  await go('/admin/reglages/courses');
  await point('text=Automatique avec règles', 1200);
  await page.evaluate(() => document.querySelector('#cutoff')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
  await s.wait(900);
  await point('#cutoff', 1400);
  s.mark('alerts');
  await go('/admin/reglages/alertes');
  await hover(600, 260, 1200);
  await scroll(300, 1600);

  // 7. Planning des livreurs : une règle de ramassage
  s.mark('planning');
  await go('/admin/planning');
  await hover(620, 165, 1000);
  await point('text=Clients sans ramasseur ce jour-là', 1400);
  s.mark('rule');
  await s.tap('button:has-text("Nouvelle règle")');
  await s.wait(800);
  const modal = page.locator('form:has(#r-courier)');
  await modal.locator('#r-courier').selectOption({ index: 1 });
  await s.wait(700);
  await modal.locator('#r-backup').selectOption({ index: 2 }).catch(() => {});
  await s.wait(700);
  const cocody = modal.locator('label:has-text("Cocody") input').first();
  await s.tap(cocody);
  await s.wait(700);
  await s.tap(modal.locator('button:has-text("Tous les jours")'));
  await s.wait(600);
  await s.tap(modal.locator('button:has-text("Enregistrer")'));
  await page.waitForLoadState('networkidle');
  await s.wait(1600);

  // 8. Ramassage et livraison automatiques
  await go('/admin/reglages/courses');
  await page.evaluate(() => [...document.querySelectorAll('h2')].find((h) => h.textContent.includes('Ramassage automatique'))?.scrollIntoView({ block: 'start' }));
  await s.wait(300);
  s.mark('autoPickup');
  await s.wait(900);
  await s.tap('[aria-label="Ramassage automatique"] label:has-text("Active")');
  await s.wait(1400);
  s.mark('autoDelivery');
  await s.tap('[aria-label="Livraison automatique"] label:has-text("Active")');
  await s.wait(900);
  await point('#max-cod', 1400);
  s.mark('trial');
  await s.tap('[aria-label="Ramassage automatique"] label:has-text("À l\'essai")');
  await s.wait(500);
  await s.tap('[aria-label="Livraison automatique"] label:has-text("À l\'essai")');
  await s.wait(1600);

  // 9. Caisse et paie
  s.mark('finance');
  await go('/admin/caisse');
  await hover(640, 260, 1200);
  await scroll(250, 1400);
  s.mark('pay');
  await go('/admin/paie-livreurs');
  await hover(640, 260, 1200);
  await scroll(300, 1600);

  // 10. Course d'essai
  s.mark('test');
  await go('/admin/mise-en-route');
  await page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.includes('course d\'essai'))?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
  await s.wait(1200);
  await point('button:has-text("course d\'essai")', 2600);
  await s.stop();
};
