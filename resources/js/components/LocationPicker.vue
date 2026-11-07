<template>
  <div class="space-y-2">
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="buttonClass" :disabled="locating" @click="useMyPosition">
        {{ locating ? 'Localisation…' : gpsLabel }}
      </button>
      <form v-if="allowLink" class="flex flex-1 min-w-56 gap-2" @submit.prevent="useLink">
        <input v-model.trim="link" class="input flex-1" placeholder="Coller un lien Google Maps ou « 5.36, -3.97 »" aria-label="Lien Google Maps">
        <button type="submit" class="btn-secondary" :disabled="!link || resolving">{{ resolving ? '…' : 'Placer' }}</button>
      </form>
    </div>
    <!-- Résultat de la localisation : encadré bien visible (une petite ligne passait inaperçue) -->
    <div v-if="message" ref="messageEl" :class="['rounded-xl px-3.5 py-3 text-[14.5px] leading-snug', tone === 'error' ? 'bg-rose-50 text-rose-900 ring-1 ring-rose-200' : tone === 'warn' ? 'bg-amber-50 text-amber-900 ring-1 ring-amber-200' : 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200']" role="status" aria-live="polite">
      <p class="font-semibold">{{ message }}</p>
      <ol v-if="steps.length" class="mt-1.5 list-decimal space-y-0.5 pl-5">
        <li v-for="st in steps" :key="st">{{ st }}</li>
      </ol>
    </div>

    <div class="relative rounded-xl overflow-hidden ring-1 ring-slate-200">
      <div ref="mapEl" :class="['w-full z-0', height]" role="application" aria-label="Carte : touchez pour placer le repère" />
      <p v-if="!hasPoint" class="absolute inset-x-3 top-3 z-[400] rounded-lg bg-white/95 px-3 py-2 text-xs text-slate-600 shadow">
        Touchez la carte à l'emplacement exact, ou utilisez votre position.
      </p>
    </div>
    <p v-if="hasPoint" class="text-xs text-slate-500">Déplacez le repère ou touchez la carte pour corriger.</p>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import http, { apiErrorMessage } from '../bootstrap/axios'

/**
 * Choix d'un point sur la carte : position GPS du téléphone, lien Google Maps collé,
 * ou repère placé à la main. v-model : { lat, lng, accuracy } ou null.
 */
const props = defineProps({
  modelValue: { type: Object, default: null },
  allowLink: { type: Boolean, default: false },
  gpsLabel: { type: String, default: '📍 Utiliser ma position actuelle' },
  buttonClass: { type: String, default: 'btn-secondary' },
  height: { type: String, default: 'h-64' },
})
const emit = defineEmits(['update:modelValue'])

const ABIDJAN = [5.345, -4.024]
// Repère en forme d'épingle, pointe sur la position
const PIN = L.divIcon({
  className: '',
  iconSize: [32, 40],
  iconAnchor: [16, 40],
  html: '<div class="location-pin"><span>📦</span></div>',
})
const mapEl = ref(null)
const locating = ref(false)
const resolving = ref(false)
const link = ref('')
const message = ref('')
const tone = ref('ok') // ok | warn | error
const steps = ref([])
const messageEl = ref(null)
let map = null
let marker = null
let circle = null

const hasPoint = computed(() => props.modelValue?.lat != null && props.modelValue?.lng != null)

function say(text, kind = false, list = []) {
  message.value = text
  tone.value = kind === true ? 'error' : kind || 'ok'
  steps.value = list
  if (text) nextTick(() => messageEl.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))
}

function place(lat, lng, accuracy = null, zoom = true) {
  emit('update:modelValue', { lat: Number(lat.toFixed(7)), lng: Number(lng.toFixed(7)), accuracy })
  if (zoom && map) map.setView([lat, lng], Math.max(map.getZoom(), 17))
}

function draw() {
  if (!map) return
  if (!hasPoint.value) {
    marker?.remove()
    circle?.remove()
    marker = circle = null
    return
  }
  const { lat, lng, accuracy } = props.modelValue
  if (!marker) {
    marker = L.marker([lat, lng], { draggable: true, autoPan: true, icon: PIN, title: 'Lieu de ramassage' }).addTo(map)
    marker.on('dragend', () => {
      const p = marker.getLatLng()
      place(p.lat, p.lng, null, false)
    })
  } else {
    marker.setLatLng([lat, lng])
  }
  circle?.remove()
  circle = accuracy ? L.circle([lat, lng], { radius: accuracy, color: '#2563eb', weight: 1, fillOpacity: 0.08 }).addTo(map) : null
}

// ── Ma position ──
// 1. Position approximative tout de suite (réseau, ou dernière position connue) : le repère se place en 1 à 2 s.
// 2. En même temps, le GPS affine pendant 25 s au plus ; le repère suit tant que la précision s'améliore.
// 3. Rien du tout au bout de 25 s (fenêtre d'autorisation ignorée, GPS muet) : on le dit, jamais d'attente sans fin.
const GOOD = 30 // mètres : assez précis, on arrête de chercher
const LIMIT = 25000
let watchId = null
let stopTimer = null
let best = null

