/**
 * Écrans du live côté e-commerçante (Jibiat, application e-commerçant) pour la vidéo « live-comment-ca-marche » :
 * produits choisis, carte prête, écran du live avec les commandes WhatsApp automatiques, bilan. Les réponses du
 * live sont mises en scène dans le navigateur (aucune donnée réelle) ; seule la connexion passe par le serveur local.
 *   node tools/motion/capture-live.cjs   (serveur Jibiat local sur http://localhost:8000, compte e-commerçant de démo)
 */
const { chromium, devices } = require('playwright')
const path = require('node:path')
const fs = require('node:fs')

const BASE = process.env.BASE || 'http://localhost:8000'
const PROPS = path.join(__dirname, 'props/live')
const OUT = path.join(__dirname, 'assets')
const min = (m) => new Date(Date.now() - m * 60000).toISOString()

const photo = (n) => `${BASE}/live-props/${n}.jpg`
const ITEMS = [
  { id: 1, product_id: 11, code: 'A1', name: 'Robe wax', variant: 'Taille S', label: 'Robe wax · Taille S', price: 12000, quantity: 4, sold: 4, remaining: 0, photo_url: photo('robe') },
  { id: 2, product_id: 11, code: 'A2', name: 'Robe wax', variant: 'Taille M', label: 'Robe wax · Taille M', price: 12000, quantity: 5, sold: 3, remaining: 2, photo_url: photo('robe') },
  { id: 3, product_id: 12, code: 'A3', name: 'Sac à main cuir', variant: null, label: 'Sac à main cuir', price: 15000, quantity: 6, sold: 2, remaining: 4, photo_url: photo('sac') },
  { id: 4, product_id: 13, code: 'A4', name: 'Escarpins dorés', variant: null, label: 'Escarpins dorés', price: 9000, quantity: 5, sold: 1, remaining: 4, photo_url: photo('chaussures') },
]
// Numéro WhatsApp Business de la boutique relié par la connexion officielle de WhatsApp
const WA = { mode: 'official', auto: true, official: { available: true, connected: true, phone: '+2250707080910', name: 'Boutique Chic Abidjan' }, qr_option: false, available: false, connected: false, phone: null }
const OFFER = { enabled: true, offered_by_company: false, trial_left: 0, monthly_until: null, monthly_cards_left: 0, extra_cards: 0,
  prices: { live: 2000, cards_per_live: 10, month: 10000, cards_per_month: 60, pack: 1000, pack_cards: 10, trial_cards: 5 }, items_per_card: 9, phone: WA.phone }
const CATALOG = [
  { id: 11, name: 'Robe wax', price: 12000, photo_url: photo('robe'), stock: 9 },
  { id: 12, name: 'Sac à main cuir', price: 15000, photo_url: photo('sac'), stock: 6 },
  { id: 13, name: 'Escarpins dorés', price: 9000, photo_url: photo('chaussures'), stock: 5 },
  { id: 14, name: 'Montre dorée', price: 18000, photo_url: photo('montre'), stock: 3 },
]
const ORDERS = [
  ['Awa Koné', 'A2', 1, 'ordered'], ['Mariam Traoré', 'A3', 1, 'ordered'], ['Fatou Diallo', 'A1', 2, 'ordered'],
  ['Aya Kouassi', 'A1', 1, 'waiting'], ['Kadi Bamba', 'A2', 1, 'ordered'], ['Nadia Yao', 'A4', 1, 'ordered'], ['Sali Ouattara', 'A1', 1, 'ordered'],
].map(([name, code, quantity, status], i) => {
  const item = ITEMS.find((x) => x.code === code)
  return { id: 100 - i, code, name: item.label, customer_name: name, customer_phone: `+22507070000${i}`, quantity, status, order_id: status === 'ordered' ? 500 + i : null, source: 'whatsapp', created_at: min(1 + i * 3), refusals: 0 }
})
const LIVE = { id: 99, name: 'Vendredi mode', status: 'live', plan: 'single', started_at: min(24), ended_at: null, ai_cards_used: 2, items_count: ITEMS.length }
const BOARD = {
  live: LIVE, items: ITEMS, orders: ORDERS, whatsapp: WA,
  advices: [{ level: 'alert', code: 'A1', text: 'A1 épuisé. Annoncez-le.' }, { level: 'info', code: 'A2', text: 'A2 : il en reste 2. Dites-le !' }, { level: 'good', code: 'A3', text: 'A3 : 3 commandes en 5 min. Ça marche !' }],
  totals: { orders: 6, amount: 84000, waiting: 1 },
}
const DRAFT = { live: { ...LIVE, status: 'draft', started_at: null }, items: ITEMS.map((i) => ({ ...i })), catalog: CATALOG, cards: { left: 8 }, offer: OFFER, shop_name: 'Boutique Chic Abidjan', whatsapp: WA, intro: { views: 3, full: false } }

;(async () => {
  const browser = await chromium.launch()
  const ctx = await browser.newContext({ ...devices['iPhone 13'], viewport: { width: 390, height: 844 }, deviceScaleFactor: 3 })
  const page = await ctx.newPage()
  page.on('pageerror', (e) => console.log('ERR', e.message))
  await page.route('**/live-props/*.jpg', (r) => r.fulfill({ path: path.join(PROPS, path.basename(r.request().url())), contentType: 'image/jpeg' }))
  await page.route('**/api/v1/live/prepare', (r) => r.fulfill({ json: { data: DRAFT } }))
  await page.route('**/api/v1/live/99/items', (r) => r.fulfill({ json: { data: DRAFT } }))
  await page.route('**/api/v1/live/99', (r) => r.fulfill({ json: { data: BOARD } }))
  await page.route('**/api/v1/live/99/summary', (r) => r.fulfill({ json: { data: { ...BOARD, live: { ...LIVE, status: 'ended', ended_at: min(0) }, duration_minutes: 45,
    deliveries: { created: 6, delivered: 0, refused: 0 }, best_item: ITEMS[1], peak_minute: 10, new_customers: 4 } } }))

  await page.goto(`${BASE}/marchand/connexion`); await page.waitForTimeout(2000)
  await page.locator('input[type=tel], input[autocomplete=username]').first().fill('0500000002')
  await page.locator('input[type=password]').first().fill('password')
  await page.getByRole('button', { name: /connecter/i }).first().click(); await page.waitForTimeout(3000)

  const shot = (name) => page.screenshot({ path: path.join(OUT, `live-${name}.png`) })
  await page.goto(`${BASE}/marchand/live/preparer`); await page.waitForTimeout(2500)
  await page.locator('details').first().evaluate((d) => d.remove()).catch(() => {})
  await page.evaluate(() => window.scrollTo(0, 250)); await page.waitForTimeout(400)
  await shot('produits')
  await page.getByRole('button', { name: /Continuer avec/ }).click(); await page.waitForTimeout(3500)
  await shot('relie')
  await page.locator('img[alt^="Carte A"]').first().scrollIntoViewIfNeeded(); await page.waitForTimeout(500)
  await shot('carte')
  await page.goto(`${BASE}/marchand/live/99`); await page.waitForTimeout(3000)
  await shot('ecran')
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await page.waitForTimeout(500)
  await shot('commandes')
  await page.goto(`${BASE}/marchand/live/99/bilan`); await page.waitForTimeout(3000)
  await shot('bilan')
  await browser.close()
  console.log(fs.readdirSync(OUT).filter((f) => f.startsWith('live-')).join(' '))
})()
