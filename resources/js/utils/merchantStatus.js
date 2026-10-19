import { longDay } from '../composables/useDeliveryDate'

/**
 * Où en est une course, en une phrase pour l'e-commerçant (au lieu du nom technique du statut),
 * avec une icône et un ton : bleu en route, vert livrée, orange à régler, gris le reste.
 */
const TONES = {
  blue: { color: 'text-blue-700', bg: 'bg-blue-50' },
  green: { color: 'text-emerald-700', bg: 'bg-emerald-50' },
  amber: { color: 'text-amber-700', bg: 'bg-amber-50' },
  slate: { color: 'text-slate-500', bg: 'bg-slate-100' },
}

const hour = (value) => (value ? new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')

function line(order) {
  switch (order.status) {
    case 'pending': return ['⏳', 'En attente de validation par l\'agence', 'slate']
    case 'confirmed': return ['📋', 'Validée : un livreur va venir la chercher', 'slate']
    case 'pickup_assigned':
    case 'pickup_in_progress': return ['🛵', 'Un livreur vient chercher le colis', 'blue']
    case 'picked_up':
    case 'at_hub':
    case 'delivery_assigned': return ['📦', 'Colis récupéré, bientôt en route', 'blue']
    case 'out_for_delivery': return ['🛵', 'Le livreur est en route vers le client', 'blue']
    case 'delivered': return ['✅', order.delivered_at ? `Livrée à ${hour(order.delivered_at)}` : 'Livrée', 'green']
    // Décision prise : le colis revient, ou une nouvelle livraison est prévue
    case 'delivery_failed': return order.return_requested
      ? ['↩️', 'Retour demandé : le colis va vous revenir', 'slate']
      : ['⚠️', order.last_incident?.label || 'Livraison échouée : à régler', 'amber']
    case 'rescheduled': return ['📅', order.delivery?.scheduled_date ? `Relivraison prévue le ${longDay(order.delivery.scheduled_date)}` : 'Relivraison prévue', 'blue']
    case 'return_assigned':
    case 'returning': return ['↩️', 'En retour vers votre boutique', 'slate']
    case 'returned': return ['↩️', 'Retournée à votre boutique', 'slate']
    case 'cancelled': return ['✖️', 'Annulée', 'slate']
    case 'rejected': return ['✖️', 'Refusée par l\'agence', 'slate']
    case 'lost': return ['❗', 'Colis perdu : l\'agence vous contacte', 'amber']
    default: return ['📦', order.status_label || order.status, 'slate']
  }
}

export function merchantStatus(order) {
  const [icon, text, tone] = line(order)

  return { icon, text, tone, ...TONES[tone] }
}

/** La course attend-elle une décision ? (calculé par le serveur, comme la file « À décider » de l'agence) */
export const needsDecision = (order) => !!order.needs_decision
