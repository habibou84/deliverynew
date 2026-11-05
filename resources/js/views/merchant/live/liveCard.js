/**
 * Carte du live : image à épingler sur TikTok ou Facebook (produits, codes A1…, prix, numéro avec des points).
 * Dessinée dans le navigateur (canvas), sans service extérieur.
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
 * @param {{ shop: string, name: string, letter: string, items: Array<{code:string,name:string,price:number,remaining?:number,photo_url?:string}>, phone: string, square?: boolean, accent?: string }} card
 * @returns {Promise<HTMLCanvasElement>}
 */
export async function drawCard({ shop, name, letter, items, phone, square = false, accent = '#e11d48' }) {
  const W = 1080
  const H = square ? 1080 : 1920
  const canvas = document.createElement('canvas')
  canvas.width = W
  canvas.height = H
  const ctx = canvas.getContext('2d')
  const font = (weight, size) => `${weight} ${size}px Inter, system-ui, sans-serif`

  ctx.fillStyle = '#0f172a'
  ctx.fillRect(0, 0, W, H)

  // En-tête : boutique, nom du live, carte
  const pad = 56
  ctx.fillStyle = '#ffffff'
  ctx.font = font(800, square ? 52 : 64)
  ctx.fillText(fitText(ctx, shop, W - pad * 2 - 260), pad, square ? 96 : 130)
  ctx.font = font(500, square ? 34 : 40)
  ctx.fillStyle = '#cbd5e1'
  ctx.fillText(fitText(ctx, name, W - pad * 2), pad, square ? 148 : 196)
  ctx.font = font(800, 34)
  const badge = `EN LIVE · CARTE ${letter}`
  const bw = ctx.measureText(badge).width + 40
  ctx.fillStyle = accent
  roundRect(ctx, W - pad - bw, square ? 52 : 82, bw, 60, 14)
  ctx.fill()
  ctx.fillStyle = '#ffffff'
  ctx.fillText(badge, W - pad - bw + 20, square ? 94 : 124)

  // Produits : 3 colonnes
  const top = square ? 190 : 260
  const footer = square ? 180 : 260
  const rows = Math.max(1, Math.ceil(items.length / 3))
  const gap = 24
  const cw = (W - pad * 2 - gap * 2) / 3
  const ch = Math.min(square ? 420 : 470, (H - top - footer - gap * (rows + 1)) / rows)
  const photos = await Promise.all(items.map((i) => loadImage(i.photo_url)))
  items.forEach((item, i) => {
    const x = pad + (i % 3) * (cw + gap)
    const y = top + Math.floor(i / 3) * (ch + gap)
    const soldOut = item.remaining === 0
    ctx.fillStyle = '#ffffff'
    roundRect(ctx, x, y, cw, ch, 24)
    ctx.fill()
    const ph = ch - 130
    ctx.save()
    roundRect(ctx, x, y, cw, ph, 24)
    ctx.clip()
    if (photos[i]) {
      const img = photos[i]
      const scale = Math.max(cw / img.width, ph / img.height)
      ctx.drawImage(img, x + (cw - img.width * scale) / 2, y + (ph - img.height * scale) / 2, img.width * scale, img.height * scale)
    } else {
      ctx.fillStyle = '#e2e8f0'
      ctx.fillRect(x, y, cw, ph)
    }
    ctx.restore()
    // Code
    ctx.font = font(800, 40)
    const cwid = ctx.measureText(item.code).width + 28
    ctx.fillStyle = '#0f172a'
    roundRect(ctx, x + 14, y + 14, cwid, 58, 12)
    ctx.fill()
    ctx.fillStyle = '#ffffff'
    ctx.fillText(item.code, x + 28, y + 57)
    // Nom et prix
    ctx.fillStyle = '#334155'
    ctx.font = font(500, 30)
    ctx.fillText(fitText(ctx, item.name, cw - 28), x + 16, y + ph + 48)
    ctx.fillStyle = soldOut ? '#b91c1c' : '#0f172a'
    ctx.font = font(800, 40)
    ctx.fillText(soldOut ? 'ÉPUISÉ' : money(item.price), x + 16, y + ph + 102)
    if (soldOut) {
      ctx.fillStyle = 'rgba(255,255,255,.55)'
      roundRect(ctx, x, y, cw, ph, 24)
      ctx.fill()
    }
  })

  // Pied : comment commander (numéro avec des points, sans le mot « WhatsApp »)
  const fy = H - footer + 30
  ctx.fillStyle = '#ffffff'
  roundRect(ctx, pad, fy, W - pad * 2, footer - 80, 28)
  ctx.fill()
  ctx.textAlign = 'center'
  ctx.fillStyle = '#0f172a'
  ctx.font = font(700, square ? 36 : 42)
  ctx.fillText(`Envoie le code, par ex. ${items[0]?.code || 'A1'}`, W / 2, fy + (square ? 50 : 66))
  ctx.font = font(800, square ? 54 : 68)
  ctx.fillText(dottedPhone(phone), W / 2, fy + (square ? 112 : 146))
  ctx.textAlign = 'left'

  return canvas
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