function stopLocating() {
  if (watchId !== null) navigator.geolocation.clearWatch(watchId)
  watchId = null
  clearTimeout(stopTimer)
  locating.value = false
}

function onFix(p) {
  const accuracy = Math.round(p.coords.accuracy)
  if (best && accuracy >= best) return
  const first = best === null
  best = accuracy
  place(p.coords.latitude, p.coords.longitude, accuracy, true)
  if (first) nextTick(() => mapEl.value?.scrollIntoView({ block: 'center', behavior: 'smooth' }))
  if (accuracy <= GOOD) {
    stopLocating()
    say(`Position trouvée (à ${accuracy} m près). Vérifiez le repère sur la carte.`)
  } else {
    say(`Position trouvée à ${accuracy} m près${locating.value ? ' : on affine…' : '.'}`, 'warn',
      locating.value ? [] : ['Déplacez le repère du doigt pour le poser exactement sur votre boutique.'])
  }
}

const HELP = {
  // Refusée pour ce site
  1: ['La localisation est bloquée pour Jibiat.', [
    'Dans Chrome, touchez ⋮ (en haut à droite) › Paramètres › Paramètres des sites › Position.',
    'Autorisez ce site, puis touchez à nouveau le bouton.',
    'Ou touchez la carte à l\'emplacement de votre boutique.',
  ]],
  // Le téléphone ne trouve pas de position (localisation coupée)
  2: ['Le téléphone ne trouve pas votre position.', [
    'Activez la localisation : tirez le haut de l\'écran vers le bas, puis touchez « Localisation ».',
    'Touchez à nouveau le bouton.',
    'Ou touchez la carte à l\'emplacement de votre boutique.',
  ]],
  // Trop long
  3: ['La position met trop de temps à arriver.', [
    'Vérifiez que la localisation du téléphone est activée.',
    'Approchez-vous d\'une fenêtre ou sortez, puis touchez à nouveau le bouton.',
    'Ou touchez la carte à l\'emplacement de votre boutique.',
  ]],
}
function fail(code) {
  stopLocating()
  if (best !== null) {
    say(`Position trouvée à ${best} m près.`, 'warn', ['Déplacez le repère du doigt pour le poser exactement sur votre boutique.'])
    return
  }
  const [text, list] = HELP[code] || HELP[3]
  say(text, true, list)
}

async function useMyPosition() {
  if (!navigator.geolocation) return say('La localisation n\'est pas disponible sur cet appareil.', true, ['Touchez la carte à l\'emplacement de votre boutique.'])
  stopLocating()
  best = null
  say('')
  // Déjà refusée : pas la peine d'attendre, on explique tout de suite comment l'autoriser
  try {
    if ((await navigator.permissions?.query({ name: 'geolocation' }))?.state === 'denied') return fail(1)
  } catch {
    // Navigateur sans cette question : on essaie directement
  }
  locating.value = true
  say('Recherche de votre position…', 'warn', ['Si le téléphone demande l\'autorisation, touchez « Autoriser ».'])
  let errors = 0
  const onError = (e) => {
    errors++
    // Refus : inutile d'attendre le GPS ; sinon on laisse sa chance à l'autre demande
    if (e.code === 1 || errors >= 2) fail(e.code)
  }
  navigator.geolocation.getCurrentPosition(onFix, onError, { enableHighAccuracy: false, maximumAge: 120000, timeout: 10000 })
  watchId = navigator.geolocation.watchPosition(onFix, onError, { enableHighAccuracy: true, maximumAge: 0, timeout: LIMIT })
  stopTimer = setTimeout(() => fail(3), LIMIT)
}

async function useLink() {
  resolving.value = true
  say('')
  try {
    const { data } = await http.post('/geo/link', { link: link.value })
    place(data.data.lat, data.data.lng)
    link.value = ''
    say('Repère placé d\'après le lien. Vérifiez-le sur la carte.')
  } catch (e) {
    say(apiErrorMessage(e), true)
  } finally {
    resolving.value = false
  }
}

watch(() => props.modelValue, draw, { deep: true })

onMounted(() => {
  map = L.map(mapEl.value, { zoomControl: true }).setView(hasPoint.value ? [props.modelValue.lat, props.modelValue.lng] : ABIDJAN, hasPoint.value ? 17 : 12)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map)
  map.on('click', (e) => place(e.latlng.lat, e.latlng.lng, null, false))
  draw()
  // La carte peut être montée dans une fenêtre qui s'ouvre : recalcule sa taille
  setTimeout(() => map?.invalidateSize(), 250)
})

onBeforeUnmount(() => {
  stopLocating()
  map?.remove()
  map = null
})
</script>

<style scoped>
:deep(.location-pin) {
  width: 32px;
  height: 32px;
  border-radius: 50% 50% 50% 0;
  transform: rotate(-45deg);
  background: #ea580c;
  border: 3px solid #fff;
  box-shadow: 0 2px 6px rgb(0 0 0 / 0.4);
  display: grid;
  place-items: center;
}

:deep(.location-pin span) {
  transform: rotate(45deg);
  font-size: 14px;
}
</style>
