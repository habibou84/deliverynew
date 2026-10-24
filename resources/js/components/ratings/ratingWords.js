/** Mots affichés sous les étoiles */
export const SCORE_WORDS = { 1: 'Mauvais', 2: 'Pas bien', 3: 'Moyen', 4: 'Bien', 5: 'Excellent' }

/** Note décimale à la française : 4,7 */
export const scoreText = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(1).replace('.', ','))

/** Remarques de l'e-commerçant (contrat Contract::MERCHANT_RATING_TAGS) */
export const MERCHANT_TAGS = [
  { code: 'pickup_on_time', label: 'Ramassage à l\'heure', good: true },
  { code: 'careful', label: 'Colis bien traité', good: true },
  { code: 'good_with_customers', label: 'Bon contact avec mes clients', good: true },
  { code: 'cash_returned', label: 'Argent bien rendu', good: true },
  { code: 'pickup_late', label: 'Ramassage en retard', good: false },
  { code: 'customer_mistreated', label: 'Client mal traité', good: false },
  { code: 'unreachable', label: 'Pas joignable', good: false },
]
