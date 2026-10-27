<template>
  <div class="space-y-3">
    <!-- Recherche (toutes dates) et choix de la date -->
    <div class="flex gap-2">
      <div class="relative flex-1">
        <input ref="searchInput" v-model="search" type="search" class="m-input !py-3 pl-11 !border-0 shadow-soft !rounded-2xl" placeholder="Nom, numéro, code…" aria-label="Rechercher une course" @input="debouncedLoad">
        <ColorIcon name="recherche" :size="22" :tile="false" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2" />
      </div>
      <button type="button" class="tap shrink-0 flex items-center gap-1.5 rounded-2xl bg-white px-3 text-sm font-semibold shadow-soft active:scale-95 transition" :class="search ? 'opacity-50' : ''" @click="dateSheet = true">
        <ColorIcon name="calendrier" :size="22" :tile="false" />{{ period.label }}
      </button>
    </div>
    <p v-if="search" class="text-xs text-slate-500">Recherche sur toutes les dates.</p>

    <!-- Toutes par défaut ; les onglets ne font que réduire la liste -->
    <div class="-mx-4 px-4 flex gap-2 overflow-x-auto no-scrollbar" role="radiogroup" aria-label="Catégorie">
      <button
        v-for="f in tabs"
        :key="f.value"
        type="button"
        role="radio"
        :aria-checked="filter === f.value"
        :class="['tap shrink-0 inline-flex items-center gap-1.5 rounded-full py-1.5 pl-3.5 text-[13.5px] font-semibold whitespace-nowrap', counts[f.value] !== undefined ? 'pr-1.5' : 'pr-3.5', filter === f.value ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 shadow-soft']"
        @click="setFilter(f.value)"
      >
        {{ f.label }}
        <span v-if="counts[f.value] !== undefined" class="min-w-[22px] h-[22px] px-1.5 rounded-full grid place-items-center text-[12px] font-bold tabular-nums" :style="filter === f.value ? { background: 'rgba(255,255,255,.18)', color: '#fff' } : TAB_TONES[f.value]">{{ counts[f.value] }}</span>
      </button>
    </div>

    <!-- Étiquettes des colis que le livreur n'a pas encore récupérés (courses affichées) -->
    <RouterLink v-if="toLabel.length" :to="{ path: '/etiquettes', query: { ids: toLabel.join(',') } }" class="tap flex items-center gap-3 rounded-[20px] bg-white p-3 text-sm shadow-soft active:scale-[.99] transition">
      <ColorIcon name="etiquette" :size="40" />
      <span class="flex-1"><strong class="block text-[14.5px]">Étiquettes à coller · {{ toLabel.length }}</strong><span class="text-slate-500">Pour les colis pas encore récupérés</span></span>
      <AppIcon name="right" class="h-5 w-5 text-slate-400" />
    </RouterLink>

    <!-- Courses rangées par étape, de la plus urgente à la plus avancée -->
    <section v-for="g in groups" :key="g.key" class="space-y-2">
      <h2 :class="['flex items-center gap-2 pt-1 text-[13px] font-extrabold uppercase tracking-wider', g.color]">
        <i :class="['block h-2 w-2 rounded-full', g.dot]" />{{ g.label }} · {{ g.orders.length }}
      </h2>
      <OrderCard v-for="o in g.orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
    </section>

    <EmptyState v-if="!loading && !orders.length" :art="search ? 'recherche' : 'colis'" :title="search ? 'Aucune course trouvée' : emptyTitle" :text="search ? 'Essayez un autre nom, numéro ou code.' : 'Choisissez une autre date avec le bouton en haut.'" />
    <!-- Chargement : des cartes grises animées à la place de la liste -->
    <div v-if="loading && !orders.length" class="space-y-2.5" aria-label="Chargement">
      <div v-for="n in 3" :key="n" class="flex items-center gap-3 rounded-[20px] bg-white p-3.5 shadow-soft animate-pulse">
        <span class="h-[46px] w-[46px] rounded-[14px] bg-slate-100" />
        <span class="flex-1 space-y-2"><span class="block h-3.5 w-2/3 rounded bg-slate-100" /><span class="block h-3 w-1/2 rounded bg-slate-100" /></span>
      </div>
    </div>
    <button v-if="hasMore && !loading" class="m-btn-secondary" @click="load(page + 1)">Voir plus</button>

    <!-- Choix de la date -->
    <BottomSheet :open="dateSheet" title="Quelles dates ?" @close="dateSheet = false">
      <div class="space-y-2">
        <button
          v-for="p in presets"
          :key="p.key"
          type="button"
          :class="['tap w-full flex items-center justify-between rounded-2xl px-4 py-3.5 text-left font-semibold ring-1', period.key === p.key ? 'ring-2 ring-[var(--app-color)] bg-emerald-50' : 'ring-slate-200 bg-white']"
          @click="choosePreset(p)"
        >
          {{ p.label }}<AppIcon v-if="period.key === p.key" name="check" class="h-5 w-5 text-[var(--app-color)]" />
        </button>
        <form class="rounded-2xl ring-1 ring-slate-200 p-4 space-y-3" @submit.prevent="chooseCustom">
          <p class="font-semibold">Une date précise ou une période</p>
          <div class="grid grid-cols-2 gap-2">
            <div><label class="m-label !text-sm" for="pick-from">Du</label><input id="pick-from" v-model="custom.from" type="date" class="m-input !py-2.5" required></div>
            <div><label class="m-label !text-sm" for="pick-to">Au</label><input id="pick-to" v-model="custom.to" type="date" :min="custom.from" class="m-input !py-2.5"></div>
          </div>
          <button class="m-btn-primary !py-3">Afficher</button>
        </form>
      </div>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cachedGet } from '../../composables/useCachedApi'
