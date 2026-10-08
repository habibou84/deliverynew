import { defineStore } from 'pinia'
import http, { TOKEN_KEY } from '../bootstrap/axios'
import { disconnectEcho } from '../bootstrap/echo'
import { homeFor } from '../roles'

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

      this.token = data.token
      this.user = data.user
      localStorage.setItem(TOKEN_KEY, data.token)
    },

    async fetchUser() {
      const { data } = await http.get('/auth/me')
      this.user = data.data
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
    },
  },
})
