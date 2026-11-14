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

// Charte : cinq tons d'état, chacun un seul sens (succès, attention, critique, info, neutre)
const TONES = {
  succes: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  attention: 'bg-amber-50 text-amber-800 ring-amber-200',
  critique: 'bg-red-50 text-red-700 ring-red-200',
  info: 'bg-blue-50 text-blue-800 ring-blue-200',
  neutre: 'bg-slate-100 text-slate-700 ring-slate-300',
}
export const STATUS_TONES = {
  pending: 'attention',
  confirmed: 'neutre',
  rejected: 'neutre',
  pickup_assigned: 'info',
  pickup_in_progress: 'info',
  picked_up: 'info',
  at_hub: 'info',
  delivery_assigned: 'info',
  out_for_delivery: 'info',
  delivered: 'succes',
  delivery_failed: 'critique',
  rescheduled: 'attention',
  return_assigned: 'info',
  returning: 'info',
  returned: 'neutre',
  cancelled: 'neutre',
  lost: 'critique',
}

export function toneClass(tone) {
  return TONES[tone] ?? TONES.neutre
}

export function statusClass(status) {
  return toneClass(STATUS_TONES[status])
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
  lost: 'Perdu',
}

export const EVENT_LABELS = {
  created: 'Course créée',
  edited: 'Course modifiée',
  status_changed: 'Changement de statut',
  assigned: 'Mission assignée',
  assignment_accepted: 'Mission acceptée',
  assignment_refused: 'Mission refusée',
  unassigned: 'Livreur retiré',
  note: 'Note',
  incident: 'Incident',
  return_requested: 'Retour demandé',
  proof_added: 'Preuve ajoutée',
  expense_added: 'Frais ajoutés',
  expense_cancelled: 'Frais annulés',
  parcel_received: 'Colis rendu au dépôt',
  relay: 'Relais entre livreurs',
}

export const ROLE_LABELS = {
  super_admin: 'Propriétaire',
  platform_support: 'Support',
  platform_accountant: 'Comptable',
  admin: 'Administrateur',
  dispatcher: 'Dispatcher',
  cashier: 'Caissier',
  hub_agent: 'Agent de dépôt',
  courier: 'Livreur',
  merchant_owner: 'E-commerçant',
  merchant_staff: 'Employé e-commerçant',
}

/** Numéro ivoirien tel qu'on le tape : +2250700000001 → 07 00 00 00 01 */
export function localPhone(phone) {
  if (!phone) return ''
  const digits = String(phone).replace(/^\+225/, '')
  return /^\d{10}$/.test(digits) ? digits.replace(/(\d{2})(?=\d)/g, '$1 ') : String(phone)
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

export const PAYMENT_METHODS = {
  cash: 'Espèces',
  wave: 'Wave',
  orange_money: 'Orange Money',
  mtn_momo: 'MTN MoMo',
  moov_money: 'Moov Money',
  bank: 'Virement bancaire',
}

export const DELIVERY_PAYMENT_METHODS = ['cash', 'wave', 'orange_money', 'mtn_momo', 'moov_money']

// Montant signé : vert si crédit, rouge si débit
export function signedClass(value) {
  if (!value) return 'text-slate-600'
  return value > 0 ? 'text-emerald-700' : 'text-red-600'
}

// Durée d'attente lisible : 25 min, 1 h 05, 2 j 3 h
export function waitDuration(minutes) {
  if (minutes < 60) return `${minutes} min`
  const hours = Math.floor(minutes / 60)
  if (hours >= 24) return `${Math.floor(hours / 24)} j ${hours % 24} h`
  const rest = minutes % 60
  return rest ? `${hours} h ${String(rest).padStart(2, '0')}` : `${hours} h`
}

// Taux en centièmes de pour cent (100 = 1 %, 75 = 0,75 %)
export function rate(value) {
  return value === null || value === undefined ? '' : `${(value / 100).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`
}
