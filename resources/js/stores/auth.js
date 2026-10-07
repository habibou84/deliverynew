import { defineStore } from 'pinia'
import axios from '../bootstrap/axios'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('token') || null
  }),

  actions: {
    async login(credentials) {
      const res = await axios.post('/login', credentials)

      this.token = res.data.access_token
      this.user = res.data.user

      localStorage.setItem('token', this.token)
      axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`
    },

    async logout() {
      await axios.post('/logout')

      this.user = null
      this.token = null

      localStorage.removeItem('token')
      delete axios.defaults.headers.common['Authorization']
    },

    async fetchUser() {
      const res = await axios.get('/me')
      this.user = res.data
    }
  }
})