import AppIcon from '../../components/mobile/AppIcon.vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { orderChanges } from '../../composables/useRealtime'
import { date as formatDate, today } from '../../utils/format'
import { groupOrders } from '../../utils/orderStage'
import { ORDER_FILTERS, filterParams } from '../../utils/orderFilters'
import { PERIOD_PRESETS, periodFromQuery, periodQuery } from '../../utils/periods'

const route = useRoute()
const router = useRouter()

const byValue = Object.fromEntries(ORDER_FILTERS.map((f) => [f.value, f]))
const tabs = [byValue.all, byValue.problems, byValue.active, byValue.delivered]
const presets = ['today', 'later', 'yesterday', 'week', 'month', 'all'].map((k) => PERIOD_PRESETS[k])
// Par défaut les 7 derniers jours : la liste n'est pas vide le matin avant la première course du jour
const DEFAULT_PERIOD = 'week'
// Couleur du compteur de chaque onglet
const TAB_TONES = {
  all: { background: '#EEF1F5', color: '#3B4859' },
  problems: { background: '#FFF4E0', color: '#B76E00' },
  active: { background: '#E8F1FF', color: '#1D5BC7' },
  delivered: { background: '#E3F7EE', color: '#127A50' },
}

const EMPTY = {
  all: 'Aucune course sur ces dates',
  active: 'Aucune course en cours',
  problems: 'Rien à régler 👍',
  delivered: 'Aucune course livrée',
}

// Anciennes adresses (?filtre=active…) toujours valables
// Pas encore récupérées par le livreur : leur étiquette est à coller sur le colis (100 au plus d'un coup)
const BEFORE_PICKUP = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress']
const toLabel = computed(() => orders.value.filter((o) => BEFORE_PICKUP.includes(o.status)).map((o) => o.id).slice(0, 100))
const filter = ref(tabs.some((f) => f.value === route.query.filtre) ? route.query.filtre : 'all')
const period = ref(periodFromQuery(route.query, DEFAULT_PERIOD))
const search = ref('')
const searchInput = ref(null)
const orders = ref([])
const counts = reactive({})
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
const dateSheet = ref(false)
const custom = reactive({ from: today(), to: '' })

const groups = computed(() => groupOrders(orders.value))
const emptyTitle = computed(() => EMPTY[filter.value])

async function load(p = 1) {
  loading.value = true
  try {
    const { data } = await cachedGet('/orders', {
      // Une recherche porte sur toutes les dates : on retrouve une course sans connaître son jour
      params: { page: p, per_page: 50, ...filterParams(byValue[filter.value], search.value ? null : period.value), search: search.value || undefined },
    })
    orders.value = p === 1 ? data.data : [...orders.value, ...data.data]
    page.value = p
    hasMore.value = data.meta.current_page < data.meta.last_page
  } catch {
    // Hors ligne sans cache pour ce filtre : liste inchangée
  } finally {
    loading.value = false
  }
}

// Nombre de courses de chaque onglet sur la période (le total est dans « meta »)
async function loadCounts() {
  await Promise.all(tabs.map(async (f) => {
    try {
      const { data } = await cachedGet('/orders', { params: { per_page: 1, ...filterParams(f, period.value) } })
      counts[f.value] = data.meta?.total ?? 0
    } catch {
      // Compteur facultatif
    }
  }))
}

// Onglet et dates gardés dans l'adresse (retour depuis une fiche)
function syncQuery() {
  router.replace({ query: { ...(filter.value === 'all' ? {} : { filtre: filter.value }), ...periodQuery(period.value, DEFAULT_PERIOD) } })
}

function setFilter(value) {
  filter.value = value
  syncQuery()
  load()
}

function setPeriod(value) {
  period.value = value
  dateSheet.value = false
  syncQuery()
  load()
  loadCounts()
}

const choosePreset = (p) => setPeriod({ key: p.key, label: p.label, from: p.from(), to: p.to() })

function chooseCustom() {
  const to = custom.to || custom.from
  const [from, end] = custom.from <= to ? [custom.from, to] : [to, custom.from]
  const short = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`
  setPeriod({ key: 'custom', label: from === end ? `Le ${formatDate(from)}` : `${short(from)} → ${short(end)}`, from, to: end })
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(), 300)
}

watch(orderChanges, () => {
  load()
  loadCounts()
})
onMounted(async () => {
  load()
  loadCounts()
  // Loupe de l'en-tête : le champ de recherche prend la main
  if (route.query.chercher) {
    await nextTick()
    searchInput.value?.focus()
  }
})
</script>
