<template>
  <div class="space-y-3">
    <div class="relative">
      <input v-model="search" type="search" class="m-input pl-11" placeholder="Nom, téléphone ou n° de course" aria-label="Rechercher une course" @input="debouncedLoad">
      <svg viewBox="0 0 24 24" class="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" stroke-linecap="round" /></svg>
    </div>

    <!-- Catégories, avec le nombre de courses de chacune -->
    <div class="flex gap-2 overflow-x-auto no-scrollbar -mx-4 px-4 pb-1" role="radiogroup" aria-label="Catégorie">
      <button
        v-for="f in filters"
        :key="f.value"
        type="button"
        role="radio"
        :aria-checked="filter === f.value"
        :class="['tap shrink-0 rounded-full px-4 py-2 text-sm font-medium ring-1', filter === f.value ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
        :style="filter === f.value ? { backgroundColor: 'var(--app-color)' } : {}"
        @click="setFilter(f.value)"
      >
        {{ f.label }}
        <span
          v-if="counts[f.value] !== undefined"
          :class="['ml-1 rounded-full px-1.5 text-xs', filter === f.value ? 'bg-white/25' : f.warn && counts[f.value] ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600']"
        >{{ counts[f.value] }}</span>
      </button>
    </div>

    <!-- Courses rangées par jour -->
    <section v-for="group in groups" :key="group.key" class="space-y-2">
      <h2 class="pt-1 text-xs font-bold uppercase tracking-wide text-slate-500">{{ group.label }}</h2>
      <OrderCard v-for="o in group.orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`">
        <template v-if="needsDecision(o)" #actions>
          <a :href="telLink(o.recipient.phone)" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold ring-1 ring-slate-200 active:bg-slate-50">📞 Appeler</a>
          <RouterLink v-if="o.status === 'delivery_failed'" :to="`/marchand/courses/${o.id}?action=reporter`" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold ring-1 ring-slate-200 active:bg-slate-50">📅 Reporter</RouterLink>
          <RouterLink :to="`/marchand/courses/${o.id}`" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }">Décider</RouterLink>
        </template>
      </OrderCard>
    </section>

    <EmptyState v-if="!loading && !orders.length" icon="🔎" :title="search ? 'Aucune course trouvée' : emptyTitle" :text="search ? 'Essayez un autre nom, numéro ou n° de course.' : emptyText">
      <RouterLink v-if="!search && filter === 'all' && auth.can('orders.create')" to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
    </EmptyState>
    <p v-if="loading" class="text-center text-slate-400 py-6">Chargement…</p>
    <button v-if="hasMore && !loading" class="m-btn-secondary" @click="load(page + 1)">Voir plus</button>

  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cachedGet } from '../../composables/useCachedApi'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { orderChanges } from '../../composables/useRealtime'
import { useAuthStore } from '../../stores/auth'
import { telLink } from '../../utils/format'
import { needsDecision } from '../../utils/merchantStatus'

const auth = useAuthStore()

// En cours, reports compris (décision prise, nouvelle livraison prévue)
const ACTIVE = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress', 'picked_up', 'at_hub', 'delivery_assigned', 'out_for_delivery', 'rescheduled', 'return_assigned', 'returning']

// « Toutes » d'abord : l'e-commerçant ne tombe jamais sur une liste vide alors qu'il a des courses
const filters = [
  { value: 'all', label: 'Toutes', statuses: [] },
  { value: 'active', label: 'En cours', statuses: ACTIVE },
  // Même règle que la file « À décider » de l'agence
  { value: 'problems', label: 'À régler', statuses: [], queue: 'to_decide', warn: true },
  { value: 'delivered', label: 'Livrées', statuses: ['delivered'] },
  { value: 'other', label: 'Retournées / annulées', statuses: ['returned', 'cancelled', 'rejected'] },
]

const EMPTY = {
  all: ['Aucune course pour le moment', 'Votre première course apparaîtra ici.'],
  active: ['Aucune course en cours', 'Toutes vos courses sont terminées.'],
  problems: ['Rien à régler', 'Aucun colis n\'attend votre décision. 👍'],
  delivered: ['Aucune course livrée', 'Les courses livrées apparaîtront ici.'],
  other: ['Aucune course retournée ou annulée', ''],
}

const route = useRoute()
const router = useRouter()
// Anciennes adresses (?filtre=active…) toujours valables
const filter = ref(filters.some((f) => f.value === route.query.filtre) ? route.query.filtre : 'all')
const search = ref('')
const orders = ref([])
const counts = reactive({})
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)

const emptyTitle = computed(() => EMPTY[filter.value][0])
const emptyText = computed(() => EMPTY[filter.value][1])

// AAAA-MM-JJ local d'une date de création
const day = (value) => {
  const d = new Date(value)
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}
const shift = (days) => day(Date.now() + days * 86400000)
// « vendredi 16 octobre » (l'année seulement si ce n'est pas l'année en cours)
const longDay = (key) => new Date(`${key}T12:00:00`).toLocaleDateString('fr-FR', {
  weekday: 'long', day: 'numeric', month: 'long', ...(key.slice(0, 4) === shift(0).slice(0, 4) ? {} : { year: 'numeric' }),
})

const groups = computed(() => {
  const list = []
  for (const order of orders.value) {
    const key = order.created_at ? day(order.created_at) : 'autre'
    let group = list.find((g) => g.key === key)
    if (!group) {
      const label = key === shift(0) ? 'Aujourd\'hui' : key === shift(-1) ? 'Hier' : key === 'autre' ? 'Plus ancien' : longDay(key)
      group = { key, label, orders: [] }
      list.push(group)
    }
    group.orders.push(order)
  }

  return list
})

async function load(p = 1) {
  loading.value = true
  const current = filters.find((f) => f.value === filter.value)
  const statuses = current?.statuses || []
  try {
    const { data } = await cachedGet('/orders', {
      params: { page: p, per_page: 20, search: search.value || undefined, status: statuses.length ? statuses : undefined, queue: current?.queue },
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

// Nombre de courses de chaque catégorie (une page d'une ligne suffit : le total est dans « meta »)
async function loadCounts() {
  await Promise.all(filters.map(async (f) => {
    try {
      const { data } = await cachedGet('/orders', { params: { per_page: 1, status: f.statuses.length ? f.statuses : undefined, queue: f.queue } })
      counts[f.value] = data.meta?.total ?? 0
    } catch {
      // Compteur facultatif
    }
  }))
}

function setFilter(value) {
  filter.value = value
  router.replace({ query: value === 'all' ? {} : { filtre: value } })
  load()
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
onMounted(() => {
  load()
  loadCounts()
})
</script>
