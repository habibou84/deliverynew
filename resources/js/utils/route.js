/**
 * Tournée du livreur : tri de proche en proche et trajet Google Maps à plusieurs arrêts.
 * Une mission sans position GPS ne peut pas être placée sur la carte : elle passe après, groupée par commune.
 */

// Arrêts possibles dans un lien Google Maps (destination + 9 étapes)
export const MAPS_MAX_STOPS = 10

const point = (m) => {
  const d = m.order?.delivery
  return d?.lat && d?.lng ? { lat: Number(d.lat), lng: Number(d.lng) } : null
}

// Distance à vol d'oiseau en km (formule de haversine)
export function distanceKm(a, b) {
  const rad = (x) => (x * Math.PI) / 180
  const dLat = rad(b.lat - a.lat)
  const dLng = rad(b.lng - a.lng)
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2
  return 6371 * 2 * Math.asin(Math.sqrt(h))
}

/**
 * Ordre de passage de proche en proche à partir de la position du livreur (ou de la première livraison placée).
 * Les livraisons sans position suivent, groupées par commune dans l'ordre où elles apparaissent.
 *
 * @returns {{ ordered: object[], located: number, unlocated: number }}
 */
export function nearestNeighborRoute(missions, start = null) {
  const located = missions.filter((m) => point(m))
  const unlocated = missions.filter((m) => !point(m))

  const ordered = []
  const left = [...located]
  let here = start || (left.length ? point(left[0]) : null)
  while (left.length) {
    let best = 0
    for (let i = 1; i < left.length; i++) {
      if (distanceKm(here, point(left[i])) < distanceKm(here, point(left[best]))) best = i
    }
    const [next] = left.splice(best, 1)
    ordered.push(next)
    here = point(next)
  }

  const zones = new Map()
  for (const m of unlocated) {
    const zone = m.order?.delivery?.zone_name || ''
    if (!zones.has(zone)) zones.set(zone, [])
    zones.get(zone).push(m)
  }

  return { ordered: [...ordered, ...[...zones.values()].flat()], located: located.length, unlocated: unlocated.length }
}

const stop = (m) => {
  const p = point(m)
  if (p) return `${p.lat},${p.lng}`
  const d = m.order?.delivery || {}
  return [d.address, d.zone_name].filter(Boolean).join(', ')
}

/**
 * Lien Google Maps : trajet depuis la position du téléphone vers les premiers arrêts, dans l'ordre donné.
 */
export function googleMapsRoute(missions) {
  const stops = missions.slice(0, MAPS_MAX_STOPS).map(stop).filter(Boolean)
  if (!stops.length) return null
  const destination = stops.pop()
  const params = new URLSearchParams({ api: '1', destination, travelmode: 'driving' })
  if (stops.length) params.set('waypoints', stops.join('|'))
  return `https://www.google.com/maps/dir/?${params.toString()}`
}
