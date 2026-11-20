/**
 * Articles du catalogue dans une course (même règle que la boutique) :
 * - produit dont le stock est suivi : envoyé avec son identifiant, la quantité est réservée dans le stock ;
 * - produit sans stock suivi : simple article (nom et prix), ramassé chez le marchand, sans limite.
 */
export const isTracked = (product) => (product.levels?.length ?? 0) > 0

// Quantité qu'on peut prendre à un emplacement (null = chez le marchand, sinon identifiant de l'entrepôt)
export function limitAt(product, hubId = null) {
  if (!isTracked(product)) return hubId === null ? Infinity : 0
  return product.levels.find((l) => (l.hub_id ?? null) === hubId)?.available ?? 0
}

// Ligne envoyée à l'API pour un produit et une quantité
export function orderLine(product, quantity, unitPrice = product.price) {
  return isTracked(product)
    ? { product_id: product.id, quantity, unit_price: unitPrice }
    : { label: product.name, quantity, unit_price: unitPrice }
}

// « 2 × Robe rouge, 1 × Sac »
export const describeLines = (lines) => lines.map((l) => `${l.quantity} × ${l.name}`).join(', ')
