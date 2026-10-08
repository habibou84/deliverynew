// Miroir côté interface des transitions autorisées (App\Enums\OrderStatus).
// Le serveur reste seul juge : ceci sert uniquement à proposer les bonnes actions.
export const TRANSITIONS = {
  pending: ['confirmed', 'rejected', 'cancelled'],
  confirmed: ['cancelled'],
  pickup_assigned: ['pickup_in_progress', 'picked_up', 'confirmed', 'cancelled'],
  pickup_in_progress: ['picked_up', 'confirmed', 'cancelled'],
  picked_up: ['at_hub', 'out_for_delivery'],
  at_hub: ['out_for_delivery'],
  delivery_assigned: ['out_for_delivery', 'at_hub'],
  out_for_delivery: ['delivered', 'delivery_failed', 'rescheduled'],
  delivery_failed: ['rescheduled', 'out_for_delivery', 'at_hub'],
  rescheduled: ['out_for_delivery', 'at_hub'],
  return_assigned: ['returning', 'returned'],
  returning: ['returned'],
}

export const FINAL = ['delivered', 'returned', 'cancelled', 'rejected']
export const BEFORE_PICKUP = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress']

export const ASSIGNABLE = {
  pickup: ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress'],
  delivery: ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress', 'picked_up', 'at_hub', 'delivery_assigned', 'delivery_failed', 'rescheduled'],
  return: ['picked_up', 'at_hub', 'delivery_assigned', 'delivery_failed', 'rescheduled', 'return_assigned'],
}

export const ASSIGNMENT_LABELS = { pickup: 'Ramassage', delivery: 'Livraison', return: 'Retour' }

// Transitions qui demandent des informations complémentaires
export function needsReason(from, to) {
  return to === 'delivery_failed' || (to === 'confirmed' && BEFORE_PICKUP.includes(from) && from !== 'pending')
}

export function needsDate(to) {
  return to === 'rescheduled'
}
