<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">{{ track ? `Trajet de ${track.courier.name}` : 'Carte des livreurs' }}</h1>
      <div v-if="!track" class="flex flex-wrap items-center gap-3 text-sm">
        <span class="flex items-center gap-1"><i class="legend bg-blue-600" /> En mission</span>
        <span class="flex items-center gap-1"><i class="legend bg-emerald-600" /> Disponible</span>
        <span class="flex items-center gap-1"><i class="legend bg-slate-400" /> Hors service</span>
        <label class="flex items-center gap-2"><input v-model="showOffDuty" type="checkbox"> Afficher les livreurs hors service</label>
      </div>
    </div>

    <div class="grid lg:grid-cols-[1fr_20rem] gap-4">
      <div class="card overflow-hidden relative">
        <div ref="mapEl" class="h-[60vh] lg:h-[75vh] w-full z-0" role="application" aria-label="Carte des livreurs" />
        <p v-if="track && !track.points.length && !track.stops.length" class="absolute inset-x-4 top-4 z-[400] rounded bg-white/95 p-3 text-sm shadow">
          Aucun déplacement enregistré ce jour-là.
        </p>
        <p v-else-if="!track && loaded && !located.length" class="absolute inset-x-4 top-4 z-[400] rounded bg-white/95 p-3 text-sm shadow">
          Aucune position pour le moment : la position d'un livreur apparaît quand il est en service dans son application
          (localisation autorisée sur son téléphone).
        </p>
      </div>

      <!-- Trajet d'une journée -->
      <aside v-if="track" class="card max-h-[75vh] overflow-y-auto">
        <div class="p-3 space-y-2 border-b">
          <button type="button" class="text-sm text-blue-600" @click="closeTrack">← Carte en direct</button>
          <div class="flex gap-2">
            <input v-model="track.date" type="date" class="input" :max="today()" aria-label="Date du trajet" @change="loadTrack">
          </div>
          <div v-if="track.recent_days?.length" class="flex flex-wrap gap-1">
            <button
              v-for="d in track.recent_days.slice(0, 7)"
              :key="d"
              type="button"
              :class="['rounded-full px-2 py-0.5 text-xs ring-1', d === track.date ? 'bg-slate-900 text-white ring-transparent' : 'ring-slate-300']"
              @click="track.date = d; loadTrack()"
            >
              {{ shortDate(d) }}
            </button>
          </div>
        </div>
        <div v-if="track.summary" class="p-3 grid grid-cols-2 gap-2 text-sm border-b">
          <div><p class="text-gray-500 text-xs">Distance</p><p class="font-semibold text-lg">{{ track.summary.distance_km }} km</p></div>
          <div><p class="text-gray-500 text-xs">En service</p><p class="font-semibold">{{ track.summary.first_at ? `${hour(track.summary.first_at)} → ${hour(track.summary.last_at)}` : '—' }}</p></div>
          <div><p class="text-gray-500 text-xs">Livrés</p><p class="font-semibold">✅ {{ track.summary.delivered }}</p></div>
          <div><p class="text-gray-500 text-xs">Récupérés · échecs</p><p class="font-semibold">📦 {{ track.summary.picked_up }} · ⚠️ {{ track.summary.failed }}</p></div>
        </div>
        <div class="divide-y">
          <button v-for="(stop, i) in track.stops" :key="i" type="button" class="w-full text-left p-3 text-sm hover:bg-slate-50" @click="focusStop(stop)">
            <span class="text-gray-500">{{ hour(stop.at) }}</span> · {{ stopIcon(stop.status) }} {{ stop.label }}
            <span class="block text-xs"><span class="font-mono">{{ stop.tracking_code }}</span><span v-if="stop.recipient"> · {{ stop.recipient }}</span><span v-if="stop.incident" class="text-red-600"> · {{ stop.incident }}</span></span>
          </button>
          <p v-if="!track.stops.length" class="p-3 text-sm text-gray-500">Aucune étape de course localisée ce jour-là.</p>
        </div>
      </aside>

      <!-- Livreurs en direct -->
      <aside v-else class="card divide-y max-h-[75vh] overflow-y-auto">
        <div v-for="c in listed" :key="c.id" :class="['p-3 hover:bg-slate-50', selectedId === c.id ? 'bg-sky-50' : '']">
          <button type="button" class="w-full text-left" @click="focus(c)">
            <div class="flex items-center justify-between gap-2">
              <span class="font-medium flex items-center gap-2">
                <i :class="['legend', dotClass(c)]" />{{ c.name }}
              </span>
              <span :class="['text-xs', isStale(c) ? 'text-amber-700' : 'text-gray-500']">{{ c.lat === null ? 'position inconnue' : ago(c.last_location_at) }}</span>
            </div>
            <p class="text-xs text-gray-500">{{ c.is_available ? 'En service' : 'Hors service' }} · {{ c.phone }}</p>
            <ul v-if="c.missions.length" class="mt-1 space-y-0.5 text-xs">
              <li v-for="m in c.missions" :key="`${m.order_id}-${m.type}`">
                {{ m.type_label }} · <span class="font-mono">{{ m.tracking_code }}</span> · {{ m.zone_name }}
              </li>
            </ul>
          </button>
          <button type="button" class="mt-1 text-xs font-medium text-blue-600" @click="openTrack(c)">🛣️ Trajet du jour</button>
        </div>
        <p v-if="loaded && !listed.length" class="p-4 text-sm text-gray-500">Aucun livreur en service.</p>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import http from '../../bootstrap/axios'
