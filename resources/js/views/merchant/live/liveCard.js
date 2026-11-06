/**
 * Carte du live : image à épingler sur TikTok ou Facebook, 100 % produits (photo, code A1…, nom, prix). Ni nom de
 * boutique, ni « live », ni numéro. Dessinée dans le navigateur (canvas), sans service extérieur.
 */

// Numéro écrit avec des points, sans indicatif : 07.00.00.00.00 (bonne pratique TikTok)
export function dottedPhone(phone) {
  const digits = String(phone || '').replace(/\D+/g, '').replace(/^225(?=\d{10}$)/, '')
  return digits.match(/.{1,2}/g)?.join('.') || ''
}

const money = (v) => `${Number(v || 0).toLocaleString('fr-FR').replace(/ | /g, ' ')} F`

async function loadImage(url) {
  if (!url) return null
  try {
    const blob = await (await fetch(url, { credentials: 'include' })).blob()
    return await createImageBitmap(blob)
  } catch {
    return null
  }
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath()
  ctx.moveTo(x + r, y)
  ctx.arcTo(x + w, y, x + w, y + h, r)
  ctx.arcTo(x + w, y + h, x, y + h, r)
  ctx.arcTo(x, y + h, x, y, r)
  ctx.arcTo(x, y, x + w, y, r)
  ctx.closePath()
}

function fitText(ctx, text, maxWidth) {
  let t = text
  while (t.length > 1 && ctx.measureText(t).width > maxWidth) t = t.slice(0, -1)
  return t === text ? t : `${t.trimEnd()}…`
}

/**
 * @param {{ items: Array<{code:string,name:string,price:number,remaining?:number,photo_url?:string}>, square?: boolean }} card
 * @returns {Promise<HTMLCanvasElement>}
 */
export async function drawCard({ items, square = false }) {
  const W = 1080
  const H = square ? 1080 : 1920
  const canvas = document.createElement('canvas')
  canvas.width = W
  canvas.height = H
  const ctx = canvas.getContext('2d')
  const font = (weight, size) => `${weight} ${size}px Inter, system-ui, sans-serif`

  ctx.fillStyle = '#0f172a'
  ctx.fillRect(0, 0, W, H)

  // Seulement les produits, sur toute la carte. Un seul produit (avec ou sans tailles) : grande photo ;
  // sinon une grille (deux par ligne, trois quand il y en a beaucoup).
  const pad = 48
  const single = isSingleProduct(items)
  const photos = await Promise.all((single ? items.slice(0, 1) : items).map((i) => loadImage(i.photo_url)))
  if (single) drawHero(ctx, { items, photo: photos[0], x: pad, y: pad, w: W - pad * 2, h: H - pad * 2, font, square })
  else drawGrid(ctx, { items, photos, x: pad, y: pad, w: W - pad * 2, h: H - pad * 2, font, square })

  return canvas
}

/** Un seul produit sur la carte (ses variantes comprises) */
export function isSingleProduct(items) {
  return items.length > 0 && items.every((i) => (i.product_id ?? i.name) === (items[0].product_id ?? items[0].name))
}

/** Colonnes de la grille selon le nombre de produits */
export function columnsFor(count, square = false) {
  return count <= (square ? 4 : 6) ? 2 : 3
}

function cover(ctx, img, x, y, w, h) {
  const scale = Math.max(w / img.width, h / img.height)
  ctx.drawImage(img, x + (w - img.width * scale) / 2, y + (h - img.height * scale) / 2, img.width * scale, img.height * scale)
}

function codeBadge(ctx, font, code, x, y, size) {
  ctx.font = font(800, size)
  const w = ctx.measureText(code).width + size * 0.7
  ctx.fillStyle = '#0f172a'
  roundRect(ctx, x, y, w, size * 1.45, size * 0.3)
  ctx.fill()
  ctx.fillStyle = '#ffffff'
  ctx.fillText(code, x + size * 0.35, y + size * 1.08)
  return w
}

