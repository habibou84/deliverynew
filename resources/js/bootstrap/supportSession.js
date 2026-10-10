import { TOKEN_KEY } from './axios'

// Session d'assistance ouverte depuis la console : le jeton arrive dans le fragment (#support=…),
// jamais envoyé au serveur. Il est gardé puis retiré de l'adresse AVANT la création du routeur,
// qui sinon le remettrait dans l'adresse (et l'historique).
if (window.location.hash.startsWith('#support=')) {
  try {
    localStorage.setItem(TOKEN_KEY, decodeURIComponent(window.location.hash.slice('#support='.length)))
    localStorage.removeItem('user')
  } catch {
    // stockage indisponible : la session ne peut pas être ouverte dans ce navigateur
  }
  window.history.replaceState(null, '', window.location.pathname + window.location.search)
}
