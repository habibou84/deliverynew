import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { TOKEN_KEY } from './axios'

let echo = null

/**
 * Connexion WebSocket (Laravel Reverb), authentifiée par le jeton Sanctum.
 * Retourne null si Reverb n'est pas configuré (VITE_REVERB_APP_KEY vide).
 */
export function useEcho() {
  if (echo || !import.meta.env.VITE_REVERB_APP_KEY) {
    return echo
  }

  window.Pusher = Pusher

  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    // Sans hôte fixe : l'adresse ouverte (chaque entreprise a la sienne, le serveur web
    // y relaie /app/ vers Reverb)
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
    auth: {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem(TOKEN_KEY)}`,
      },
    },
  })

  return echo
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
}
