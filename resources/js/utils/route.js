/**
 * Itinéraire du livreur : ses missions en cours (ramassages, livraisons, retours) rangées en arrêts, un arrêt par lieu,
 * tri de proche en proche et trajet Google Maps à plusieurs arrêts.
 * Règle : le colis d'une course est ramassé avant d'être livré (si les deux missions sont chez ce livreur).
 */

// Arrêts possibles dans un lien Google Maps (destination + 9 étapes)
export const MAPS_MAX_STOPS = 10

// Rappel « retour tout près » sur une livraison : distance maximale à vol d'oiseau
export const NEARBY_KM = 1

/** Lieu d'une mission : ramassage et retour chez l'e-commerçant, livraison chez le destinataire */
export function missionPlace(m) {
  const p = (m.type === 'delivery' ? m.order?.delivery : m.order?.pickup) || {}
  const lat = p.lat ? Number(p.lat) : null
  const lng = p.lng ? Number(p.lng) : null
  return { lat, lng, address: p.address || '', zone: p.zone_name || '', located: lat !== null && lng !== null }
}

/** Nom affiché pour l'arrêt : la boutique (ramassage, retour) ou le destinataire (livraison) */
function missionName(m) {
  if (m.type === 'delivery') return m.order?.recipient?.name || m.order?.recipient?.phone || m.order?.tracking_code
  return m.order?.merchant?.business_name || m.order?.pickup?.contact_name || m.order?.tracking_code
}

// Même lieu : même position (à une dizaine de mètres près), sinon même adresse dans la même commune
function placeKey(m) {
  const p = missionPlace(m)
  if (p.located) return `l:${p.lat.toFixed(4)},${p.lng.toFixed(4)}`
  return `a:${p.zone}|${p.address}`.toLowerCase().replace(/\s+/g, ' ').trim()
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
 * Arrêts de l'itinéraire, dans l'ordre enregistré (route_rank), les missions pas encore placées à la fin.
 * Ramassage et retour chez la même boutique : un seul arrêt.
 */
export function buildStops(missions) {
  const stops = new Map()
  missions.forEach((m, index) => {
    const key = placeKey(m)
    if (!stops.has(key)) stops.set(key, { key, missions: [], index, place: missionPlace(m), name: missionName(m) })
    const stop = stops.get(key)
    stop.missions.push(m)
    if (!stop.place.located && missionPlace(m).located) stop.place = missionPlace(m)
  })
  const rank = (s) => Math.min(...s.missions.map((m) => m.route_rank ?? Infinity))
  return [...stops.values()]
    .sort((a, b) => rank(a) - rank(b) || a.index - b.index)
    .map((s) => ({ ...s, tasks: tasksOf(s.missions) }))
}

/** Ce qu'il y a à faire à l'arrêt : « Retourner 2 colis », « Ramasser 3 colis », « Livrer » */
function tasksOf(missions) {
  const of = (type) => missions.filter((m) => m.type === type)
  const tasks = []
  const returns = of('return')
  const pickups = of('pickup')
  const deliveries = of('delivery')
  if (returns.length) tasks.push({ type: 'return', label: `Retourner ${returns.length} colis`, missions: returns })
  if (pickups.length) tasks.push({ type: 'pickup', label: `Ramasser ${pickups.length} colis`, missions: pickups })
  if (deliveries.length) tasks.push({ type: 'delivery', label: deliveries.length > 1 ? `Livrer ${deliveries.length} colis` : 'Livrer', missions: deliveries })
  return tasks
}

/** Arrêts à faire avant celui-ci : le ramassage des colis qu'on y livre */
function prerequisites(stop, stops) {
  const orders = new Set(stop.missions.filter((m) => m.type === 'delivery').map((m) => m.order_id))
  return stops.filter((s) => s !== stop && s.missions.some((m) => m.type === 'pickup' && orders.has(m.order_id)))
}

/** Livraisons placées avant le ramassage de leur colis (ordre choisi à la main) */
export function orderViolations(stops) {
  const seen = new Set()
  const wrong = []
  for (const stop of stops) {
    for (const before of prerequisites(stop, stops)) {
      if (!seen.has(before.key)) wrong.push({ stop, before })
    }
    seen.add(stop.key)
  }
  return wrong
}

/**
 * Ordre de passage de proche en proche à partir de la position du livreur (ou du premier arrêt placé), en gardant
 * chaque ramassage avant la livraison du même colis. Les arrêts sans position suivent, groupés par commune.
 *
 * @returns {{ ordered: object[], located: number, unlocated: number }}
 */
export function nearestNeighborRoute(stops, start = null) {
  const located = stops.filter((s) => s.place.located)
  const unlocated = stops.filter((s) => !s.place.located)
  const done = new Set()
  const ready = (s) => prerequisites(s, stops).every((p) => done.has(p.key) || !p.place.located)

  const ordered = []
  const left = [...located]
  let here = start || (left.length ? left[0].place : null)
  while (left.length) {
    const candidates = left.filter(ready)
    const pool = candidates.length ? candidates : left
    let best = pool[0]
    for (const s of pool) if (distanceKm(here, s.place) < distanceKm(here, best.place)) best = s
    left.splice(left.indexOf(best), 1)
    ordered.push(best)
    done.add(best.key)
    here = best.place
  }

  const zones = new Map()
  for (const s of unlocated) {
    if (!zones.has(s.place.zone)) zones.set(s.place.zone, [])
    zones.get(s.place.zone).push(s)
  }
  // Sans position : ramassages d'abord dans chaque commune, pour garder « ramassé avant livré »
  const rest = [...zones.values()].flatMap((list) => [...list].sort((a, b) => Number(a.missions[0].type === 'delivery') - Number(b.missions[0].type === 'delivery')))

  return { ordered: [...ordered, ...rest], located: located.length, unlocated: unlocated.length }
}

const stopParam = (s) => (s.place.located ? `${s.place.lat},${s.place.lng}` : [s.place.address, s.place.zone].filter(Boolean).join(', '))

/** Lien Google Maps : trajet depuis la position du téléphone vers les premiers arrêts, dans l'ordre donné */
export function googleMapsRoute(stops) {
  const list = stops.slice(0, MAPS_MAX_STOPS).map(stopParam).filter(Boolean)
  if (!list.length) return null
  const destination = list.pop()
  const params = new URLSearchParams({ api: '1', destination, travelmode: 'driving' })
  if (list.length) params.set('waypoints', list.join('|'))
  return `https://www.google.com/maps/dir/?${params.toString()}`
}

/** Retour à faire tout près d'une livraison (le plus proche, à moins de NEARBY_KM) */
export function nearbyReturn(delivery, stops) {
  const here = missionPlace(delivery)
  if (!here.located) return null
  let best = null
  for (const s of stops) {
    if (!s.place.located || !s.missions.some((m) => m.type === 'return')) continue
    const km = distanceKm(here, s.place)
    if (km <= NEARBY_KM && (!best || km < best.km)) best = { stop: s, km }
  }
  return best
}
