// Réception au dépôt des colis d'un livreur : colis à rendre cochés par défaut ; un colis à rendre décoché demande un motif

export const GAP_REASONS = [
  { value: 'not_presented', label: 'Pas présenté par le livreur' },
  { value: 'damaged', label: 'Colis abîmé, refusé' },
  { value: 'other', label: 'Autre (préciser)' },
]

// Contrôle du bac : le colis déposé n'y est pas
export const DROP_REASONS = [
  { value: 'not_found', label: 'Introuvable dans le bac' },
  { value: 'damaged', label: 'Colis abîmé, refusé' },
  { value: 'other', label: 'Autre (préciser)' },
]

/** Colis cochés au départ : ceux que le livreur doit rendre */
export const defaultReturned = (parcels) => parcels.filter((p) => p.due).map((p) => p.id)

/** Colis à rendre non cochés */
export const uncheckedDue = (parcels, returned) => parcels.filter((p) => p.due && !returned.includes(p.id))

/** Codes des colis décochés dont le motif manque (ou « Autre » sans précision) */
export function incomplete(list, reasons) {
  return list.filter((p) => !reasons[p.id]?.reason || (reasons[p.id].reason === 'other' && !reasons[p.id].note?.trim())).map((p) => p.tracking_code)
}

/** Données envoyées : colis reçus et colis à rendre non réceptionnés avec leur motif */
export function receptionPayload(parcels, returned, reasons) {
  return {
    order_ids: returned,
    missing: uncheckedDue(parcels, returned).map((p) => ({ order_id: p.id, reason: reasons[p.id]?.reason, note: reasons[p.id]?.note?.trim() || undefined })),
  }
}

/** Code de suivi lu sur l'étiquette : le QR code mène à …/suivi/CODE ; sinon le texte lu ou tapé */
export function codeFromScan(text) {
  const raw = String(text || '').trim()
  const match = raw.match(/\/suivi\/([^/?#\s]+)/i)
  return decodeURIComponent(match ? match[1] : raw).toUpperCase()
}
