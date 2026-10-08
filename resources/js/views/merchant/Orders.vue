<template>
  <div class="space-y-3">
    <div class="relative">
      <input v-model="search" type="search" class="m-input pl-11" placeholder="Nom, téléphone ou code" aria-label="Rechercher une course" @input="debouncedLoad">
      <svg viewBox="0 0 24 24" class="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" stroke-linecap="round" /></svg>
    </div>

    <RouterLink v-if="auth.can('orders.create')" to="/marchand/courses/import" class="block text-right text-sm font-medium text-[var(--app-color)]">📥 Importer un fichier Excel ou CSV</RouterLink>

    <div class="flex gap-2 overflow-x-auto -mx-4 px-4 pb-1">
      <button
        v-for="f in filters"
        :key="f.value"
        :class="['tap shrink-0 rounded-full px-4 py-2 text-sm font-medium ring-1', filter === f.value ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
        :style="filter === f.value ? { backgroundColor: 'var(--app-color)' } : {}"
        @click="setFilter(f.value)"
      >
        {{ f.label }}
      </button>
    </div>

    <div class="space-y-2">
      <OrderCard v-for="o in orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
    </div>

    <EmptyState v-if="!loading && !orders.length" icon="🔎" title="Aucune course" :text="search ? 'Essayez un autre nom ou numéro.' : 'Rien dans cette catégorie.'" />
    <p v-if="loading" class="text-center text-slate-400 py-6">Chargement…</p>
    <button v-if="hasMore && !loading" class="m-btn-secondary" @click="load(page + 1)">Voir plus</button>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cachedGet } from '../../composables/useCachedApi'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { orderChanges } from '../../composables/useRealtime'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

const ACTIVE = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress', 'picked_up', 'at_hub', 'delivery_assigned', 'out_for_delivery', 'return_assigned', 'returning']

const filters = [
  { value: 'active', label: 'En cours', statuses: ACTIVE },
  { value: 'problems', label: 'Problèmes', statuses: ['delivery_failed', 'rescheduled'] },
  { value: 'delivered', label: 'Livrées', statuses: ['delivered'] },
  { value: 'other', label: 'Retournées / annulées', statuses: ['returned', 'cancelled', 'rejected'] },
  { value: 'all', label: 'Toutes', statuses: [] },
]

const route = useRoute()
const router = useRouter()
const filter = ref(route.query.filtre || 'active')
const search = ref('')
const orders = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)

async function load(p = 1) {
  loading.value = true
  const statuses = filters.find((f) => f.value === filter.value)?.statuses || []
  try {
    const { data } = await cachedGet('/orders', {
      params: { page: p, per_page: 20, search: search.value || undefined, status: statuses.length ? statuses : undefined },
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

function setFilter(value) {
  filter.value = value
  router.replace({ query: { filtre: value } })
  load()
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(), 300)
}

watch(orderChanges, () => load())
onMounted(load)
</script>
