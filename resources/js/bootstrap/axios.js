import axios from 'axios'

axios.defaults.baseURL = import.meta.env.VITE_API_URL
axios.defaults.headers.common['Accept'] = 'application/json'

// Si un token existe déjà au chargement
const token = localStorage.getItem('token')
if (token) {
  axios.defaults.headers.common['Authorization'] = `Bearer ${token}`
}

export default axios