import { useEcho } from '../../bootstrap/echo'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { today } from '../../utils/format'

// Au-delà, la position est considérée comme ancienne (téléphone éteint, réseau coupé…)
const STALE_MINUTES = 15
const ABIDJAN = [5.345, -4.024]

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const echo = useEcho()

const mapEl = ref(null)
const couriers = ref([])
const loaded = ref(false)
const showOffDuty = ref(false)
const selectedId = ref(null)
const now = ref(Date.now())

let map = null
let fitted = false
const markers = new Map()
let liveLayer = null
let trackLayer = null

// Trajet affiché : { courier, date, points, stops, summary, recent_days }
const track = ref(null)
const STOP_ICONS = { picked_up: '📦', delivered: '✅', delivery_failed: '⚠️', rescheduled: '📅', returned: '↩️', out_for_delivery: '🛵', pickup_in_progress: '🛵', at_hub: '🏬' }

const visible = computed(() => couriers.value.filter((c) => showOffDuty.value || c.is_available || c.missions.length))
const located = computed(() => visible.value.filter((c) => c.lat !== null && c.lng !== null))
const listed = computed(() => [...visible.value].sort((a, b) => (a.lat === null) - (b.lat === null) || b.missions.length - a.missions.length || a.name.localeCompare(b.name)))

function isStale(c) {
  return !c.last_location_at || now.value - new Date(c.last_location_at).getTime() > STALE_MINUTES * 60000
}

function dotClass(c) {
  if (!c.is_available && !c.missions.length) return 'bg-slate-400'
  return c.missions.length ? 'bg-blue-600' : 'bg-emerald-600'
}

function ago(value) {
  if (!value) return '—'
  const minutes = Math.round((now.value - new Date(value).getTime()) / 60000)
  if (minutes < 1) return 'à l\'instant'
  if (minutes < 60) return `il y a ${minutes} min`
  const hours = Math.round(minutes / 60)
  return hours < 24 ? `il y a ${hours} h` : `le ${new Date(value).toLocaleDateString('fr-FR')}`
}

function initials(name) {
  return (name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase()
}

function escape(text) {
  return String(text ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]))
}

function icon(c) {
  const color = { 'bg-blue-600': '#2563eb', 'bg-emerald-600': '#059669', 'bg-slate-400': '#94a3b8' }[dotClass(c)]
  return L.divIcon({
    className: '',
    iconSize: [34, 34],
    iconAnchor: [17, 17],
    popupAnchor: [0, -18],
    html: `<div class="courier-pin${isStale(c) ? ' is-stale' : ''}" style="background:${color}">${escape(initials(c.name))}</div>`,
  })
}

