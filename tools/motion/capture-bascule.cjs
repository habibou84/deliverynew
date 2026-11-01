/**
 * Captures de la vidéo « Passer à Jibiat : le ménage, puis le jour de la bascule » (scène bascule).
 * Les vrais écrans de Réglages › Reprise de l'ancienne application, sur ordinateur (1280 × 800), remplis avec des
 * réponses de démonstration (une reprise terminée, sans ancienne application branchée) : seules les adresses
 * /company/legacy-* sont simulées dans le navigateur, le reste vient de la base locale de démonstration.
 *   JIBIAT=http://127.0.0.1:8001 node tools/motion/capture-bascule.cjs [identifiants…]
 * Chaque vue donne assets/bascule-<identifiant>.png et la position des éléments à montrer
 * (assets/positions-bascule.json, coordonnées de l'écran 1280 × 800).
 */
const fs = require('fs')
const path = require('path')
const { chromium } = require(process.env.PLAYWRIGHT_MODULES ? path.join(process.env.PLAYWRIGHT_MODULES, 'playwright') : 'playwright')

const JIBIAT = process.env.JIBIAT || 'http://127.0.0.1:8001'
const OUT = path.join(__dirname, 'assets')
const ADMIN = { phone: '0700000001', password: 'password' }

// Une entreprise dont la reprise est faite ; bascule prévue lundi 2 novembre à 6 h
const SWITCH_AT = '2026-11-02T06:00:00Z'
const ago = (s) => new Date(Date.now() - s * 1000).toISOString()
const COURIERS = [
  ['Moussa Coulibaly', 412500, 61, '2026-03-14'],
  ['Yacouba Ouattara', 287000, 44, '2026-04-02'],
  ['Serge Kouassi', 198500, 29, '2026-05-21'],
  ['Koffi Yao', 156000, 23, '2026-06-09'],
  ['Ibrahim Traoré', 94500, 15, '2026-07-30'],
  ['Awa Konaté', 61000, 9, '2026-08-17'],
]
const MERCHANTS = [
  ['Boutique Chic', 186500], ['Saveurs d\'Ivoire', 142000], ['Tech Store', 318000], ['Beauté Divine', 97500],
  ['Mode Abobo', 64000], ['Awa Mode', 121500], ['Chez Mariam', 43000], ['Bijoux Riviera', 75500],
]

const state = { switchStarted: false, balancesValidated: false }

