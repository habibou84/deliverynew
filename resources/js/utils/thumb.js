// Photo à la taille affichée : le serveur renvoie une version allégée (160, 320, 640 ou 1080 px de large)
export function thumb(url, width = 320) {
  if (!url) return url
  return url + (url.includes('?') ? '&' : '?') + 'w=' + width
}
