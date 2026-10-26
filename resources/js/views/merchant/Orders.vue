<template>
  <div class="space-y-3">
    <!-- Recherche (toutes dates) et choix de la date -->
    <div class="flex gap-2">
      <div class="relative flex-1">
        <input ref="searchInput" v-model="search" type="search" class="m-input !py-3 pl-10" placeholder="Client, numéro, code…" aria-label="Rechercher une course" @input="debouncedLoad">
        <AppIcon name="search" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-[18px] w-[18px] text-slate-400" />
      </div>
      <button type="button" class="tap shrink-0 flex items-center gap-1.5 rounded-xl bg-white px-3.5 text-sm font-bold ring-1 ring-slate-300" :class="search ? 'opacity-50' : ''" @click="dateSheet = true">
        <AppIcon name="calendar" class="h-[18px] w-[18px]" />{{ period.label }}
      </button>
    </div>
    <p v-if="search" class="text-xs text-slate-500">Recherche sur toutes les dates.</p>

    <!-- Toutes par défaut ; les onglets ne font que réduire la liste -->
    <div class="flex rounded-xl bg-slate-200/70 p-1" role="radiogroup" aria-label="Catégorie">
      <button
        v-for="f in tabs"
        :key="f.value"
        type="button"
        role="radio"
        :aria-checked="filter === f.value"
        :class="['tap flex-1 rounded-lg py-2 text-[13px] font-bold whitespace-nowrap', filter === f.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600']"
        @click="setFilter(f.value)"
      >
        {{ f.label }}
        <span v-if="counts[f.value] !== undefined" :class="['ml-0.5 rounded-full px-1.5 text-[11px]', f.warn && counts[f.value] ? 'bg-orange-100 text-orange-800' : filter === f.value ? 'bg-emerald-100 text-emerald-800' : 'bg-white/70']">{{ counts[f.value] }}</span>
      </button>
    </div>

    <!-- Étiquettes des colis que le livreur n'a pas encore récupérés (courses affichées) -->
    <RouterLink v-if="toLabel.length" :to="{ path: '/etiquettes', query: { ids: toLabel.join(',') } }" class="tap flex items-center justify-center gap-2 rounded-xl bg-white py-2.5 text-sm font-bold ring-1 ring-slate-300">
      🖨️ Étiquettes à coller ({{ toLabel.length }})
    </RouterLink>

    <!-- Courses rangées par étape, de la plus urgente à la plus avancée -->
    <section v-for="g in groups" :key="g.key" class="space-y-2">
      <h2 :class="['flex items-center gap-2 pt-1 text-[13px] font-extrabold uppercase tracking-wider', g.color]">
        <i :class="['block h-2 w-2 rounded-full', g.dot]" />{{ g.label }} · {{ g.orders.length }}
      </h2>
      <OrderCard v-for="o in g.orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
    </section>

    <EmptyState v-if="!loading && !orders.length" icon="🔎" :title="search ? 'Aucune course trouvée' : emptyTitle" :text="search ? 'Essayez un autre nom, numéro ou code.' : 'Choisissez une autre date avec le bouton en haut.'" />
    <p v-if="loading" class="text-center text-slate-400 py-6">Chargement…</p>
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
const period = ref(periodFromQuery(route.query, 'today'))
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
  router.replace({ query: { ...(filter.value === 'all' ? {} : { filtre: filter.value }), ...periodQuery(period.value, 'today') } })
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
