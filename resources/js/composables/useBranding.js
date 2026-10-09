import { reactive } from 'vue'
import http from '../bootstrap/axios'

const CACHE_KEY = 'branding'

function readCache() {
  try {
    return JSON.parse(localStorage.getItem(CACHE_KEY)) || null
  } catch {
    return null
  }
}

// Nom, logo et accroche de l'entreprise de livraison (pages publiques). Affichage
// immédiat depuis le dernier chargement, puis mise à jour depuis le serveur.
export const branding = reactive({ name: '', tagline: '', phone: '', email: '', address: '', logo_url: null, loaded: false, ...readCache() })

let loading = null

export function loadBranding(force = false) {
  if (loading && !force) return loading
  loading = http.get('/branding').then(({ data }) => {
    Object.assign(branding, data.data, { loaded: true })
    try { localStorage.setItem(CACHE_KEY, JSON.stringify(data.data)) } catch { /* stockage indisponible */ }
    if (data.data.name) document.title = data.data.name
  }).catch(() => { branding.loaded = true })
  return loading
}

// Numéro au format wa.me (chiffres seulement, indicatif compris)
export function whatsappLink(phone, text = '') {
  const digits = String(phone || '').replace(/\D/g, '')
  return digits ? `https://wa.me/${digits}${text ? `?text=${encodeURIComponent(text)}` : ''}` : null
}
