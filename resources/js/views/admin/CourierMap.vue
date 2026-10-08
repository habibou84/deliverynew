<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Carte des livreurs</h1>
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="flex items-center gap-1"><i class="legend bg-blue-600" /> En mission</span>
        <span class="flex items-center gap-1"><i class="legend bg-emerald-600" /> Disponible</span>
        <span class="flex items-center gap-1"><i class="legend bg-slate-400" /> Hors service</span>
        <label class="flex items-center gap-2"><input v-model="showOffDuty" type="checkbox"> Afficher les livreurs hors service</label>
      </div>
    </div>

    <div class="grid lg:grid-cols-[1fr_20rem] gap-4">
      <div class="card overflow-hidden relative">
        <div ref="mapEl" class="h-[60vh] lg:h-[75vh] w-full z-0" role="application" aria-label="Carte des livreurs" />
        <p v-if="loaded && !located.length" class="absolute inset-x-4 top-4 z-[400] rounded bg-white/95 p-3 text-sm shadow">
          Aucune position pour le moment : la position d'un livreur apparaît quand il est en service dans son application
          (localisation autorisée sur son téléphone).
        </p>
      </div>

      <aside class="card divide-y max-h-[75vh] overflow-y-auto">
        <button
          v-for="c in listed"
          :key="c.id"
          type="button"
          :class="['w-full text-left p-3 hover:bg-slate-50', selectedId === c.id ? 'bg-sky-50' : '']"
          @click="focus(c)"
        >
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
        <p v-if="loaded && !listed.length" class="p-4 text-sm text-gray-500">Aucun livreur en service.</p>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import http from '../../bootstrap/axios'
import { useEcho } from '../../bootstrap/echo'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'

// Au-delà, la position est considérée comme ancienne (téléphone éteint, réseau coupé…)
const STALE_MINUTES = 15
const ABIDJAN = [5.345, -4.024]

const auth = useAuthStore()
const router = useRouter()
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
  if (!map) return
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
      markers.set(c.id, L.marker([c.lat, c.lng], { icon: icon(c), title: c.name }).bindPopup(popup(c)).addTo(map))
    }
  }

  // Cadrage automatique au premier affichage
  if (!fitted && located.value.length) {
    map.fitBounds(L.latLngBounds(located.value.map((c) => [c.lat, c.lng])), { padding: [40, 40], maxZoom: 15 })
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

// Position reçue en direct : on déplace le livreur sans tout recharger
function onLocation(payload) {
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

:deep(.courier-pin.is-stale) {
  opacity: 0.55;
  border-style: dashed;
}
</style>
