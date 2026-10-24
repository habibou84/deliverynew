/** Mots affichés sous les étoiles */
export const SCORE_WORDS = { 1: 'Mauvais', 2: 'Pas bien', 3: 'Moyen', 4: 'Bien', 5: 'Excellent' }

/** Note décimale à la française : 4,7 */
export const scoreText = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(1).replace('.', ','))