function popup(c) {
  const missions = c.missions.length
    ? `<ul style="margin:4px 0 0;padding-left:16px">${c.missions.map((m) => `<li>${escape(m.type_label)} · <a href="/admin/courses/${m.order_id}" data-order="${m.order_id}">${escape(m.tracking_code)}</a> · ${escape(m.zone_name)}</li>`).join('')}</ul>`
    : '<p style="margin:4px 0 0">Aucune mission en cours</p>'

  return `<strong>${escape(c.name)}</strong><br>
    <a href="tel:${escape(c.phone)}">${escape(c.phone)}</a> · ${c.is_available ? 'en service' : 'hors service'}<br>
    <span style="color:${isStale(c) ? '#b45309' : '#6b7280'}">Position ${escape(ago(c.last_location_at))}</span>${missions}`
}

function render() {
  if (!map || track.value) return
  const ids = new Set(located.value.map((c) => c.id))

  for (const [id, marker] of markers) {
    if (!ids.has(id)) {
      marker.remove()
      markers.delete(id)
    }
  }

  for (const c of located.value) {
    const marker = markers.get(c.id)
    if (marker) {
      marker.setLatLng([c.lat, c.lng]).setIcon(icon(c)).setPopupContent(popup(c))
    } else {
      markers.set(c.id, L.marker([c.lat, c.lng], { icon: icon(c), title: c.name }).bindPopup(popup(c)).addTo(liveLayer))
    }
  }

  // Cadrage automatique au premier affichage
  if (!fitted && located.value.length) {
    map.fitBounds(L.latLngBounds(located.value.map((c) => [c.lat, c.lng])), { padding: [40, 40], maxZoom: 15, animate: false })
    fitted = true
  }
}

function focus(c) {
  selectedId.value = c.id
  const marker = markers.get(c.id)
  if (marker) {
    map.flyTo(marker.getLatLng(), Math.max(map.getZoom(), 15), { duration: 0.6 })
    marker.openPopup()
  }
}

async function load() {
  couriers.value = (await http.get('/couriers/map')).data.data
  loaded.value = true
  now.value = Date.now()
}

