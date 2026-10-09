import { defineStore } from 'pinia'
import http, { TOKEN_KEY } from '../bootstrap/axios'
import { disconnectEcho } from '../bootstrap/echo'
import { homeFor } from '../roles'
import { clearApiCache } from '../composables/useCachedApi'

// Profil gardé sur l'appareil pour ouvrir l'application sans réseau (PWA hors ligne)
const USER_KEY = 'user'

function readCachedUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null')
  } catch {
    return null
  }
}

function cacheUser(user) {
  try {
    if (user) localStorage.setItem(USER_KEY, JSON.stringify(user))
    else localStorage.removeItem(USER_KEY)
  } catch {
    // stockage indisponible : l'application demandera simplement le réseau au démarrage
  }
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem(TOKEN_KEY) || null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
    role: (state) => state.user?.role ?? null,
    homeRoute: (state) => homeFor(state.user?.role),
    can: (state) => (permission) =>
      state.user?.role === 'super_admin' || (state.user?.permissions ?? []).includes(permission),
  },

  actions: {
    async login({ login, password }) {
      const { data } = await http.post('/auth/login', {
        login,
        password,
        device_name: navigator.userAgent.slice(0, 100),
      })

      this.startSession(data)
    },

    // Session ouverte par le serveur : connexion, fin d'inscription, nouveau mot de passe
    startSession({ token, user }) {
      this.token = token
      this.user = user
      localStorage.setItem(TOKEN_KEY, token)
      cacheUser(user)
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
      localStorage.removeItem(TOKEN_KEY)
      clearApiCache()
      cacheUser(null)
    },
  },
})
