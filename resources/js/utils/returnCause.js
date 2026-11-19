// Pourquoi un colis repart chez l'e-commerçant (champ « return_cause », contrat 1.11).
// Le retour n'est « demandé » par l'e-commerçant que pour « merchant » : le reste vient du client ou de l'agence.
const MERCHANT = {
  refused: 'Refusé par le client : le colis vous sera retourné',
  max_attempts: 'Tentatives épuisées : le colis vous sera retourné',
  company: 'L\'agence vous retourne le colis',
  merchant: 'Vous avez demandé le retour',
}

const AGENCY = {
  refused: 'Retour : refusé par le client',
  max_attempts: 'Retour : tentatives épuisées',
  company: 'Retour décidé par l\'agence',
  merchant: 'Retour demandé par l\'e-commerçant',
}

// Phrase pour l'e-commerçant (raison inconnue : retour sans raison précise)
export const merchantReturnText = (order) => MERCHANT[order?.return_cause] || 'Le colis vous sera retourné'

// Court libellé pour l'agence (badges des listes et de la fiche)
export const agencyReturnText = (order) => AGENCY[order?.return_cause] || 'Retour à organiser'
