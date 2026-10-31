<template>
  <div class="space-y-4">
    <!-- Chiffres de l'équipe -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
      <div class="card p-3">
        <p class="text-sm text-gray-600">Livreurs actifs</p>
        <p class="text-2xl font-bold">{{ totals.active ?? '—' }} <span class="text-sm font-normal text-gray-600">sur {{ totals.couriers ?? '—' }}</span></p>
      </div>
      <div class="card p-3">
        <p class="text-sm text-gray-600">Colis en main</p>
        <p class="text-2xl font-bold">{{ totals.in_hand ?? '—' }}</p>
      </div>
      <div class="card p-3">
        <p class="text-sm text-gray-600">Livrés aujourd'hui</p>
        <p class="text-2xl font-bold text-emerald-700">{{ totals.delivered ?? '—' }} <span class="text-sm font-normal text-gray-600">sur {{ totals.deliveries ?? '—' }}</span></p>
      </div>
      <div class="card p-3">
        <p class="text-sm text-gray-600">Échecs du jour</p>
        <p :class="['text-2xl font-bold', totals.failed ? 'text-red-700' : '']">{{ totals.failed ?? '—' }}</p>
      </div>
      <div class="card p-3 col-span-2 lg:col-span-1">
        <p class="text-sm text-gray-600">Avancement de la journée</p>
        <p class="text-2xl font-bold">{{ totals.progress === null || totals.progress === undefined ? '—' : `${totals.progress} %` }}</p>
        <div class="mt-1 h-2 rounded-full bg-slate-200" role="progressbar" :aria-valuenow="totals.progress || 0" aria-valuemin="0" aria-valuemax="100" aria-label="Avancement de la journée">
          <div class="h-2 rounded-full bg-emerald-700" :style="{ width: `${totals.progress || 0}%` }" />
        </div>
      </div>
    </div>

    <!-- Filtres -->
    <div class="flex flex-wrap items-end gap-3">
      <div class="flex-1 min-w-[12rem]">
        <label class="label" for="act-search">Chercher un livreur</label>
        <input id="act-search" v-model="f.search" type="search" class="input" placeholder="Nom ou téléphone">
      </div>
      <div>
        <label class="label" for="act-state">État</label>
        <select id="act-state" v-model="f.state" class="input w-auto">
          <option value="">Tous</option>
          <option value="mission">En mission</option>
          <option value="available">Disponibles</option>
          <option value="offline">Hors service</option>
          <option value="watch">À surveiller ({{ totals.watched || 0 }})</option>
        </select>
      </div>
      <div v-if="zones.length">
        <label class="label" for="act-zone">Zone</label>
        <select id="act-zone" v-model="f.zone" class="input w-auto">
          <option value="">Toutes</option>
          <option v-for="z in zones" :key="z" :value="z">{{ z }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="act-sort">Trier</label>
        <select id="act-sort" v-model="f.sort" class="input w-auto">
          <option value="watch">À surveiller d'abord</option>
          <option value="remaining">Le plus à faire</option>
          <option value="progress">Les moins avancés</option>
          <option value="name">Nom</option>
        </select>
      </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
      <!-- Livreurs -->
      <div class="space-y-2">
        <p v-if="!loaded" class="text-gray-500">Chargement…</p>
        <p v-else-if="!visible.length" class="card p-4 text-center text-gray-500">Aucun livreur.</p>
        <button
          v-for="c in visible"
          :key="c.id"
          type="button"
          :class="['card w-full p-3 text-left space-y-1.5 transition', c.flags.length ? 'bg-orange-50 border-orange-200' : '', selected?.id === c.id ? 'ring-2 ring-emerald-700' : 'hover:border-slate-400']"
          :aria-pressed="selected?.id === c.id"
          @click="select(c)"
        >
          <div class="flex items-center gap-2">
            <span :class="['h-2.5 w-2.5 rounded-full', STATE[c.state].dot]" aria-hidden="true" />
            <span class="flex-1 font-semibold">{{ c.name }}</span>
            <span :class="['rounded-full px-2 py-0.5 text-xs', STATE[c.state].chip]">{{ STATE[c.state].label }}<template v-if="c.last_location_at"> · vu {{ ago(c.last_location_at) }}</template></span>
          </div>
          <Progress v-if="c.deliveries.total" label="Livraisons" :done="c.deliveries.done" :total="c.deliveries.total" :warn="c.flags.length > 0" />
          <Progress v-if="c.pickups.total" label="Ramassages" :done="c.pickups.done" :total="c.pickups.total" />
          <p class="text-sm text-gray-700">
            {{ c.parcels_in_hand }} colis en main · {{ c.failed }} échec{{ c.failed > 1 ? 's' : '' }} · {{ money(c.cash_in_hand) }} en main
            <template v-if="!c.deliveries.total && !c.pickups.total"> · aucune mission aujourd'hui</template>
          </p>
          <p v-if="c.flags.length" class="text-sm font-semibold text-orange-800">⚠ {{ c.flags.join(' · ') }}</p>
        </button>
      </div>

      <!-- Carte et détail du livreur choisi, côte à côte avec la liste -->
      <div class="space-y-3 lg:sticky lg:top-4 self-start">
        <div class="card overflow-hidden">
          <div ref="mapEl" class="h-72 lg:h-[22rem] w-full z-0" role="application" aria-label="Position des livreurs" />
        </div>

        <div v-if="selected" ref="detailEl" class="card p-4 space-y-3">
          <div class="flex items-start gap-2">
            <div class="flex-1">
              <h2 class="text-lg font-bold">{{ selected.name }}</h2>
              <p class="text-sm text-gray-600">{{ STATE[selected.state].label }}<template v-if="selected.zones.length"> · {{ selected.zones.join(', ') }}</template><template v-if="selected.vehicle_type"> · {{ selected.vehicle_type }}</template></p>
            </div>
            <button type="button" class="btn-secondary" aria-label="Fermer le détail" @click="selected = null">✕</button>
          </div>
          <div class="flex flex-wrap gap-2">
            <a v-if="selected.phone" :href="telLink(selected.phone)" class="btn-primary">Appeler</a>
            <a v-if="selected.phone" :href="whatsappLink(selected.phone)" target="_blank" rel="noopener" class="btn-secondary">WhatsApp</a>
            <RouterLink :to="{ path: '/admin/activite/carte', query: { livreur: selected.id } }" class="btn-secondary">Trajet du jour</RouterLink>
            <RouterLink v-if="selected.cash_in_hand > 0 && auth.can('finance.view')" to="/admin/caisse" class="btn-secondary">Recevoir l'argent</RouterLink>
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
            <div class="rounded-lg bg-slate-50 p-2"><p class="text-gray-600">Livrées</p><p class="text-lg font-bold text-emerald-700">{{ selected.deliveries.done }} / {{ selected.deliveries.total }}</p></div>
            <div class="rounded-lg bg-slate-50 p-2"><p class="text-gray-600">Ramassées</p><p class="text-lg font-bold">{{ selected.pickups.done }} / {{ selected.pickups.total }}</p></div>
            <div class="rounded-lg bg-slate-50 p-2"><p class="text-gray-600">Colis en main</p><p class="text-lg font-bold">{{ selected.parcels_in_hand }}</p></div>
            <div class="rounded-lg bg-slate-50 p-2"><p class="text-gray-600">Argent en main</p><p class="text-lg font-bold">{{ money(selected.cash_in_hand) }}</p></div>
          </div>
          <p v-if="selected.flags.length" class="rounded-lg bg-orange-50 px-3 py-2 text-sm text-orange-900">⚠ {{ selected.flags.join(' · ') }}</p>

          <h3 class="font-semibold">Missions du jour</h3>
          <p v-if="missionsLoading" class="text-sm text-gray-500">Chargement…</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm stack-table">
              <thead class="bg-slate-50 text-left text-gray-600">
                <tr><th class="p-2">Heure</th><th class="p-2">Course</th><th class="p-2">État</th><th class="p-2 text-right">Montant</th></tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="m in missions" :key="m.id" :class="m.status === 'in_progress' ? 'bg-blue-50' : ''">
                  <td class="p-2 whitespace-nowrap">{{ m.completed_at ? time(m.completed_at) : (m.status === 'in_progress' ? 'en cours' : 'à faire') }}</td>
                  <td data-label="Course" class="p-2">
                    <RouterLink :to="`/admin/courses/${m.order_id}`" class="font-mono text-xs text-blue-700 hover:underline">{{ m.tracking_code }}</RouterLink>
                    {{ m.type_label }} · {{ m.merchant }}<template v-if="m.zone"> · {{ m.zone }}</template>
                  </td>
                  <td data-label="État" :class="['p-2 font-medium', MISSION[m.status]?.cls]">{{ MISSION[m.status]?.label || m.status }}</td>
                  <td data-label="Montant" class="p-2 text-right">{{ m.cod_amount ? money(m.cod_amount) : '—' }}</td>
                </tr>
                <tr v-if="!missions.length"><td colspan="4" class="p-3 text-center text-gray-500">Aucune mission aujourd'hui.</td></tr>
              </tbody>
            </table>
          </div>
          <p class="text-sm">
            <RouterLink to="/admin/livreurs" class="text-blue-700 hover:underline">Fiches des livreurs</RouterLink> ·
            <RouterLink to="/admin/activite/colis" class="text-blue-700 hover:underline">Colis en main</RouterLink> ·
            <RouterLink to="/admin/activite/remontees" class="text-blue-700 hover:underline">Remontées terrain</RouterLink>
          </p>
        </div>
        <p v-else class="text-sm text-gray-600">Choisissez un livreur pour voir ses missions du jour et le situer sur la carte.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import http from '../../../bootstrap/axios'
import { useAuthStore } from '../../../stores/auth'
import { money, telLink, whatsappLink } from '../../../utils/format'

const auth = useAuthStore()
const ABIDJAN = [5.345, -4.024]
const STATE = {
  mission: { label: 'En mission', dot: 'bg-blue-600', chip: 'bg-blue-100 text-blue-900', color: '#2563eb' },
  available: { label: 'Disponible', dot: 'bg-emerald-600', chip: 'bg-emerald-100 text-emerald-900', color: '#059669' },
  offline: { label: 'Hors service', dot: 'bg-slate-400', chip: 'bg-slate-200 text-slate-700', color: '#94a3b8' },
}
const MISSION = {
  completed: { label: 'Faite', cls: 'text-emerald-700' },
  failed: { label: 'Échec', cls: 'text-red-700' },
  in_progress: { label: 'En cours', cls: 'text-blue-700' },
  accepted: { label: 'À faire', cls: 'text-slate-700' },
  assigned: { label: 'À faire', cls: 'text-slate-700' },
}

// Barre de progression d'un livreur
const Progress = defineComponent({
  props: { label: String, done: Number, total: Number, warn: Boolean },
  setup(props) {
    return () => {
      const pct = props.total ? Math.round((100 * props.done) / props.total) : 0
      const color = pct === 100 ? 'bg-emerald-700' : props.warn ? 'bg-orange-600' : 'bg-emerald-600'
      return h('div', { class: 'flex items-center gap-2 text-sm' }, [
        h('span', { class: 'w-32 shrink-0' }, `${props.label} ${props.done} / ${props.total}`),
        h('div', { class: 'h-2 flex-1 rounded-full bg-slate-200', role: 'progressbar', 'aria-valuenow': pct, 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-label': props.label },
          h('div', { class: ['h-2 rounded-full', color], style: { width: `${pct}%` } })),
      ])
    }
  },
})

const couriers = ref([])
const totals = ref({})
const loaded = ref(false)
const selected = ref(null)
const missions = ref([])
const missionsLoading = ref(false)
const f = reactive({ search: '', state: '', zone: '', sort: 'watch' })
const mapEl = ref(null)
const detailEl = ref(null)
let map = null
let layer = null
let timer = null

const norm = (v) => (v || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const zones = computed(() => [...new Set(couriers.value.flatMap((c) => c.zones))].sort())
const visible = computed(() => {
  const list = couriers.value.filter((c) => (!f.search || norm(`${c.name} ${c.phone}`).includes(norm(f.search)))
    && (!f.state || (f.state === 'watch' ? c.flags.length > 0 : c.state === f.state))
    && (!f.zone || c.zones.includes(f.zone)))
  const by = {
    watch: () => 0, // ordre du serveur : à surveiller d'abord, puis le plus à faire
    remaining: (a, b) => b.remaining - a.remaining,
    progress: (a, b) => (a.progress ?? 101) - (b.progress ?? 101),
    name: (a, b) => a.name.localeCompare(b.name),
  }
  return f.sort === 'watch' ? list : [...list].sort(by[f.sort])
})

function ago(at) {
  const minutes = Math.round((Date.now() - new Date(at).getTime()) / 60000)
  if (minutes < 2) return 'à l\'instant'
  if (minutes < 60) return `il y a ${minutes} min`
  if (minutes < 48 * 60) return `il y a ${Math.round(minutes / 60)} h`
  return `il y a ${Math.round(minutes / 1440)} jours`
}
const time = (at) => new Date(at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })

async function load() {
  const { data } = await http.get('/couriers/activity')
  couriers.value = data.data
  totals.value = data.totals
  loaded.value = true
  if (selected.value) selected.value = couriers.value.find((c) => c.id === selected.value.id) || null
  drawMarkers()
}

function drawMarkers() {
  if (!map) return
  layer.clearLayers()
  const points = []
  for (const c of couriers.value) {
    if (c.lat === null || c.lng === null) continue
    const isSel = selected.value?.id === c.id
    const color = c.flags.length ? '#ea580c' : STATE[c.state].color
    L.circleMarker([c.lat, c.lng], { radius: isSel ? 11 : 8, color: '#fff', weight: 3, fillColor: color, fillOpacity: 1 })
      .bindTooltip(c.name, { permanent: isSel, direction: 'top', offset: [0, -8] })
      .on('click', () => select(c))
      .addTo(layer)
    points.push([c.lat, c.lng])
  }
  if (!selected.value && points.length) map.fitBounds(L.latLngBounds(points), { padding: [30, 30], maxZoom: 14, animate: false })
}

async function select(c) {
  selected.value = c
  drawMarkers()
  if (map && c.lat !== null) map.flyTo([c.lat, c.lng], 15, { duration: 0.5 })
  missionsLoading.value = true
  try {
    missions.value = (await http.get(`/couriers/${c.id}/activity`)).data.data
  } finally {
    missionsLoading.value = false
  }
  // Téléphone : le détail est sous la liste
  await nextTick()
  if (window.innerWidth < 1024) detailEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

onMounted(async () => {
  map = L.map(mapEl.value, { zoomControl: true }).setView(ABIDJAN, 12)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map)
  layer = L.layerGroup().addTo(map)
  await load()
  // Mise à jour toute seule chaque minute
  timer = setInterval(() => load().catch(() => {}), 60000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  map?.remove()
})
</script>