/** Un seul produit : grande photo, nom, prix, et une pastille par variante (code + taille) */
function drawHero(ctx, { items, photo, x, y, w, h, font, square }) {
  const first = items[0]
  const variants = items.length > 1
  const samePrice = items.every((i) => i.price === first.price)
  const allSoldOut = items.every((i) => i.remaining === 0)
  const chipRows = variants ? Math.ceil(items.length / 3) : 0
  const textH = (square ? 150 : 190) + chipRows * (square ? 86 : 100)
  const ph = Math.max(200, h - textH)
  ctx.fillStyle = '#ffffff'
  roundRect(ctx, x, y, w, h, 32)
  ctx.fill()
  ctx.save()
  roundRect(ctx, x, y, w, ph, 32)
  ctx.clip()
  if (photo) cover(ctx, photo, x, y, w, ph)
  else {
    ctx.fillStyle = '#e2e8f0'
    ctx.fillRect(x, y, w, ph)
  }
  if (allSoldOut) {
    ctx.fillStyle = 'rgba(255,255,255,.55)'
    ctx.fillRect(x, y, w, ph)
  }
  ctx.restore()
  if (!variants) codeBadge(ctx, font, first.code, x + 24, y + 24, 64)

  let ty = y + ph + (square ? 62 : 78)
  ctx.fillStyle = '#334155'
  ctx.font = font(700, square ? 44 : 54)
  ctx.fillText(fitText(ctx, first.name, w - 48), x + 24, ty)
  ty += square ? 70 : 88
  ctx.fillStyle = allSoldOut ? '#b91c1c' : '#0f172a'
  ctx.font = font(800, square ? 64 : 80)
  const minPrice = Math.min(...items.map((i) => i.price))
  ctx.fillText(allSoldOut ? 'ÉPUISÉ' : `${samePrice ? '' : 'dès '}${money(minPrice)}`, x + 24, ty)
  if (!variants) return

  // Variantes : trois par ligne
  const gap = 16
  const cw = (w - 48 - gap * 2) / 3
  const chH = square ? 70 : 84
  items.forEach((item, i) => {
    const cx = x + 24 + (i % 3) * (cw + gap)
    const cy = ty + 24 + Math.floor(i / 3) * (chH + 16)
    const soldOut = item.remaining === 0
    ctx.fillStyle = soldOut ? '#f1f5f9' : '#0f172a'
    roundRect(ctx, cx, cy, cw, chH, 16)
    ctx.fill()
    ctx.fillStyle = soldOut ? '#94a3b8' : '#ffffff'
    ctx.font = font(800, square ? 34 : 40)
    const label = `${item.code} · ${item.variant || ''}`
    ctx.fillText(fitText(ctx, label, cw - 28), cx + 14, cy + chH * 0.66)
    if (soldOut) {
      ctx.strokeStyle = '#b91c1c'
      ctx.lineWidth = 5
      ctx.beginPath()
      ctx.moveTo(cx + 10, cy + chH / 2)
      ctx.lineTo(cx + cw - 10, cy + chH / 2)
      ctx.stroke()
    }
  })
}

/** Plusieurs produits : 2 ou 3 colonnes, photo, code, nom et prix */
function drawGrid(ctx, { items, photos, x, y, w, h, font, square }) {
  const cols = columnsFor(items.length, square)
  const rows = Math.ceil(items.length / cols)
  const gap = 24
  const cw = (w - gap * (cols - 1)) / cols
  const big = cols === 2
  const ch = Math.min(square ? (big ? 500 : 480) : (big ? 880 : 600), (h - gap * (rows - 1)) / rows)
  const nameSize = big ? 40 : 32
  const priceSize = big ? 56 : 44
  const textH = big ? 165 : 140
  // Grille centrée dans la hauteur disponible
  y += Math.max(0, (h - (rows * ch + (rows - 1) * gap)) / 2)
  items.forEach((item, i) => {
    const ix = x + (i % cols) * (cw + gap)
    const iy = y + Math.floor(i / cols) * (ch + gap)
    const soldOut = item.remaining === 0
    ctx.fillStyle = '#ffffff'
    roundRect(ctx, ix, iy, cw, ch, 24)
    ctx.fill()
    const ph = ch - textH
    ctx.save()
    roundRect(ctx, ix, iy, cw, ph, 24)
    ctx.clip()
    if (photos[i]) cover(ctx, photos[i], ix, iy, cw, ph)
    else {
      ctx.fillStyle = '#e2e8f0'
      ctx.fillRect(ix, iy, cw, ph)
    }
    ctx.restore()
    codeBadge(ctx, font, item.code, ix + 14, iy + 14, big ? 56 : 46)
    ctx.fillStyle = '#334155'
    ctx.font = font(500, nameSize)
    ctx.fillText(fitText(ctx, item.variant ? `${item.name} · ${item.variant}` : item.name, cw - 28), ix + 16, iy + ph + nameSize + 18)
    ctx.fillStyle = soldOut ? '#b91c1c' : '#0f172a'
    ctx.font = font(800, priceSize)
    ctx.fillText(soldOut ? 'ÉPUISÉ' : money(item.price), ix + 16, iy + ph + nameSize + priceSize + 36)
    if (soldOut) {
      ctx.fillStyle = 'rgba(255,255,255,.55)'
      roundRect(ctx, ix, iy, cw, ph, 24)
      ctx.fill()
    }
  })
}

/** Enregistre ou partage l'image (téléphone : feuille de partage ; sinon téléchargement) */
export async function saveCard(canvas, fileName) {
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
  const file = new File([blob], fileName, { type: 'image/png' })
  if (navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title: 'Carte du live' })
      return
    } catch {
      // partage annulé : téléchargement classique
    }
  }
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 2000)
}
