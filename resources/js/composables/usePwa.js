import { reactive } from 'vue'

// État partagé : installation de l'application et connexion réseau
export const pwa = reactive({
  canInstall: false, // Android/Chrome : invite d'installation disponible
  installed: window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true,
  isIos: /iphone|ipad|ipod/i.test(window.navigator.userAgent),
  online: window.navigator.onLine,
})

let deferredPrompt = null

export function setupPwa() {
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferredPrompt = event
    pwa.canInstall = true
  })
  window.addEventListener('appinstalled', () => {
    pwa.installed = true
    pwa.canInstall = false
  })
  window.addEventListener('online', () => { pwa.online = true })
  window.addEventListener('offline', () => { pwa.online = false })

  // Service worker uniquement sur le build de production (pas avec le serveur Vite)
  if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}))
  }
}

export async function promptInstall() {
  if (!deferredPrompt) return false
  deferredPrompt.prompt()
  const { outcome } = await deferredPrompt.userChoice
  deferredPrompt = null
  pwa.canInstall = false
  return outcome === 'accepted'
}

const THEMES = { marchand: '#047857', livreur: '#1d4ed8' }

/**
 * Adapte le manifeste et la couleur de la barre du téléphone à l'espace affiché
 * (navigation interne de l'application, sans rechargement de page).
 */
export function applyAppTheme(path) {
  const app = path.startsWith('/livreur') ? 'livreur' : path.startsWith('/marchand') ? 'marchand' : null
  if (!app) return

  const set = (selector, create, attr, value) => {
    let el = document.head.querySelector(selector)
    if (!el) {
      el = create()
      document.head.appendChild(el)
    }
    el.setAttribute(attr, value)
  }
  const link = (rel) => () => Object.assign(document.createElement('link'), { rel })

  set('link[rel="manifest"]', link('manifest'), 'href', `/manifest/${app}.webmanifest`)
  set('link[rel="apple-touch-icon"]', link('apple-touch-icon'), 'href', `/icons/${app}-apple-180.png`)
  set('meta[name="theme-color"]', () => Object.assign(document.createElement('meta'), { name: 'theme-color' }), 'content', THEMES[app])
}