function mocks() {
  const switchState = () => state.switchStarted
    ? { key_active: true, started: true, switch_at: SWITCH_AT, ends_at: '2026-12-02T06:00:00Z', switched: { merchants: 46, couriers: 17 } }
    : { key_active: true, started: false, switch_at: SWITCH_AT, ends_at: null, switched: { merchants: 0, couriers: 0 } }
  return {
    'company/legacy-overview': () => ({
      orders: { imported_at: '2026-10-20T10:12:00Z', enabled: true, last_at: ago(40), last_error: null, job: 'done', count: 2847 },
      switch: switchState(),
      connect: { orders: true, accounts: true, switch: true },
      accounts: { used_at: '2026-10-19T15:30:00Z' },
      todo: { deleted: 3, on_the_way: 14, on_the_way_days: 30, zones: 0, prices: 0, balances: true, cash: true, recheck_running: false },
    }),
    'company/legacy-orders/balances': () => {
      const merchants = MERCHANTS.map(([name, amount], i) => ({ legacy_id: 100 + i, name, old: amount, jibiat: amount, gap: false }))
      const couriers = COURIERS.map(([name], i) => ({ legacy_id: 200 + i, name, old: [18500, 0, 12000, 0, 7500, 0][i], jibiat: [18500, 0, 12000, 0, 7500, 0][i], gap: false }))
      const sum = (rows) => rows.reduce((s, r) => s + r.old, 0)
      return { merchants, couriers, totals: { merchants_old: sum(merchants), merchants_jibiat: sum(merchants), couriers_old: sum(couriers), couriers_jibiat: sum(couriers), merchants_gaps: 0, couriers_gaps: 0 } }
    },
    'company/legacy-orders': () => ({
      url: 'https://ancienne.express-ci.com', has_key: true, months: 12, imported_at: '2026-10-20T10:12:00Z', orders: 2847,
      balances_validated_at: state.balancesValidated ? ago(5) : null, enabled: true, last_at: ago(40), last_error: null,
      job: { status: 'done', report: null }, today: { created: 12, updated: 41 }, conflicts: 0, switch_at: SWITCH_AT,
    }),
    'company/legacy-recheck': () => ({ since: '2026-10-20', last: null }),
    'company/legacy-cash': () => {
      const couriers = COURIERS.map(([name, total, count, oldest], i) => ({ courier_id: i + 1, name, total, count, oldest, since_total: 0, since_count: 0 }))
      return { switch_date: '2026-11-02', cutoff: '2026-11-02', couriers, totals: { total: couriers.reduce((s, c) => s + c.total, 0), since_total: 0 }, last: null }
    },
    'company/legacy-purge/on-the-way': () => ({
      couriers: [
        { courier_id: 1, name: 'Moussa Coulibaly', count: 5, oldest: '2026-08-04' },
        { courier_id: 2, name: 'Yacouba Ouattara', count: 4, oldest: '2026-08-21' },
        { courier_id: 3, name: 'Serge Kouassi', count: 3, oldest: '2026-09-02' },
        { courier_id: 5, name: 'Ibrahim Traoré', count: 2, oldest: '2026-09-15' },
      ],
      orders: [
        [48211, 'Aminata Bamba', 'Boutique Chic', '2026-08-04', 15000], [48390, 'Jean-Marc Kouadio', 'Tech Store', '2026-08-11', 42000],
        [48517, 'Fatou Diallo', 'Beauté Divine', '2026-08-19', 8500], [48702, 'Paul Aka', 'Mode Abobo', '2026-09-01', 12000],
        [48866, 'Mariam Touré', 'Saveurs d\'Ivoire', '2026-09-12', 6500],
      ].map(([legacy, recipient, merchant, day, cod], i) => ({ id: 900 + i, legacy_id: legacy, recipient, merchant, day, cod_amount: cod })),
      total: 5,
    }),
    'company/legacy-purge': () => ({ simulation: { never_picked: 38, not_delivered: 21, held: 6, on_the_way: 14, unsettled: 9 }, last: null }),
    'company/legacy-recheck/deleted': () => ({
      __raw: {
        data: [
          { id: 950, legacy_id: 47903, status_label: 'Livrée', money: true, cash_with_courier: true, recipient: 'Kevin N\'Guessan', merchant: 'Tech Store', courier: 'Koffi Yao', cod_amount: 35000 },
          { id: 951, legacy_id: 48120, status_label: 'Livrée', money: true, cash_with_courier: false, recipient: 'Clarisse Yapi', merchant: 'Awa Mode', courier: 'Serge Kouassi', cod_amount: 18000 },
          { id: 952, legacy_id: 48455, status_label: 'En livraison', money: false, cash_with_courier: false, recipient: 'Ousmane Cissé', merchant: 'Boutique Chic', courier: 'Moussa Coulibaly', cod_amount: 22500 },
        ],
        meta: { total: 3 },
      },
    }),
    'company/legacy-switch': () => switchState(),
  }
}

