<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Livreurs</h1>
        <p class="text-sm text-gray-500">Cliquez sur un livreur pour voir sa fiche : chiffres, note, informations.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-if="auth.can('settings.manage')" class="btn-secondary" @click="reports?.openSettings()">⭐ Réglages des notes</button>
        <RouterLink v-if="auth.can('users.manage')" to="/admin/utilisateurs" class="btn-secondary">+ Créer un compte livreur</RouterLink>
      </div>
    </div>

    <SettingsShortcut :blocks="['couriers']" title="Livreurs" intro="Comment les livreurs travaillent sur le terrain." more="/admin/reglages/courses" />

    <!-- Résumé : chaque case filtre la liste -->
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
      <button v-for="k in kpis" :key="k.key" type="button" :class="['card px-3 py-2 text-left transition hover:border-slate-400', k.active ? 'ring-2 ring-blue-500' : '', k.alert ? 'border-red-200 bg-red-50' : '']" @click="k.apply()">
        <span :class="['block text-xs', k.alert ? 'text-red-800' : 'text-gray-500']">{{ k.label }}</span>
        <span :class="['block text-xl font-bold', k.alert ? 'text-red-700' : '']">{{ k.value }}</span>
      </button>
    </div>

    <RatingReports ref="reports" @changed="load" />

    <!-- Filtres -->
    <div class="card flex flex-wrap items-end gap-2 p-3">
      <div class="min-w-[12rem] flex-1">
        <label class="label" for="f-search">Recherche</label>
        <input id="f-search" v-model="f.search" type="search" class="input" placeholder="Nom ou téléphone" @input="debounced">
      </div>
      <button type="button" class="btn-secondary md:hidden" :aria-expanded="moreFilters" @click="moreFilters = !moreFilters">Filtres{{ activeFilters ? ` (${activeFilters})` : '' }}</button>
      <div :class="[moreFilters ? 'flex' : 'hidden', 'w-full flex-wrap items-end gap-2 md:flex md:w-auto']">
      <div>
        <label class="label" for="f-zone">Zone</label>
        <select id="f-zone" v-model="f.zone_id" class="input max-w-[14rem]" @change="reload">
          <option value="">Toutes</option>
          <option v-for="z in zones" :key="z.id" :value="String(z.id)">{{ z.full_name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="f-available">Disponibilité</label>
        <select id="f-available" v-model="f.available" class="input" @change="reload">
          <option value="">Tous</option><option value="1">Disponibles</option><option value="0">Indisponibles</option>
        </select>
      </div>
      <div>
        <label class="label" for="f-vehicle">Véhicule</label>
        <select id="f-vehicle" v-model="f.vehicle_type" class="input" @change="reload">
          <option value="">Tous</option>
          <option v-for="(label, value) in VEHICLES" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="f-rating">Note</label>
        <select id="f-rating" v-model="f.rating" class="input" @change="reload">
          <option value="">Toutes</option><option value="good">4 et plus</option><option value="below">Sous le seuil</option>
          <option value="reported">Avec signalement</option><option value="unrated">Pas encore noté</option>
        </select>
      </div>
      <div>
        <label class="label" for="f-status">Compte</label>
        <select id="f-status" v-model="f.status" class="input" @change="reload">
          <option value="active">Actifs</option><option value="inactive">Suspendus</option><option value="all">Tous</option>
        </select>
      </div>
      <div>
        <label class="label" for="f-days">Chiffres sur</label>
        <select id="f-days" v-model="f.days" class="input" @change="reload">
          <option value="7">7 jours</option><option value="30">30 jours</option><option value="90">90 jours</option>
        </select>
      </div>
      </div>
      <button v-if="filtered" type="button" class="btn-secondary" @click="reset">Effacer</button>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="card overflow-hidden">
      <!-- Ordinateur : tableau -->
      <div class="hidden overflow-x-auto md:block">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th v-for="c in COLUMNS" :key="c.key" :class="['px-3 py-2.5 font-bold whitespace-nowrap', c.num ? 'text-right' : '']">
                <button v-if="c.sort" type="button" class="uppercase hover:text-gray-900" :aria-label="`Trier par ${c.label}`" @click="sortBy(c.sort)">
                  {{ c.label }}<span v-if="f.sort === c.sort" aria-hidden="true">{{ desc ? ' ▼' : ' ▲' }}</span>
                </button>
                <template v-else>{{ c.label }}</template>
              </th>
              <th class="px-3 py-2.5" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in rows" :key="c.id" :class="['cursor-pointer hover:bg-slate-50', c.active ? '' : 'opacity-60']" @click="open(c)">
              <td class="px-3 py-2.5">
                <div class="flex items-center gap-2">
                  <span :class="['inline-flex min-w-[3.4rem] items-center justify-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-bold', scoreClass(c)]" :title="c.ratings_count ? `${c.ratings_count} avis` : 'Pas encore noté'">
                    ★ {{ scoreText(c.score) }}
                  </span>
                  <span class="min-w-0">
                    <RouterLink :to="`/admin/livreurs/${c.id}`" class="block font-semibold text-gray-900 hover:underline" @click.stop>{{ c.name }}</RouterLink>
                    <span class="text-xs text-gray-500">{{ c.phone }}<template v-if="!c.active"> · compte suspendu</template></span>
                  </span>
                </div>
              </td>
              <td class="px-3 py-2.5">
                <span :class="['rounded-full px-2 py-0.5 text-xs', c.is_available ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600']">{{ c.is_available ? 'Disponible' : 'Indisponible' }}</span>
                <span v-if="c.last_location_at" class="mt-0.5 block text-xs text-gray-400">vu {{ ago(c.last_location_at) }}</span>
              </td>
              <td class="max-w-[12rem] px-3 py-2.5 text-xs text-gray-600"><span class="line-clamp-2">{{ c.zones.join(', ') || '—' }}</span></td>
              <td class="px-3 py-2.5 text-xs text-gray-600 whitespace-nowrap">
                {{ c.vehicle?.label || VEHICLES[c.vehicle_type] || '—' }}
                <span v-if="c.vehicle_warnings.length" :class="['block font-semibold', c.vehicle_warnings.some((w) => w.level === 'alert') ? 'text-red-700' : 'text-amber-700']" :title="c.vehicle_warnings.map((w) => w.text).join(' · ')">⚠ papiers</span>
              </td>
              <td class="px-3 py-2.5 text-right font-semibold">{{ c.missions || '—' }}</td>
              <td class="px-3 py-2.5 text-right"><b>{{ c.delivered }}</b><span v-if="c.per_day" class="block text-xs text-gray-400">{{ String(c.per_day).replace('.', ',') }}/jour</span></td>
              <td :class="['px-3 py-2.5 text-right font-semibold', rateClass(c.success_rate)]">{{ c.success_rate === null ? '—' : `${c.success_rate} %` }}</td>
              <td class="px-3 py-2.5 text-right">{{ c.delivery_minutes === null ? '—' : duration(c.delivery_minutes) }}</td>
              <td class="px-3 py-2.5 text-right">{{ c.failed || '—' }}</td>
              <td class="px-3 py-2.5 text-xs font-semibold text-orange-700 whitespace-nowrap">
                <span v-if="c.to_handle" class="block">⚠ {{ c.to_handle }} signalement{{ c.to_handle > 1 ? 's' : '' }}</span>
                <span v-else-if="c.below_threshold" class="block">⚠ note basse</span>
              </td>
              <td class="px-3 py-2.5 text-right whitespace-nowrap" @click.stop>
                <RouterLink :to="{ path: '/admin/courses', query: { courier_id: c.id } }" class="text-blue-600" title="Ses courses">Courses</RouterLink>
                <button v-if="auth.can('users.manage')" type="button" class="ml-3 text-blue-600" @click="edit(c)">Modifier</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Téléphone : lignes -->
      <ul class="divide-y divide-slate-100 md:hidden">
        <li v-for="c in rows" :key="c.id">
          <RouterLink :to="`/admin/livreurs/${c.id}`" :class="['flex items-center gap-3 px-4 py-3', c.active ? '' : 'opacity-60']">
            <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-bold', scoreClass(c)]">★ {{ scoreText(c.score) }}</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-semibold">{{ c.name }}</span>
              <span class="text-xs text-gray-500">
                {{ c.is_available ? 'Disponible' : 'Indisponible' }} · {{ c.delivered }} livrée{{ c.delivered > 1 ? 's' : '' }}<template v-if="c.success_rate !== null"> · {{ c.success_rate }} %</template>
              </span>
              <span v-if="c.to_handle || c.below_threshold" class="block text-xs font-semibold text-orange-700">⚠ {{ c.to_handle ? `${c.to_handle} signalement(s)` : 'note basse' }}</span>
            </span>
            <span class="text-gray-400" aria-hidden="true">›</span>
          </RouterLink>
        </li>
      </ul>

      <p v-if="loaded && !rows.length" class="p-6 text-center text-gray-500">Aucun livreur ne correspond.</p>
      <p v-if="!loaded" class="p-6 text-center text-gray-500">Chargement…</p>
      <div class="flex flex-wrap items-center justify-between gap-2 border-t px-3">
        <Pagination :meta="meta" class="flex-1" @change="(p) => load(p)" />
        <label v-if="meta && meta.total > 10" class="flex items-center gap-2 py-3 text-xs text-gray-500">
          Par page
          <select v-model="f.per_page" class="input w-20 py-1" @change="reload"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
        </label>
      </div>
    </div>
    <p class="text-xs text-gray-500">
      Note sur 5 : clients, e-commerçants et responsables, sur 90 jours. Livrées, réussite, temps de livraison (du ramassage à la livraison, temps habituel) et échecs : sur la période choisie.
    </p>

    <CourierForm :open="!!editing" :courier="editing" @close="editing = null" @saved="load(meta?.current_page)" />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import CourierForm from '../../components/CourierForm.vue'
import Pagination from '../../components/Pagination.vue'
import RatingReports from '../../components/ratings/RatingReports.vue'
import { scoreText } from '../../components/ratings/ratingWords'
import SettingsShortcut from '../../components/settings/SettingsShortcut.vue'
import { useAuthStore } from '../../stores/auth'

const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }
const COLUMNS = [
  { key: 'name', label: 'Note · Livreur', sort: 'name' },
  { key: 'state', label: 'État' },
  { key: 'zones', label: 'Zones' },
  { key: 'vehicle', label: 'Véhicule' },
  { key: 'missions', label: 'En cours', sort: 'missions', num: true },
  { key: 'delivered', label: 'Livrées', sort: 'delivered', num: true },
  { key: 'success', label: 'Réussite', sort: 'success_rate', num: true },
  { key: 'minutes', label: 'Temps', sort: 'delivery_minutes', num: true },
  { key: 'failed', label: 'Échecs', sort: 'failed', num: true },
  { key: 'alerts', label: 'Alertes' },
]
const DEFAULTS = { search: '', zone_id: '', available: '', vehicle_type: '', rating: '', status: 'active', days: '30', sort: 'name', dir: 'asc', per_page: '25' }

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const f = reactive({ ...DEFAULTS, ...Object.fromEntries(Object.entries(route.query).filter(([k]) => k in DEFAULTS).map(([k, v]) => [k, String(v)])) })
const rows = ref([])
const meta = ref(null)
const summary = ref({})
const zones = ref([])
const loaded = ref(false)
const error = ref('')
const editing = ref(null)
const reports = ref(null)

const desc = computed(() => f.dir === 'desc')
const moreFilters = ref(false)
const activeFilters = computed(() => ['zone_id', 'available', 'vehicle_type', 'rating'].filter((k) => f[k] !== '').length + (f.status !== 'active' ? 1 : 0))
const filtered = computed(() => ['search', 'zone_id', 'available', 'vehicle_type', 'rating'].some((k) => f[k] !== '') || f.status !== 'active')
const kpis = computed(() => {
  const s = summary.value
  return [
    { key: 'all', label: 'Livreurs', value: s.couriers ?? '—', active: !filtered.value, apply: reset },
    { key: 'available', label: 'Disponibles', value: s.available ?? '—', active: f.available === '1', apply: () => setFilter('available', f.available === '1' ? '' : '1') },
    { key: 'score', label: 'Note moyenne', value: s.average_score ? `★ ${scoreText(s.average_score)}` : '—', active: f.sort === 'score', apply: () => { f.sort = 'score'; f.dir = 'desc'; reload() } },
    { key: 'below', label: 'Note sous le seuil', value: s.below ?? 0, active: f.rating === 'below', alert: s.below > 0, apply: () => setFilter('rating', f.rating === 'below' ? '' : 'below') },
    { key: 'reported', label: 'Signalements à traiter', value: s.to_handle ?? 0, active: f.rating === 'reported', alert: s.to_handle > 0, apply: () => setFilter('rating', f.rating === 'reported' ? '' : 'reported') },
  ]
})

const scoreClass = (c) => (c.score === null ? 'bg-slate-100 text-slate-500' : c.below_threshold ? 'bg-orange-100 text-orange-800' : c.score >= 4 ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800')
const rateClass = (r) => (r === null ? 'text-gray-400' : r >= 90 ? 'text-emerald-700' : r >= 75 ? 'text-amber-700' : 'text-red-700')
const duration = (m) => (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`)
function ago(iso) {
  const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (min < 1) return 'à l\'instant'
  if (min < 60) return `il y a ${min} min`
  if (min < 1440) return `il y a ${Math.floor(min / 60)} h`
  return `il y a ${Math.floor(min / 1440)} j`
}

async function load(page = 1) {
  error.value = ''
  const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))
  // Adresse de la page : garde les filtres (retour depuis la fiche)
  router.replace({ query: Object.fromEntries(Object.entries(f).filter(([k, v]) => v !== DEFAULTS[k])) })
  try {
    const { data } = await http.get('/couriers/directory', { params: { ...params, page } })
    rows.value = data.data
    meta.value = data.meta
    summary.value = data.summary
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loaded.value = true
  }
}

const reload = () => load(1)
let timer = null
function debounced() {
  clearTimeout(timer)
  timer = setTimeout(reload, 300)
}
function setFilter(key, value) {
  f[key] = value
  reload()
}
function reset() {
  Object.assign(f, { ...DEFAULTS, days: f.days, per_page: f.per_page })
  reload()
}
function sortBy(key) {
  const first = key === 'name' ? 'asc' : 'desc'
  f.dir = f.sort === key ? (f.dir === 'desc' ? 'asc' : 'desc') : first
  f.sort = key
  reload()
}

const open = (c) => router.push(`/admin/livreurs/${c.id}`)
async function edit(c) {
  try {
    editing.value = (await http.get(`/couriers/${c.id}`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

onMounted(async () => {
  // Depuis la page Utilisateurs : ouvre la fenêtre du livreur demandé
  const asked = route.query.id
  load()
  if (asked) edit({ id: asked })
  zones.value = (await http.get('/zones')).data.data
})
</script>