function hour(value) {
  return new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function shortDate(day) {
  return day === today() ? 'Aujourd\'hui' : new Date(day).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
}

function stopIcon(status) {
  return STOP_ICONS[status] || '•'
}

function pin(html, color, size = 26) {
  return L.divIcon({ className: '', iconSize: [size, size], iconAnchor: [size / 2, size / 2], popupAnchor: [0, -size / 2], html: `<div class="track-pin" style="background:${color};width:${size}px;height:${size}px;line-height:${size - 4}px">${html}</div>` })
}

async function openTrack(c, date = today()) {
  track.value = { courier: { id: c.id, name: c.name }, date, points: [], stops: [], summary: null, recent_days: [] }
  router.replace({ query: { livreur: c.id, date } })
  await loadTrack()
}

async function loadTrack() {
  const t = track.value
  const { data } = await http.get(`/couriers/${t.courier.id}/track`, { params: { date: t.date } })
  Object.assign(t, data.data, { courier: data.data.courier })
  router.replace({ query: { livreur: t.courier.id, date: t.date } })
  drawTrack(true)
}

function drawTrack(fit) {
  const t = track.value
  if (!map || !t) return
  map.removeLayer(liveLayer)
  trackLayer.clearLayers()

  const line = t.points.map((p) => [p[0], p[1]])
  if (line.length) {
    L.polyline(line, { color: '#2563eb', weight: 4, opacity: 0.8 }).addTo(trackLayer)
    const first = t.points[0]
    const last = t.points[t.points.length - 1]
    L.marker([first[0], first[1]], { icon: pin('D', '#059669'), title: 'Départ' }).bindPopup(`<strong>Départ</strong> à ${hour(first[2])}`).addTo(trackLayer)
    L.marker([last[0], last[1]], { icon: pin(escape(initials(t.courier.name)), '#1d4ed8', 32), title: 'Dernière position' })
      .bindPopup(`<strong>${escape(t.courier.name)}</strong><br>Dernière position à ${hour(last[2])}`).addTo(trackLayer)
  }

  for (const stop of t.stops) {
    L.marker([stop.lat, stop.lng], { icon: pin(stopIcon(stop.status), '#fff', 28), title: stop.label })
      .bindPopup(`<strong>${hour(stop.at)} · ${escape(stop.label)}</strong><br><a href="/admin/courses/${stop.order_id}" data-order="${stop.order_id}">${escape(stop.tracking_code)}</a>${stop.recipient ? ` · ${escape(stop.recipient)}` : ''}${stop.incident ? `<br><span style="color:#dc2626">${escape(stop.incident)}</span>` : ''}`)
      .addTo(trackLayer)
  }

  const bounds = [...line, ...t.stops.map((s) => [s.lat, s.lng])]
  if (fit && bounds.length) {
    // Une animation en cours (cadrage initial, survol) écraserait ce cadrage
    map.stop()
    map.fitBounds(L.latLngBounds(bounds), { padding: [40, 40], maxZoom: 16, animate: false })
  }
}

function focusStop(stop) {
  map.flyTo([stop.lat, stop.lng], Math.max(map.getZoom(), 16), { duration: 0.6 })
  trackLayer.eachLayer((layer) => {
    const at = layer.getLatLng?.()
    if (at && at.lat === stop.lat && at.lng === stop.lng && layer.getPopup()) layer.openPopup()
  })
}

function closeTrack() {
  track.value = null
  trackLayer.clearLayers()
  liveLayer.addTo(map)
  router.replace({ query: {} })
  render()
}

// Position reçue en direct : on déplace le livreur sans tout recharger
function onLocation(payload) {
  // Trajet du jour affiché : le tracé s'allonge en direct
  const t = track.value
  if (t && t.courier.id === payload.id && t.date === today() && payload.lat !== null && payload.is_available) {
    t.points.push([payload.lat, payload.lng, payload.last_location_at])
    drawTrack(false)
  }

  const c = couriers.value.find((x) => x.id === payload.id)
  if (!c) return load()
  Object.assign(c, { is_available: payload.is_available, lat: payload.lat, lng: payload.lng, last_location_at: payload.last_location_at })
  now.value = Date.now()
}

watch([couriers, showOffDuty, now], render, { deep: true })
watch(orderChanges, load)

const channel = auth.user?.company_id ? `company.${auth.user.company_id}` : null
let refreshTimer
let clockTimer

onMounted(async () => {
  map = L.map(mapEl.value, { zoomControl: true }).setView(ABIDJAN, 12)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map)
  liveLayer = L.layerGroup().addTo(map)
  trackLayer = L.layerGroup().addTo(map)

  // Lien vers la fiche de la course depuis une bulle
  map.on('popupopen', (e) => {
    e.popup.getElement()?.querySelectorAll('a[data-order]').forEach((a) => a.addEventListener('click', (ev) => {
      ev.preventDefault()
      router.push(`/admin/courses/${a.dataset.order}`)
    }))
  })

  await load()
  await nextTick()
  render()

  // Lien direct vers un trajet : /admin/carte?livreur=12&date=2026-10-08
  if (route.query.livreur) {
    const c = couriers.value.find((x) => x.id === Number(route.query.livreur))
    if (c) openTrack(c, route.query.date || today())
  }

  if (echo && channel) echo.private(channel).listen('.courier.location', onLocation)
  refreshTimer = setInterval(load, 60000)
  clockTimer = setInterval(() => { now.value = Date.now() }, 30000)
})

onBeforeUnmount(() => {
  clearInterval(refreshTimer)
  clearInterval(clockTimer)
  // Le canal reste ouvert pour le reste du back-office : on retire seulement cet écouteur
  if (echo && channel) echo.private(channel).stopListening('.courier.location', onLocation)
  map?.remove()
  map = null
})
</script>

<style scoped>
.legend {
  display: inline-block;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 9999px;
}

:deep(.courier-pin) {
  width: 34px;
  height: 34px;
  border-radius: 9999px;
  border: 3px solid #fff;
  box-shadow: 0 1px 4px rgb(0 0 0 / 0.4);
  color: #fff;
  font: 600 12px/28px system-ui, sans-serif;
  text-align: center;
}

:deep(.track-pin) {
  border-radius: 9999px;
  border: 2px solid #fff;
  box-shadow: 0 1px 4px rgb(0 0 0 / 0.4);
  color: #fff;
  font: 600 12px system-ui, sans-serif;
  text-align: center;
}

:deep(.courier-pin.is-stale) {
  opacity: 0.55;
  border-style: dashed;
}
</style>