// [identifiant, page, gestes, vue (0, pixels, ou titre amené en haut), éléments à repérer]
const SHOTS = [
  ['accueil', '/admin/reglages/reprise', null, 0, ['Copie active', 'À faire', 'Argent chez les livreurs : point', 'Contrôles et ménage', 'Bascule vers Jibiat']],
  ['argent', '/admin/reglages/reprise/argent-livreurs', null, 0, ['1. Date du point de départ', 'Date de bascule', '2. Livreur par livreur', 'Moussa Coulibaly']],
  ['argent-montant', '/admin/reglages/reprise/argent-livreurs', async (page) => {
    await page.getByLabel('Montant que Moussa Coulibaly a vraiment').fill('18500')
    await page.getByLabel('Montant que Serge Kouassi a vraiment').fill('12000')
    await page.getByLabel('Montant que Ibrahim Traoré a vraiment').fill('7500')
  }, '2. Livreur par livreur', ['Tout remis', 'Moussa Coulibaly', 'css:input[type="number"]', 'Total']],
  ['argent-valider', '/admin/reglages/reprise/argent-livreurs', async (page) => {
    await page.getByLabel('Montant que Moussa Coulibaly a vraiment').fill('18500')
    await page.getByLabel('Montant que Serge Kouassi a vraiment').fill('12000')
    await page.getByLabel('Montant que Ibrahim Traoré a vraiment').fill('7500')
    await page.getByLabel('Tapez VALIDER pour confirmer').fill('VALIDER')
  }, 'Total', ['seront marqués', 'Tapez VALIDER', 'Faire le point de départ', 'Rien ne bouge']],
  ['soldes', '/admin/reglages/reprise/controles', null, 'Contrôle des soldes', ['Contrôle des soldes', 'Comparer les soldes']],
  ['soldes-compare', '/admin/reglages/reprise/controles', async (page) => {
    await page.getByRole('button', { name: 'Comparer les soldes' }).click()
    await page.waitForTimeout(800)
  }, 'Contrôle des soldes', ['E-commerçants : à leur reverser', 'Livreurs : argent encore en main', 'identiques', 'Valider ces soldes']],
  ['nettoyage', '/admin/reglages/reprise/controles', null, 'Anciennes courses à clôturer', ['Anciennes courses à clôturer', 'Simuler', 'Jamais ramassées', 'Ramassées, au dépôt', 'En chemin depuis longtemps', 'Livrées, argent pas soldé']],
  ['nettoyage-cloturer', '/admin/reglages/reprise/controles', async (page) => {
    await page.getByLabel('Tapez CLÔTURER pour confirmer').fill('CLÔTURER')
  }, 'Jamais ramassées', ['course(s) seront clôturées', 'Tapez CLÔTURER', 'Clôturer ces courses']],
  ['en-chemin', '/admin/reglages/reprise/a-verifier?days=30', async (page) => {
    await page.getByRole('button', { name: /Moussa Coulibaly/ }).click()
    await page.waitForTimeout(800)
    const boxes = page.locator('tbody input[type="checkbox"]')
    await boxes.nth(0).check()
    await boxes.nth(1).check()
  }, 0, ['Moussa Coulibaly', 'Livrées (argent à remettre)', 'Retournées à l\'e-commerçant', '48211']],
  ['supprimees', '/admin/reglages/reprise/supprimees', null, 0, ['Supprimées dans l', 'Garder', 'Annuler', '47903']],
  ['bascule', '/admin/reglages/reprise/bascule', null, 0, ['Date de la bascule', 'Bascule prévue', 'Jour et heure de la bascule', 'Programmer', 'Basculer maintenant', 'Déjà passés']],
  ['bascule-faite', '/admin/reglages/reprise/bascule', null, 0, ['Bascule commencée', 'Déjà passés']],
]

function scroller() {
  const all = [document.scrollingElement, ...document.querySelectorAll('main, [class*="overflow-y-auto"], [class*="overflow-auto"]')]
  return all.filter(Boolean).sort((a, b) => (b.scrollHeight - b.clientHeight) - (a.scrollHeight - a.clientHeight))[0]
}

