/**
 * Réduit une photo prise au téléphone (souvent plusieurs Mo) avant l'envoi : côté le plus long ramené à `max`
 * pixels, enregistrée en PNG (net pour un QR code) ou en JPEG si le PNG reste lourd. Si le navigateur ne sait pas lire l'image, elle part telle quelle.
 */
export async function shrinkImage(file, max = 1200) {
  try {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height))
    if (scale === 1 && file.size < 1024 * 1024 && /^image\/(png|jpeg|webp)$/.test(file.type)) return file
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    const png = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
    if (png && png.size <= 1024 * 1024) return new File([png], 'qr-code.png', { type: 'image/png' })
    // Photo chargée (fond, reflets) : JPEG, bien plus léger
    const jpeg = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9))
    return jpeg ? new File([jpeg], 'qr-code.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file
  }
}
