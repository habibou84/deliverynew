<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Courses</h1>
      <div v-if="auth.can('orders.create')" class="flex gap-2">
        <RouterLink :to="`${auth.homeRoute}/courses/import`" class="btn-secondary">📥 Importer</RouterLink>
        <RouterLink :to="`${auth.homeRoute}/courses/nouvelle`" class="btn-primary">+ Nouvelle course</RouterLink>
      </div>
    </div>

    <!-- Files d'attente du dispatch -->
    <div v-if="isStaff" class="flex gap-2 overflow-x-auto pb-1">
      <button
        v-for="q in queues"
        :key="q.value"
        :class="['btn whitespace-nowrap', filters.queue === q.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']"
        @click="setQueue(q.value)"
      >
        {{ q.label }}
      </button>
    </div>

    <div class="card p-3 grid grid-cols-2 md:grid-cols-6 gap-2">
      <input v-model="filters.search" class="input col-span-2" placeholder="Code, téléphone, nom, référence…" @input="debouncedLoad">
      <select v-model="filters.status" class="input" @change="load(1)">
        <option value="">Tous les statuts</option>
        <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
      </select>
      <select v-if="isStaff" v-model="filters.merchant_id" class="input" @change="load(1)">
        <option value="">Tous les marchands</option>
        <option v-for="m in merchants" :key="m.id" :value="String(m.id)">{{ m.business_name }}</option>
      </select>
      <input v-model="filters.from" type="date" class="input" @change="load(1)">
      <input v-model="filters.to" type="date" class="input" @change="load(1)">
    </div>

    <p v-if="filters.courier_id" class="text-sm">
      Filtré sur un livreur · <button class="text-blue-600" @click="filters.courier_id = ''; load(1)">retirer le filtre</button>
    </p>

    <!-- Assignation groupée -->
    <div v-if="isStaff && selected.length" class="card p-3 flex flex-wrap items-center gap-2 bg-blue-50 border-blue-200">
      <span class="text-sm font-medium">{{ selected.length }} sélectionnée(s)</span>
      <select v-model="bulk.type" class="input w-auto">
        <option value="pickup">Ramassage</option>
        <option value="delivery">Livraison</option>
        <option value="return">Retour</option>
      </select>
      <select v-model="bulk.courier_id" class="input w-auto">
        <option :value="null" disabled>Livreur…</option>
        <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.active_assignments_count }} en cours){{ c.is_available ? '' : ' · indisponible' }}</option>
      </select>
      <button class="btn-primary" :disabled="!bulk.courier_id || assigning" @click="bulkAssign">Assigner</button>
      <button class="btn-secondary" @click="selected = []">Annuler</button>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr>
            <th v-if="isStaff" class="p-2 w-8"><input type="checkbox" :checked="allSelected" aria-label="Tout sélectionner" @change="toggleAll"></th>
            <th class="p-2">Code</th>
            <th v-if="isStaff" class="p-2">Marchand</th>
            <th class="p-2">Destinataire</th>
            <th class="p-2">Trajet</th>
            <th class="p-2">Statut</th>
            <th class="p-2">Livreur</th>
            <th class="p-2 text-right">À encaisser</th>
            <th class="p-2">Créée</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-if="loading"><td colspan="9" class="p-6 text-center text-gray-500">Chargement…</td></tr>
          <tr v-else-if="!orders.length"><td colspan="9" class="p-6 text-center text-gray-500">Aucune course.</td></tr>
          <tr
            v-for="o in orders"
            v-else
            :key="o.id"
            :class="['hover:bg-slate-50 cursor-pointer', highlighted === o.id ? 'bg-yellow-50' : '']"
            @click="open(o)"
          >
            <td v-if="isStaff" class="p-2" @click.stop><input v-model="selected" type="checkbox" :value="o.id" :aria-label="`Sélectionner ${o.tracking_code}`"></td>
            <td class="p-2 font-mono text-xs">{{ o.tracking_code }}</td>
            <td v-if="isStaff" class="p-2">{{ o.merchant?.business_name }}</td>
            <td class="p-2">
              <div>{{ o.recipient.name || '—' }}</div>
              <div class="text-xs text-gray-500">{{ o.recipient.phone }}</div>
            </td>
            <td class="p-2 whitespace-nowrap">{{ o.pickup.zone_name }} → {{ o.delivery.zone_name }}</td>
            <td class="p-2">
              <StatusBadge :status="o.status" :label="o.status_label" />
              <div v-if="o.last_incident && ['delivery_failed', 'rescheduled'].includes(o.status)" class="text-xs text-red-600 mt-0.5">{{ o.last_incident.label }}</div>
              <div v-if="o.return_requested && !['returned', 'return_assigned', 'returning'].includes(o.status)" class="text-xs text-rose-600">Retour demandé</div>
              <div v-if="o.status === 'rescheduled' && o.delivery.scheduled_date" :class="['text-xs', o.delivery.scheduled_date <= todayIso ? 'text-emerald-700 font-medium' : 'text-gray-600']">📅 {{ o.delivery.scheduled_date <= todayIso ? 'Prévue aujourd\'hui' : date(o.delivery.scheduled_date) }}</div>
              <button v-if="canDecide(o)" type="button" class="mt-1 text-xs rounded bg-blue-600 text-white px-2 py-0.5" @click.stop="deciding = o">Décider</button>
            </td>
            <td class="p-2 text-xs">
              <div v-if="o.pickup_courier">↑ {{ o.pickup_courier.name }}</div>
              <div v-if="o.delivery_courier">↓ {{ o.delivery_courier.name }}</div>
              <div v-if="o.held_by" class="text-amber-700" :title="`Colis chez ${o.held_by.name}`">🎒 {{ o.held_by.name }}</div>
            </td>
            <td class="p-2 text-right whitespace-nowrap">{{ money(o.amounts.cod_amount) }}</td>
            <td class="p-2 text-xs text-gray-500 whitespace-nowrap">{{ dateTime(o.created_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <Pagination :meta="meta" @change="load" />

    <OrderDecision :open="!!deciding" :order="deciding" :couriers="couriers" @close="deciding = null" @decided="decided" />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatusBadge from '../../components/StatusBadge.vue'
import Pagination from '../../components/Pagination.vue'
import OrderDecision from '../../components/OrderDecision.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { orderChanges, lastOrderChange } from '../../composables/useRealtime'
import { date, dateTime, money, STATUS_LABELS } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const route = useRoute()

const isStaff = computed(() => !auth.user?.merchant_id)

const queues = [
  { value: '', label: 'Toutes' },
  { value: 'to_confirm', label: 'À valider' },
  { value: 'to_pickup', label: 'À ramasser' },
  { value: 'to_prepare', label: 'À préparer' },
  { value: 'to_deliver', label: 'À livrer' },
  { value: 'to_decide', label: 'À décider' },
  { value: 'scheduled', label: 'Reportées' },
  { value: 'incidents', label: 'Incidents' },
  { value: 'to_return', label: 'À retourner' },
]

const filters = reactive({
  queue: route.query.queue || '',
  search: route.query.search || '',
  status: '',
  merchant_id: route.query.merchant_id || '',
  courier_id: route.query.courier_id || '',
  from: '',
  to: '',
})

const orders = ref([])
const meta = ref(null)
const loading = ref(false)
const merchants = ref([])
const couriers = ref([])
const selected = ref([])
const bulk = reactive({ type: 'pickup', courier_id: null })
const assigning = ref(false)
const highlighted = ref(null)

const allSelected = computed(() => orders.value.length > 0 && selected.value.length === orders.value.length)

async function load(page = 1) {
  loading.value = orders.value.length === 0
  const params = { page }
  for (const [k, v] of Object.entries(filters)) {
    if (v) params[k] = k === 'status' ? [v] : v
  }
  const { data } = await http.get('/orders', { params })
  orders.value = data.data
  meta.value = data.meta
  loading.value = false
  selected.value = selected.value.filter((id) => orders.value.some((o) => o.id === id))
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
}

function setQueue(value) {
  filters.queue = value
  bulk.type = { to_deliver: 'delivery', incidents: 'delivery', to_return: 'return' }[value] || 'pickup'
  router.replace({ query: value ? { queue: value } : {} })
  load(1)
}

function toggleAll(e) {
  selected.value = e.target.checked ? orders.value.map((o) => o.id) : []
}

// Suite à donner à un colis non livré
const deciding = ref(null)
const todayIso = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const canDecide = (o) => auth.can('orders.dispatch') && ['delivery_failed', 'rescheduled'].includes(o.status) && !['return_assigned', 'returning'].includes(o.status)
function decided(order) {
  deciding.value = null
  toasts.success(order.status === 'rescheduled' ? `Relivraison prévue le ${date(order.delivery.scheduled_date)}.` : (order.return_requested ? 'Retour au marchand décidé.' : 'Décision enregistrée.'))
  load(meta.value?.current_page || 1)
}

function open(order) {
  router.push(`${auth.homeRoute}/courses/${order.id}`)
}

async function bulkAssign() {
  assigning.value = true
  try {
    const { data } = await http.post('/orders/bulk-assign', { order_ids: selected.value, ...bulk })
    const errors = Object.entries(data.data.errors)
    if (data.data.assigned.length) toasts.success(`${data.data.assigned.length} course(s) assignée(s).`)
    errors.forEach(([id, message]) => {
      const code = orders.value.find((o) => o.id === Number(id))?.tracking_code || id
      toasts.error(`${code} : ${message}`)
    })
    selected.value = []
    await load(meta.value?.current_page || 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    assigning.value = false
  }
}

watch(() => route.query.search, (value) => {
  if (value === undefined) return
  filters.search = value
  load(1)
})

// Rafraîchissement temps réel
watch(orderChanges, async () => {
  await load(meta.value?.current_page || 1)
  highlighted.value = lastOrderChange.value?.order?.id
  setTimeout(() => { highlighted.value = null }, 3000)
})

onMounted(async () => {
  await load()
  if (isStaff.value) {
    const empty = { data: { data: [] } }
    const [m, c] = await Promise.all([
      http.get('/merchants', { params: { per_page: 200 } }).catch(() => empty),
      auth.can('orders.dispatch') ? http.get('/couriers') : Promise.resolve(empty),
    ])
    merchants.value = m.data.data
    couriers.value = c.data.data
  }
})
</script>
