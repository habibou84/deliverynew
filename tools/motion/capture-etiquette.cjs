/**
 * Images de la vidéo « live-etiquette » : la fenêtre de l'étiquette dans l'application e-commerçant (Jibiat),
 * l'étiquette elle-même, puis le compte de l'e-commerçante sur un réseau social (étiquette épinglée, lien du live
 * dans la bio, numéro en commentaire épinglé pendant le live). Les données du live sont mises en scène dans le
 * navigateur ; seule la connexion passe par le serveur local.
 *   BASE=http://127.0.0.1:8011 node tools/motion/capture-etiquette.cjs   (compte e-commerçant de démo)
 */
const { chromium, devices } = require('playwright')
const path = require('node:path')

const BASE = process.env.BASE || 'http://localhost:8000'
const PROPS = path.join(__dirname, 'props/live')
const OUT = path.join(__dirname, 'assets')

const photo = (n) => `${BASE}/live-props/${n}.jpg`
const ITEMS = [
  { id: 1, product_id: 11, code: 'A1', name: 'Robe wax', variant: 'Taille S', label: 'Robe wax · Taille S', price: 12000, quantity: 4, photo_url: photo('robe') },
  { id: 2, product_id: 11, code: 'A2', name: 'Robe wax', variant: 'Taille M', label: 'Robe wax · Taille M', price: 12000, quantity: 5, photo_url: photo('robe') },
  { id: 3, product_id: 12, code: 'A3', name: 'Sac à main cuir', variant: null, label: 'Sac à main cuir', price: 15000, quantity: 6, photo_url: photo('sac') },
  { id: 4, product_id: 13, code: 'A4', name: 'Escarpins dorés', variant: null, label: 'Escarpins dorés', price: 9000, quantity: 5, photo_url: photo('chaussures') },
]
const WA = { mode: 'official', auto: true, official: { available: true, connected: true, phone: '+2250707080910', name: 'Boutique Chic Abidjan' }, qr_option: false, available: false, connected: false, phone: null }
const OFFER = { enabled: true, offered_by_company: false, trial_left: 1, monthly_until: null, extra_cards: 0, live_price: 500,
  prices: { live: 500, cap: 5000, pack: 1000, pack_cards: 10 }, phone: WA.official.phone }
const DRAFT = {
  live: { id: 99, name: 'Vendredi mode', status: 'draft', started_at: null }, items: ITEMS, catalog: [], cards: { left: 8 }, offer: OFFER,
  whatsapp: WA, public_url: 'https://boutique-chic.jibiat.com/l/7Kq2xA', intro: { views: 3, full: false },
}

;(async () => {
  const browser = await chromium.launch()
  const ctx = await browser.newContext({ ...devices['iPhone 13'], viewport: { width: 390, height: 844 }, deviceScaleFactor: 3 })
  const page = await ctx.newPage()
  page.on('pageerror', (e) => console.log('ERR', e.message))
  await page.addInitScript(() => { try { localStorage.setItem('install-dismissed-marchand', String(Date.now() + 9e9)); localStorage.setItem('jibiat.swipe-hint-done', '1') } catch {} })
  await page.route('**/live-props/*.jpg', (r) => r.fulfill({ path: path.join(PROPS, path.basename(r.request().url())), contentType: 'image/jpeg' }))
  await page.route('**/api/v1/live/prepare', (r) => r.fulfill({ json: { data: DRAFT } }))
  await page.route('**/api/v1/live/99/items', (r) => r.fulfill({ json: { data: DRAFT } }))

  await page.goto(`${BASE}/marchand/connexion`); await page.waitForTimeout(2000)
  await page.locator('input[type=tel], input[autocomplete=username]').first().fill('0500000001')
  await page.locator('input[type=password]').first().fill('password')
  await page.getByRole('button', { name: /connecter/i }).first().click(); await page.waitForTimeout(3000)

  // La fenêtre de l'étiquette, format TikTok
  await page.goto(`${BASE}/marchand/live/preparer`); await page.waitForTimeout(2500)
  await page.getByRole('button', { name: /Ouvrir ▸/ }).first().click(); await page.waitForTimeout(3000)
  await page.screenshot({ path: path.join(OUT, 'etiquette-fenetre.png') })
  // L'étiquette seule, pour le réseau social
  await page.locator('img[alt^="Étiquette A"]').first().screenshot({ path: path.join(PROPS, 'etiquette.png') })
  // Les consignes et « C'est fait »
  await page.getByText('Pendant le live, épinglez').scrollIntoViewIfNeeded()
  await page.waitForTimeout(600)
  await page.screenshot({ path: path.join(OUT, 'etiquette-consignes.png') })

  // Le compte sur le réseau social
  const social = await ctx.newPage()
  for (const n of ['profil', 'bio', 'live']) {
    await social.goto(`file://${PROPS}/reseau.html?n=${n}`); await social.waitForTimeout(400)
    await social.screenshot({ path: path.join(OUT, `etiquette-${n}.png`) })
  }
  await browser.close()
  console.log('etiquette-fenetre etiquette-consignes etiquette-profil etiquette-bio etiquette-live')
})()
