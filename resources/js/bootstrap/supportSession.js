import { TOKEN_KEY } from './axios'

// Session ouverte depuis l'adresse de la console : assistance (#support=…) ou fin de l'inscription
// d'une entreprise (#session=…). Le jeton arrive dans le fragment, jamais envoyé au serveur. Il est
// gardé puis retiré de l'adresse AVANT la création du routeur, qui sinon le remettrait dans
// l'adresse (et l'historique).
const handoff = window.location.hash.match(/^#(support|session)=(.+)$/)
if (handoff) {
  try {
    localStorage.setItem(TOKEN_KEY, decodeURIComponent(handoff[2]))
    localStorage.removeItem('user')
  } catch {
    // stockage indisponible : la session ne peut pas être ouverte dans ce navigateur
  }
  window.history.replaceState(null, '', window.location.pathname + window.location.search)
}
