import axios from 'axios'

export const TOKEN_KEY = 'token'

const http = axios.create({
  baseURL: '/api/v1',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Jeton expiré ou révoqué : on nettoie et on renvoie vers la connexion
http.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const isLogin = error.config?.url?.includes('/auth/login')

    if (status === 401 && !isLogin) {
      localStorage.removeItem(TOKEN_KEY)
      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }
    }

    return Promise.reject(error)
  },
)

/**
 * Extrait un message lisible d'une erreur d'API ({ message, errors? }).
 */
export function apiErrorMessage(error, fallback = 'Une erreur est survenue.') {
  if (error.isAxiosError && !error.response) {
    return 'Pas de connexion. Réessayez quand le réseau revient.'
  }
  const data = error.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first.length) return first[0]
  }
  return data?.message || fallback
}

export default http
