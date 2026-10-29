// Sonnerie des missions : application Android du livreur (module natif « Ring », notifications Firebase).
// Le téléphone sonne comme un appel pour une nouvelle mission ; cet écran-ci enregistre le téléphone,
// ouvre l'écran « appel entrant » quand on touche la sonnerie et arrête la sonnerie après la réponse.
import { Capacitor, registerPlugin } from '@capacitor/core'
import http from '../bootstrap/axios'
import { nativeApp } from './index'

const Ring = registerPlugin('Ring')
const SEEN_KEY = 'ring-setup-seen'

export const ringAvailable = () => nativeApp === 'livreur' && Capacitor.isNativePlatform() && Capacitor.getPlatform() === 'android' && Capacitor.isPluginAvailable('Ring')

export async function ringStatus() {
  if (!ringAvailable()) return null
  try {
    return await Ring.status()
  } catch {
    return null
  }
}

/** Tout est réglé pour que le téléphone sonne (notifications, écran verrouillé) */
export const ringReady = (s) => !!(s?.available && s.notifications && s.fullScreen)

export async function requestNotifications() {
  await Ring.requestPermissions().catch(() => {})
  return ringStatus()
}
export const openFullScreenSettings = () => Ring.openFullScreenSettings().catch(() => {})
export const openDndSettings = () => Ring.openDndSettings().catch(() => {})
export const testRing = () => Ring.test().catch(() => {})
export const stopRing = () => (ringAvailable() ? Ring.stop().catch(() => {}) : Promise.resolve())

/**
 * À l'ouverture de l'application livreur : téléphone enregistré pour la sonnerie, écran « appel entrant » ouvert
 * si l'application a été lancée par la sonnerie, et une fois l'écran des autorisations proposé.
 */
export async function setupRing(router) {
  if (!ringAvailable()) return
  const open = (path) => path && router.currentRoute.value.fullPath !== path && router.push(path)

  Ring.addListener('ring', ({ path }) => open(path))
  const pending = await Ring.consumePath().catch(() => null)
  if (pending?.path) open(pending.path)

  const status = await ringStatus()
  if (!status?.available) return
  try {
    const { token } = await Ring.getToken()
    if (token) await http.post('/courier/devices', { token, platform: 'android' })
  } catch {
    // Pas de réseau : nouvel essai à la prochaine ouverture
  }

  let seen = false
  try {
    seen = localStorage.getItem(SEEN_KEY) === '1'
  } catch {
    seen = true
  }
  if (!pending?.path && !seen && !ringReady(status)) {
    try {
      localStorage.setItem(SEEN_KEY, '1')
    } catch {
      // stockage indisponible : l'écran reste accessible depuis le profil
    }
    router.push('/livreur/sonnerie')
  }
}

/** Déconnexion : ce téléphone ne sonne plus pour ce compte */
export async function forgetRingDevice() {
  if (!ringAvailable()) return
  try {
    const { token } = await Ring.getToken()
    if (token) await http.delete('/courier/devices', { data: { token } })
  } catch {
    // déjà oublié ou hors ligne
  }
}
