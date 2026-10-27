import { longDay } from '../composables/useDeliveryDate'
import { money } from './format'

/**
 * Étape d'une course pour l'e-commerçant : groupe de la liste, couleur, barre d'avancement en 4 traits
 * (créée, récupérée, en route, livrée) et phrase courte.
 *
 * Couleurs : gris à ramasser, bleu récupérée ou en route, vert livrée, orange à régler,
 * violet retour, rouge refusée ou perdue.
 */
export const TONES = {
  orange: { badge: 'bg-orange-100 text-orange-700', color: 'text-orange-700', bar: 'bg-orange-500', dot: 'bg-orange-500', soft: 'bg-orange-50' },
  blue: { badge: 'bg-blue-100 text-blue-700', color: 'text-blue-700', bar: 'bg-blue-600', dot: 'bg-blue-600', soft: 'bg-blue-50' },
  gray: { badge: 'bg-slate-100 text-slate-500', color: 'text-slate-600', bar: 'bg-slate-500', dot: 'bg-slate-400', soft: 'bg-slate-50' },
  green: { badge: 'bg-emerald-100 text-emerald-700', color: 'text-emerald-700', bar: 'bg-emerald-700', dot: 'bg-emerald-700', soft: 'bg-emerald-50' },
  violet: { badge: 'bg-violet-100 text-violet-700', color: 'text-violet-700', bar: 'bg-violet-600', dot: 'bg-violet-600', soft: 'bg-violet-50' },
  red: { badge: 'bg-red-100 text-red-700', color: 'text-red-700', bar: 'bg-red-600', dot: 'bg-red-600', soft: 'bg-red-50' },
}

/** Groupes de la liste, du plus urgent au plus avancé */
export const GROUPS = [
  { key: 'decide', label: 'À régler', tone: 'orange' },
  { key: 'route', label: 'En route vers le client', tone: 'blue' },
  { key: 'picked', label: 'Récupérées, bientôt en route', tone: 'blue' },
  { key: 'pickup', label: 'À ramasser chez vous', tone: 'gray' },
  { key: 'delivered', label: 'Livrées', tone: 'green' },
  { key: 'returns', label: 'Retours', tone: 'violet' },
  { key: 'closed', label: 'Annulées ou refusées', tone: 'gray' },
]

/** Icône en couleur de chaque groupe (une seule icône, le colis, pour une course pas encore partie) */
export const STAGE_ICONS = { decide: 'aregler', route: 'moto', picked: 'moto', pickup: 'colis', delivered: 'livre', returns: 'retour', closed: 'attente' }

const hour = (value) => (value ? new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')
const firstName = (person) => (person?.name || '').split(' ')[0]

/** Ce qui revient au marchand pour une course (encaissé ou à encaisser, moins la livraison et l'expédition) */
export function orderNet(order) {
  const shipping = order.is_shipping ? (order.shipping?.fee ?? order.shipping?.fee_estimate ?? 0) : 0
  return (order.amounts.collected_amount ?? order.amounts.cod_amount) - (order.amounts.total_fees || 0) - shipping
}

function groupOf(order) {
  if (order.needs_decision) return 'decide'
  switch (order.status) {
    case 'out_for_delivery': return 'route'
    case 'picked_up':
    case 'at_hub':
    case 'delivery_assigned':
    case 'rescheduled': return 'picked'
    case 'delivery_failed': return order.return_requested ? 'returns' : 'picked'
    case 'pending':
    case 'confirmed':
    case 'pickup_assigned':
    case 'pickup_in_progress': return 'pickup'
    case 'delivered': return 'delivered'
    case 'return_assigned':
    case 'returning':
    case 'returned': return 'returns'
    default: return 'closed'
  }
}

const STEPS = {
  pending: 1, confirmed: 1, pickup_assigned: 1, pickup_in_progress: 1,
  picked_up: 2, at_hub: 2, delivery_assigned: 2, delivery_failed: 2, rescheduled: 2,
  out_for_delivery: 3, delivered: 4,
  return_assigned: 2, returning: 2, returned: 2, cancelled: 1, rejected: 1, lost: 2,
}

function sentence(order) {
  const courier = order.delivery_courier || order.pickup_courier
  switch (order.status) {
    case 'pending': return ['En attente de validation', 'clock']
    case 'confirmed': return [order.from_warehouse ? 'Préparée à l\'entrepôt' : 'En attente du livreur', 'clock']
    case 'pickup_assigned':
    case 'pickup_in_progress': return [courier ? `${firstName(courier)} vient chercher le colis` : 'Un livreur vient chercher le colis', 'store']
    case 'picked_up':
    case 'at_hub':
    case 'delivery_assigned': return ['Colis récupéré', 'box']
    case 'out_for_delivery': return ['Le livreur arrive', 'moto']
    case 'delivered': return [`Livrée${order.delivered_at ? ` à ${hour(order.delivered_at)}` : ''} · ${money(orderNet(order))} pour vous`, 'check']
    case 'rescheduled': return [order.delivery?.scheduled_date ? `Relivraison ${longDay(order.delivery.scheduled_date)}` : 'Relivraison prévue', 'calendar']
    case 'delivery_failed':
      if (order.return_requested) return ['Retour demandé', 'back']
      return [order.last_incident?.label ? `Échec : ${order.last_incident.label.charAt(0).toLowerCase()}${order.last_incident.label.slice(1)}` : 'Livraison échouée', 'alert']
    case 'return_assigned':
    case 'returning': return ['En retour vers vous', 'back']
    case 'returned': return ['Retournée à votre boutique', 'store']
    case 'cancelled': return ['Annulée', 'x']
    case 'rejected': return ['Refusée par l\'agence', 'ban']
    case 'lost': return ['Colis perdu : l\'agence vous contacte', 'alert']
    default: return [order.status_label || order.status, 'box']
  }
}

export function orderStage(order) {
  const group = GROUPS.find((g) => g.key === groupOf(order))
  const tone = order.status === 'lost' || order.status === 'rejected' ? 'red' : group.tone
  const [text, icon] = sentence(order)
  const courier = ['delivered', 'returned', 'cancelled', 'rejected'].includes(order.status)
    ? null
    : order.delivery_courier || order.pickup_courier

  return {
    group: group.key,
    tone,
    ...TONES[tone],
    step: STEPS[order.status] ?? 1,
    text,
    icon,
    // Petit repère à droite : le livreur en charge, sinon l'heure de création
    aside: courier ? firstName(courier) : order.status === 'delivered' ? '' : hour(order.created_at),
  }
}

/** Courses rangées par groupe, dans l'ordre des groupes (groupes vides retirés) */
export function groupOrders(orders) {
  return GROUPS
    .map((g) => ({ ...g, ...TONES[g.tone], orders: orders.filter((o) => groupOf(o) === g.key) }))
    .filter((g) => g.orders.length)
}
