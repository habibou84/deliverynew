<template>
  <div class="space-y-4">
    <div v-if="!embedded" class="flex flex-wrap items-center justify-between gap-2">
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
        <!-- Actions sur les courses cochées -->
        <div v-if="checked.length" class="card sticky top-2 z-10 flex flex-wrap items-center gap-2 border-blue-200 bg-blue-50 p-2 text-sm">
          <span class="font-medium">{{ checked.length }} cochée{{ checked.length > 1 ? 's' : '' }}</span>
          <input v-model="bulkDate" type="date" class="input w-auto !py-1" :min="todayIso" aria-label="Nouvelle date">
          <button type="button" class="btn-primary !py-1" :disabled="busy || !bulkDate" @click="rescheduleMany(checkedOrders, bulkDate)">📅 Reporter</button>
          <select v-model="bulkCourier" class="input w-auto !py-1" aria-label="Livreur">
            <option :value="null">Livreur…</option>
            <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <button type="button" class="btn-secondary !py-1" :disabled="busy || !bulkCourier" @click="assignMany">🛵 Confier</button>
          <button type="button" class="btn-secondary !py-1" :disabled="busy || !returnable.length" @click="returnMany">↩️ Retour ({{ returnable.length }})</button>
          <button type="button" class="ml-auto text-gray-600" @click="checked = []">Annuler</button>
        </div>

        <p v-if="loading" class="card p-6 text-center text-gray-500">Chargement…</p>
        <p v-else-if="!orders.length" class="card p-6 text-center text-gray-500">Aucune course.</p>
        <div v-else class="card divide-y">
          <div v-for="o in orders" :key="o.id" class="flex flex-wrap items-start gap-x-3 gap-y-2 px-3 py-2 text-sm hover:bg-slate-50">
            <input v-model="checked" type="checkbox" :value="o.id" class="mt-1" :aria-label="`Choisir ${o.tracking_code}`">
            <RouterLink :to="`/admin/courses/${o.id}`" class="min-w-[12rem] flex-1">
              <span class="font-mono text-xs font-semibold text-blue-700">{{ o.tracking_code }}</span>
              <span class="ml-1">{{ o.merchant?.business_name }} → {{ o.recipient.name }}</span>
              <span class="block text-xs text-gray-500">{{ o.delivery.zone_name || o.delivery.address }} · {{ money(o.amounts.cod_amount) }}</span>
            </RouterLink>
            <div class="w-36">
              <StatusBadge :status="o.status" :label="o.status_label" />
              <span class="block text-xs text-red-700">prévue le {{ date(dayOf(o)) }}</span>
            </div>
            <CourierCell :order="o" :couriers="couriers" can-dispatch class="w-48" @updated="replace" />
            <!-- Actions à portée de main, selon l'état de la course -->
            <div class="flex flex-wrap items-center gap-1">
              <button v-if="o.needs_decision || o.status === 'delivery_failed'" type="button" class="btn-primary !px-2 !py-1 text-xs" @click="deciding = o">Décider</button>
              <template v-if="canReschedule(o)">
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" :disabled="busy" title="Livrer aujourd'hui" @click="rescheduleMany([o], todayIso)">Aujourd'hui</button>
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" :disabled="busy" title="Livrer demain" @click="rescheduleMany([o], tomorrowIso)">Demain</button>
              </template>
              <button v-if="canReturn(o)" type="button" class="btn-secondary !px-2 !py-1 text-xs" :disabled="busy" @click="returnMany([o])">↩️ Retour</button>
              <a v-if="o.recipient.phone" :href="`tel:${o.recipient.phone}`" class="btn-secondary !px-2 !py-1 text-xs" :title="`Appeler ${o.recipient.name || 'le client'}`">📞</a>
              <button v-if="o.edit_scope" type="button" class="btn-secondary !px-2 !py-1 text-xs" title="Modifier la course" @click="editing = o">✏️</button>
            </div>
          </div>
        </div>
        <Pagination :meta="meta" @change="load" />
      </section>
    </div>

    <OrderDecision :open="!!deciding" :order="deciding" :couriers="couriers" @close="deciding = null" @decided="afterAction" />
    <OrderEditModal :open="!!editing" :order="editing" focus="date" @close="editing = null" @saved="afterAction" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Pagination from '../../components/Pagination.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import CourierCell from '../../components/CourierCell.vue'
