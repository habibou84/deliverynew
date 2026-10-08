<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <h1 class="text-xl font-bold">{{ isMerchant ? 'Mon point de livraisons' : 'Tableau de bord' }}</h1>
      <div class="flex items-end gap-2">
        <div><label class="label" for="from">Du</label><input id="from" v-model="period.from" type="date" class="input" @change="loadSummary"></div>
        <div><label class="label" for="to">Au</label><input id="to" v-model="period.to" type="date" class="input" @change="loadSummary"></div>
      </div>
    </div>

    <!-- Files d'attente du dispatch (personnel) -->
    <div v-if="!isMerchant && auth.can('orders.dispatch')" class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <RouterLink
        v-for="q in queues"
        :key="q.value"
        :to="{ path: '/admin/courses', query: { queue: q.value } }"
        :class="['card p-4 hover:shadow', q.count ? q.accent : '']"
      >
        <p class="text-sm text-gray-600">{{ q.label }}</p>
        <p class="text-2xl font-bold">{{ q.count ?? '—' }}</p>
      </RouterLink>
    </div>

    <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
      <StatCard title="Total" :value="summary.counts.total" icon="📦" />
      <StatCard title="Livrés" :value="summary.counts.delivered" icon="✅" />
      <StatCard title="En cours" :value="summary.counts.in_progress" icon="🚚" />
      <StatCard title="Non livrés" :value="summary.counts.failed" icon="⚠️" />
      <StatCard title="Reportés" :value="summary.counts.rescheduled" icon="📅" />
      <StatCard title="Retournés" :value="summary.counts.returned + summary.counts.returning" icon="↩️" />
      <StatCard title="Annulés" :value="summary.counts.cancelled" icon="✖️" />
    </div>

    <div v-if="summary" class="grid md:grid-cols-4 xl:grid-cols-5 gap-3">
      <StatCard title="Montant encaissé" :value="money(summary.amounts.collected)" icon="💰" />
      <StatCard title="Frais de livraison retenus" :value="money(summary.amounts.fees)" icon="🧾" />
      <StatCard v-if="summary.amounts.shipping_fees" title="Frais d'expédition" :value="money(summary.amounts.shipping_fees)" icon="🚌" />
      <StatCard :title="isMerchant ? 'Net à recevoir' : 'Net à reverser'" :value="money(summary.amounts.net_to_merchant)" icon="🏦" />
      <StatCard title="Taux de livraison" :value="summary.delivery_rate === null ? '—' : `${summary.delivery_rate} %`" icon="📈" />
    </div>

    <div class="card">
      <div class="flex items-center justify-between p-4 border-b">
        <h2 class="font-semibold">Dernières courses</h2>
        <RouterLink :to="`${auth.homeRoute}/courses`" class="text-sm text-blue-600">Tout voir</RouterLink>
      </div>
      <ul class="divide-y">
        <li v-for="o in recent" :key="o.id">
          <RouterLink :to="`${auth.homeRoute}/courses/${o.id}`" class="flex flex-wrap items-center gap-3 p-3 hover:bg-slate-50 text-sm">
            <span class="font-mono text-xs">{{ o.tracking_code }}</span>
            <span class="flex-1">{{ o.recipient.name || o.recipient.phone }} · {{ o.delivery.zone_name }}</span>
            <StatusBadge :status="o.status" :label="o.status_label" />
          </RouterLink>
        </li>
        <li v-if="!recent.length" class="p-4 text-sm text-gray-500">Aucune course pour le moment.</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http from '../../bootstrap/axios'
import StatCard from '../../components/StatCard.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { money, today } from '../../utils/format'

const auth = useAuthStore()
const isMerchant = computed(() => !!auth.user?.merchant_id)

const period = reactive({ from: isMerchantStart(), to: today() })
const summary = ref(null)
const recent = ref([])
const queues = ref([
  { value: 'to_confirm', label: 'À valider', count: null, accent: 'border-amber-300 bg-amber-50' },
  { value: 'to_pickup', label: 'À ramasser', count: null, accent: 'border-indigo-300 bg-indigo-50' },
  { value: 'to_deliver', label: 'À livrer', count: null, accent: 'border-blue-300 bg-blue-50' },
  { value: 'incidents', label: 'Incidents', count: null, accent: 'border-red-300 bg-red-50' },
  { value: 'to_return', label: 'À retourner', count: null, accent: 'border-rose-300 bg-rose-50' },
])

// Marchand : depuis le début du mois ; personnel : aujourd'hui
function isMerchantStart() {
  return useAuthStore().user?.merchant_id ? `${today().slice(0, 8)}01` : today()
}

async function loadSummary() {
  summary.value = (await http.get('/reports/summary', { params: period })).data.data
}

async function loadQueues() {
  if (isMerchant.value || !auth.can('orders.dispatch')) return
  await Promise.all(queues.value.map(async (q) => {
    const { data } = await http.get('/orders', { params: { queue: q.value, per_page: 1 } })
    q.count = data.meta.total
  }))
}

async function loadRecent() {
  recent.value = (await http.get('/orders', { params: { per_page: 8 } })).data.data
}

function refresh() {
  loadSummary()
  loadQueues()
  loadRecent()
}

watch(orderChanges, refresh)
onMounted(refresh)
</script>
