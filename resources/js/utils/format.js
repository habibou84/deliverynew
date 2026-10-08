// Formatage commun (montants en FCFA, dates, statuts)

const moneyFormatter = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })

export function money(value) {
  if (value === null || value === undefined || value === '') return '—'
  return `${moneyFormatter.format(value)} F`
}

export function dateTime(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString('fr-FR', {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

export function date(value) {
  if (!value) return '—'
  return new Date(value.length === 10 ? `${value}T00:00:00` : value).toLocaleDateString('fr-FR')
}

export function today() {
  const d = new Date()
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}

// Couleur du badge par statut de course
const STATUS_COLORS = {
  pending: 'bg-amber-100 text-amber-800',
  confirmed: 'bg-sky-100 text-sky-800',
  rejected: 'bg-gray-200 text-gray-700',
  pickup_assigned: 'bg-indigo-100 text-indigo-800',
  pickup_in_progress: 'bg-indigo-100 text-indigo-800',
  picked_up: 'bg-violet-100 text-violet-800',
  at_hub: 'bg-violet-100 text-violet-800',
  delivery_assigned: 'bg-blue-100 text-blue-800',
  out_for_delivery: 'bg-blue-600 text-white',
  delivered: 'bg-emerald-600 text-white',
  delivery_failed: 'bg-red-100 text-red-800',
  rescheduled: 'bg-orange-100 text-orange-800',
  return_assigned: 'bg-rose-100 text-rose-800',
  returning: 'bg-rose-100 text-rose-800',
  returned: 'bg-gray-200 text-gray-700',
  cancelled: 'bg-gray-200 text-gray-700',
}

export function statusClass(status) {
  return STATUS_COLORS[status] ?? 'bg-gray-100 text-gray-700'
}

export const STATUS_LABELS = {
  pending: 'En attente de validation',
  confirmed: 'Validée',
  rejected: 'Refusée',
  pickup_assigned: 'Ramassage assigné',
  pickup_in_progress: 'En route pour le ramassage',
  picked_up: 'Récupéré',
  at_hub: 'Au dépôt',
  delivery_assigned: 'Livraison assignée',
  out_for_delivery: 'En chemin',
  delivered: 'Livré',
  delivery_failed: 'Échec de livraison',
  rescheduled: 'Reporté',
  return_assigned: 'Retour assigné',
  returning: 'Retour en cours',
  returned: 'Retourné',
  cancelled: 'Annulée',
}

export const EVENT_LABELS = {
  created: 'Course créée',
  edited: 'Course modifiée',
  status_changed: 'Changement de statut',
  assigned: 'Mission assignée',
  assignment_accepted: 'Mission acceptée',
  assignment_refused: 'Mission refusée',
  note: 'Note',
  incident: 'Incident',
  return_requested: 'Retour demandé',
  proof_added: 'Preuve ajoutée',
}

export const ROLE_LABELS = {
  super_admin: 'Super admin',
  admin: 'Administrateur',
  dispatcher: 'Dispatcher',
  cashier: 'Caissier',
  hub_agent: 'Agent de dépôt',
  courier: 'Livreur',
  merchant_owner: 'E-commerçant',
  merchant_staff: 'Employé e-commerçant',
}

export function telLink(phone) {
  return phone ? `tel:${phone}` : null
}

export function whatsappLink(phone) {
  return phone ? `https://wa.me/${phone.replace(/\D/g, '')}` : null
}

export function mapsLink(lat, lng, address) {
  if (lat && lng) return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`
  if (address) return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${address}, Abidjan`)}`
  return null
}
