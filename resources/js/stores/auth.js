import { defineStore } from 'pinia'
import http from '../bootstrap/axios'
import { disconnectEcho } from '../bootstrap/echo'
import { currentSpace, getCachedUser, getToken, SERVER_SPACES, setCachedUser, setSpace, setToken, spaceForPath } from '../bootstrap/session'
import { homeFor } from '../roles'
import { clearApiCache } from '../composables/useCachedApi'
import { appLock } from '../native'
import { useNotificationStore } from './notifications'

// Profil gardé sur l'appareil (par espace) pour ouvrir l'application sans réseau (PWA hors ligne)
const readCachedUser = getCachedUser
const cacheUser = setCachedUser

// Appareil retenu après le code de connexion de la console (jeton propre à ce navigateur)
const TRUSTED_DEVICE = 'jibiat.trusted-device'
function readTrustedDevice() {
  try {
    return localStorage.getItem(TRUSTED_DEVICE) || undefined
  } catch {
    return undefined
  }
}
function saveTrustedDevice(token) {
  try {
    localStorage.setItem(TRUSTED_DEVICE, token)
  } catch {
    // Navigation privée : le code sera simplement redemandé
  }
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: getToken() || null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
    role: (state) => state.user?.role ?? null,
    homeRoute: (state) => homeFor(state.user?.role),
    // Une liste de droits : l'un d'eux suffit
    can: (state) => (permission) =>
      state.user?.role === 'super_admin' || [permission].flat().some((p) => (state.user?.permissions ?? []).includes(p)),
    // Module inclus dans la formule de l'entreprise (sans entreprise ni formule : tout)
    hasFeature: (state) => (feature) => {
      const features = state.user?.company?.features
      return !Array.isArray(features) || features.includes(feature)
    },
  },

  actions: {
    async login({ login, password }) {
      const { data } = await http.post('/auth/login', {
        login,
        password,
        device_name: navigator.userAgent.slice(0, 100),
        // Seuls les comptes de l'espace de cette page de connexion y entrent (contrôlé par le serveur)
        space: SERVER_SPACES[currentSpace()],
        // Console : appareil retenu après un code de connexion (le code n'est pas redemandé)
        trusted_device: readTrustedDevice(),
      })

      // Console : un code a été envoyé par e-mail, la session s'ouvrira après ce code
      if (data.two_factor) return { twoFactor: data.two_factor }

      await this.adoptSession(data)
      return {}
    },

    // Code de connexion de la console ; « remember » : appareil retenu 30 jours
    async confirmTwoFactor({ challenge, code, remember }) {
      const { data } = await http.post('/auth/two-factor', { challenge, code, remember })
      if (data.trusted_device) saveTrustedDevice(data.trusted_device)
      await this.adoptSession(data)
    },

    // Session renvoyée par le serveur (mot de passe ou WhatsApp) : refusée si l'application est limitée à un autre espace
    async adoptSession(data) {
      // Application livreur ou e-commerçant (installée ou Android) : seuls les comptes de son espace
      const lock = appLock()
      if (lock && !lock.roles.includes(data.user?.role)) {
        await http.post('/auth/logout', null, { headers: { Authorization: `Bearer ${data.token}` } }).catch(() => {})
        throw Object.assign(new Error(lock.other), { response: { data: { message: lock.other } } })
      }

      this.startSession(data)
    },

    // Session ouverte par le serveur : connexion, fin d'inscription, nouveau mot de passe
    startSession({ token, user }) {
      // Rangée dans l'espace du compte (mot de passe oublié, inscription : pages communes)
      setSpace(spaceForPath(homeFor(user?.role)))
      this.token = token
      this.user = user
      setToken(token)
      cacheUser(user)
    },

    /**
     * Page d'un autre espace (ex. espace livreur ouvert après l'espace e-commerçant) : on reprend la
     * session de cet espace, jamais celle de l'autre.
     */
    useSpace(space) {
      if (!setSpace(space)) return
      disconnectEcho()
      clearApiCache()
      // Cloche de l'autre compte : rechargée pour le nouvel espace
      useNotificationStore().reset()
      this.token = getToken() || null
      this.user = null
    },

    /**
     * Recharge le profil. Sans réseau, reprend le profil gardé sur l'appareil ;
     * seule une session refusée par le serveur (401) entraîne la déconnexion.
     */
    async fetchUser() {
      try {
        const { data } = await http.get('/auth/me')
        this.user = data.data
        cacheUser(data.data)
      } catch (error) {
        const cached = readCachedUser()
        if (!error.response && cached) {
          this.user = cached
          return
        }
        throw error
      }
    },

    async logout() {
      try {
        if (this.token) await http.post('/auth/logout')
      } finally {
        this.clear()
      }
    },

    clear() {
      disconnectEcho()
      this.user = null
      this.token = null
      setToken(null)
      clearApiCache()
      useNotificationStore().reset()
      cacheUser(null)
    },
  },
})
