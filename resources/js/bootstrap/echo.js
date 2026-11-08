import { getToken } from './session'
import { nativeApp, siteOrigin } from '../native'

let echo = null
let loading = null

/**
 * Connexion WebSocket (Laravel Reverb), authentifiée par le jeton Sanctum.
 * Les bibliothèques du direct (Echo, Pusher) sont chargées à part, après l'affichage : elles ne ralentissent
 * pas l'ouverture de l'application. Renvoie null si Reverb n'est pas configuré (VITE_REVERB_APP_KEY vide).
 *
 * @returns {Promise<import('laravel-echo').default|null>}
 */
export function loadEcho() {
  if (echo || !import.meta.env.VITE_REVERB_APP_KEY) {
    return Promise.resolve(echo)
  }

  loading ??= Promise.all([import('laravel-echo'), import('pusher-js')])
    .then(([{ default: Echo }, { default: Pusher }]) => {
      window.Pusher = Pusher
      echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        // Sans hôte fixe : l'adresse ouverte (chaque entreprise a la sienne, le serveur web
        // y relaie /app/ vers Reverb)
        wsHost: import.meta.env.VITE_REVERB_HOST || (nativeApp ? new URL(siteOrigin()).hostname : window.location.hostname),
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: `${nativeApp ? siteOrigin() : ''}/api/broadcasting/auth`,
        auth: {
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${getToken()}`,
          },
        },
      })

      return echo
    })
    .catch(() => {
      // Hors ligne au chargement : on réessaiera au prochain écran
      loading = null
      return null
    })

  return loading
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
  loading = null
}
