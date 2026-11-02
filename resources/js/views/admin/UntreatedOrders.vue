<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <RouterLink to="/admin/courses" class="text-sm text-blue-600">‹ Courses</RouterLink>
        <h1 class="text-xl font-bold">⏳ Courses non traitées</h1>
        <p class="text-sm text-gray-600">Date de livraison dépassée, et pas encore livrées, retournées ni annulées. Toutes les dates, jusqu'à ce qu'elles soient réglées.</p>
      </div>
      <p v-if="data" class="rounded-full bg-red-100 px-3 py-1 text-sm font-semibold text-red-800">{{ data.total }} course{{ data.total > 1 ? 's' : '' }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Regrouper">
      <span class="text-gray-600">Regrouper :</span>
      <button
        v-for="g in GROUPINGS" :key="g.value" type="button"
        :class="['rounded-full px-3 py-1 ring-1', by === g.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="by === g.value" @click="setBy(g.value)"
      >
        {{ g.label }}
      </button>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-else-if="data && !data.total" class="card p-6 text-center text-gray-500">✅ Aucune course non traitée : tout est à jour.</p>

    <div v-else-if="data" class="grid gap-4 lg:grid-cols-[18rem_1fr]">
      <!-- Groupes : date, livreur ou e-commerçant -->
      <nav class="card divide-y self-start" aria-label="Groupes">
        <button
          type="button"
          :class="['flex w-full items-center justify-between px-3 py-2 text-left text-sm', selected === null ? 'bg-slate-100 font-semibold' : 'hover:bg-slate-50']"
          @click="select(null)"
        >
          Toutes <span class="rounded-full bg-slate-200 px-2 text-xs">{{ data.total }}</span>
        </button>
        <button
          v-for="g in data.groups" :key="g.key" type="button"
          :class="['flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm', selected === g.key ? 'bg-slate-100 font-semibold' : 'hover:bg-slate-50']"
          @click="select(g.key)"
        >
          <span class="min-w-0">
            <span class="block truncate first-letter:uppercase">{{ g.label }}</span>
            <span class="block text-xs font-normal text-gray-500">{{ money(g.amount) }} à encaisser<template v-if="g.oldest"> · depuis le {{ date(g.oldest) }}</template></span>
          </span>
          <span class="shrink-0 rounded-full bg-red-100 px-2 text-xs font-semibold text-red-800">{{ g.count }}</span>
        </button>
      </nav>

      <!-- Courses du groupe choisi -->
      <section class="space-y-2">
        <input v-model="search" class="input" placeholder="Code, téléphone, nom…" aria-label="Rechercher" @input="debounced">
        <p v-if="loading" class="card p-6 text-center text-gray-500">Chargement…</p>
        <p v-else-if="!orders.length" class="card p-6 text-center text-gray-500">Aucune course.</p>
        <div v-else class="card divide-y">
          <RouterLink
            v-for="o in orders" :key="o.id" :to="`/admin/courses/${o.id}`"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm hover:bg-slate-50"
          >
            <span class="w-28 font-mono text-xs font-semibold">{{ o.tracking_code }}</span>
            <span class="min-w-[10rem] flex-1">
              {{ o.merchant?.business_name }} → {{ o.recipient.name }}
              <span class="block text-xs text-gray-500">{{ o.delivery.zone_name || o.delivery.address }} · {{ courierOf(o) || 'sans livreur' }}</span>
            </span>
            <StatusBadge :status="o.status" :label="o.status_label" />
            <span class="w-24 text-right text-xs text-red-700">prévue le {{ date(dayOf(o)) }}</span>
            <span class="w-24 text-right tabular-nums">{{ money(o.amounts.cod_amount) }}</span>
          </RouterLink>
        </div>
        <Pagination :meta="meta" @change="load" />
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Pagination from '../../components/Pagination.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { date, money } from '../../utils/format'

/**
 * Courses non traitées : jour de livraison dépassé, course pas terminée. Regroupées par date prévue, par livreur ou par
 * e-commerçant ; un groupe choisi affiche ses courses.
 */
const GROUPINGS = [
  { value: 'date', label: '📅 Par date' },
  { value: 'courier', label: '🛵 Par livreur' },
  { value: 'merchant', label: '🏪 Par e-commerçant' },
]
const route = useRoute()
const router = useRouter()
const by = ref(GROUPINGS.some((g) => g.value === route.query.by) ? route.query.by : 'date')
const selected = ref(null)
const data = ref(null)
const orders = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref('')
const search = ref('')

const dayOf = (o) => (o.delivery.scheduled_date || o.created_at || '').slice(0, 10)
const courierOf = (o) => o.delivery_courier?.name || o.pickup_courier?.name

async function loadGroups() {
  try {
    data.value = (await http.get('/orders/untreated-groups', { params: { by: by.value } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function filtersFor(key) {
  if (key === null) return {}
  if (by.value === 'date') return { from: key, to: key, date_basis: 'delivery' }
  if (by.value === 'courier') return key === 'none' ? { without_courier: 1 } : { lead_courier_id: key }
  return { merchant_id: key }
}

async function load(page = 1) {
  loading.value = true
  try {
    const params = { queue: 'untreated', page, per_page: 50, search: search.value || undefined, ...filtersFor(selected.value) }
    const res = (await http.get('/orders', { params })).data
    orders.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

let timer = null
function debounced() {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
}

function select(key) {
  selected.value = key
  load(1)
}

function setBy(value) {
  by.value = value
  selected.value = null
  router.replace({ query: { by: value } })
  loadGroups()
  load(1)
}

onMounted(() => {
  loadGroups()
  load(1)
})
</script>