import OrderDecision from '../../components/OrderDecision.vue'
import OrderEditModal from '../../components/OrderEditModal.vue'
import { useToastStore } from '../../stores/toasts'
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
const props = defineProps({ embedded: Boolean, stage: { type: String, default: 'all' } })
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
    data.value = (await http.get('/orders/untreated-groups', { params: { by: by.value, stage: props.stage } })).data.data
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
    const params = { queue: props.stage === 'picked' ? 'untreated_picked' : 'untreated', page, per_page: 50, search: search.value || undefined, ...filtersFor(selected.value) }
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
  router.replace({ query: { ...route.query, by: value } })
  loadGroups()
  load(1)
}

// ── Actions à portée de main ──
const toasts = useToastStore()
const couriers = ref([])
const checked = ref([])
const busy = ref(false)
const deciding = ref(null)
const editing = ref(null)
const bulkCourier = ref(null)
const isoDay = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const todayIso = isoDay(new Date())
const tomorrowIso = isoDay(new Date(Date.now() + 86400000))
const bulkDate = ref(todayIso)
const NOT_PICKED = ['pending', 'confirmed', 'pickup_assigned']
const PICKED = ['picked_up', 'at_hub', 'rescheduled', 'delivery_assigned', 'delivery_failed']

const checkedOrders = computed(() => orders.value.filter((o) => checked.value.includes(o.id)))
const canReschedule = (o) => !!o.edit_scope && !o.return_requested && o.status !== 'delivery_failed'
const canReturn = (o) => PICKED.includes(o.status) && !o.return_requested
const returnable = computed(() => checkedOrders.value.filter(canReturn))

function replace(updated) {
  const i = orders.value.findIndex((o) => o.id === updated.id)
  if (i !== -1) orders.value[i] = updated
}

async function afterAction() {
  deciding.value = null
  editing.value = null
  await Promise.all([loadGroups(), load(meta.value?.current_page || 1)])
}

async function run(action, success) {
  busy.value = true
  try {
    await action()
    toasts.success(success)
    checked.value = []
    await afterAction()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

// Nouvelle date : colis pas encore ramassés par « À reporter » (e-commerçant prévenu), les autres par une modification
function rescheduleMany(list, day) {
  const late = list.filter((o) => o.to_reschedule).map((o) => o.id)
  const others = list.filter((o) => !o.to_reschedule && canReschedule(o))
  const label = day === todayIso ? 'aujourd\'hui' : `le ${date(day)}`
  return run(async () => {
    if (late.length) await http.post('/orders/reschedule', { order_ids: late, date: day })
    for (const o of others) await http.patch(`/orders/${o.id}`, { delivery_scheduled_date: day, notify_courier: true })
  }, `${late.length + others.length} course${late.length + others.length > 1 ? 's' : ''} : livraison ${label}.`)
}

function assignMany() {
  const list = checkedOrders.value
  return run(async () => {
    for (const o of list) {
      const type = o.awaiting_courier?.stage || (NOT_PICKED.includes(o.status) ? 'pickup' : 'delivery')
      await http.post(`/orders/${o.id}/assign`, { type, courier_id: bulkCourier.value })
    }
  }, `${list.length} course${list.length > 1 ? 's' : ''} confiée${list.length > 1 ? 's' : ''} à ${couriers.value.find((c) => c.id === bulkCourier.value)?.name}.`)
}

function returnMany(list = returnable.value) {
  if (!window.confirm(`Faire revenir ${list.length > 1 ? `ces ${list.length} colis` : 'ce colis'} chez l'e-commerçant ?`)) return
  return run(async () => {
    for (const o of list) await http.post(`/orders/${o.id}/return-request`, { note: 'Course non traitée : retour décidé par l\'agence' })
  }, `Retour demandé pour ${list.length} colis.`)
}

watch(() => props.stage, () => {
  selected.value = null
  loadGroups()
  load(1)
})

onMounted(async () => {
  loadGroups()
  load(1)
  couriers.value = (await http.get('/couriers').catch(() => ({ data: { data: [] } }))).data.data
})
</script>