;(async () => {
  const browser = await chromium.launch({ args: ['--lang=fr-FR'], executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' })
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1.5, locale: 'fr-FR', timezoneId: 'Africa/Abidjan', reducedMotion: 'reduce' })
  const routes = mocks()
  await context.route(/\/api\/v1\/company\/legacy-/, async (route) => {
    const url = new URL(route.request().url())
    const key = url.pathname.replace(/^\/api\/v1\//, '')
    if (route.request().method() !== 'GET') return route.fulfill({ json: { data: {} } })
    const fn = routes[key]
    if (!fn) return route.continue()
    const body = fn()
    return route.fulfill({ json: body.__raw ?? { data: body } })
  })

  const login = await context.newPage()
  await login.goto(`${JIBIAT}/admin/connexion`)
  await login.fill('input[type="tel"], input[autocomplete="username"]', ADMIN.phone)
  await login.fill('input[type="password"]', ADMIN.password)
  await login.getByRole('button', { name: 'Se connecter', exact: true }).click()
  await login.waitForTimeout(3000)
  if (login.url().includes('connexion')) console.log('connexion ?', await login.locator('body').innerText().then((t) => t.slice(0, 300)))
  await login.close()

  const only = process.argv.slice(2)
  const POS = path.join(OUT, 'positions-bascule.json')
  const positions = fs.existsSync(POS) ? JSON.parse(fs.readFileSync(POS, 'utf8')) : {}
  for (const [name, url, steps, view, texts] of SHOTS) {
    if (only.length && !only.includes(name)) continue
    state.switchStarted = name === 'bascule-faite'
    const page = await context.newPage()
    try {
      await page.goto(JIBIAT + url)
      await page.waitForLoadState('networkidle').catch(() => {})
      await page.waitForTimeout(1200)
      await page.addStyleTag({ content: '[class*="install" i], .toast, [role="status"]{display:none!important}' }).catch(() => {})
      await page.evaluate(() => {
        const el = [...document.querySelectorAll('div, p, a')].filter((e) => e.textContent.trim().startsWith('🧭 Mise en route') || e.textContent.trim().startsWith('Mise en route :'))
          .sort((a, b) => a.textContent.length - b.textContent.length)[0]
        if (el) (el.closest('[class*="bg-amber"], [class*="bg-yellow"]') ?? el).style.display = 'none'
      })
      if (steps) await steps(page)
      await page.evaluate(([v, find]) => {
        const box = new Function(`return (${find})`)()()
        if (typeof v === 'number') return box.scrollTo(0, v)
        const t = [...document.querySelectorAll('h1, h2, h3, p, span, div, a, td')].filter((e) => e.offsetParent && e.textContent.trim().startsWith(v))
          .sort((a, b) => a.textContent.length - b.textContent.length)[0]
        if (t) box.scrollTo(0, t.getBoundingClientRect().top + box.scrollTop - 110)
      }, [view, scroller.toString()])
      await page.evaluate(() => {
        document.activeElement?.blur()
        // Champs de date affichés à la française (le navigateur sans interface les montre à l'américaine)
        for (const input of document.querySelectorAll('input[type="date"], input[type="datetime-local"]')) {
          const [d, t] = input.value.split('T')
          if (!d) continue
          const [y, m, j] = d.split('-')
          input.type = 'text'
          input.value = `${j}/${m}/${y}${t ? ` à ${t.slice(0, 5).replace(':', ' h ')}` : ''}`
        }
      })
      await page.mouse.move(1270, 790)
      await page.waitForTimeout(500)
      await page.screenshot({ path: path.join(OUT, `bascule-${name}.png`) })
      positions[name] = await page.evaluate((list) => Object.fromEntries(list.map((t) => {
        const el = t.startsWith('css:') ? [...document.querySelectorAll(t.slice(4))].filter((e) => e.offsetParent)[0]
          : [...document.querySelectorAll('body *')].filter((e) => e.offsetParent && e.textContent.trim().includes(t)).sort((a, b) => a.textContent.length - b.textContent.length)[0]
        if (!el) return [t, null]
        const r = (e) => { const b = e.getBoundingClientRect(); return { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) } }
        return [t, { text: r(el), box: r(el.closest('.card, .rounded-lg, .rounded-xl, button, label, a, tr') ?? el) }]
      })), texts)
      console.log('✓', name)
    } catch (e) {
      console.log('✗', name, e.message.split('\n')[0])
    }
    await page.close()
  }
  fs.writeFileSync(POS, JSON.stringify(positions, null, 1))
  await browser.close()
})()
