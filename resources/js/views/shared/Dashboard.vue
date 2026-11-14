<template>
  <!-- Équipe du dispatch : à faire maintenant, point du jour, livreurs -->
  <StaffDashboard v-if="isDispatchStaff" />
  <div v-else class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <h1 class="text-xl font-bold">{{ isMerchant ? 'Mon point de livraisons' : 'Tableau de bord' }}</h1>
      <div class="flex items-end gap-2">
        <div><label class="label" for="from">Du</label><input id="from" v-model="period.from" type="date" class="input" @change="loadSummary"></div>
        <div><label class="label" for="to">Au</label><input id="to" v-model="period.to" type="date" class="input" @change="loadSummary"></div>
      </div>
    </div>

    <!-- Livraisons de la période : courses à livrer ces jours-là (date prévue, sinon jour de création) -->
    <div v-if="summary" class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="font-semibold">🚚 {{ isOneDay ? (period.from === today() ? 'Livraisons du jour' : `Livraisons du ${date(period.from)}`) : 'Livraisons de la période' }}</h2>
      <p class="text-xs text-slate-600">Selon la date de livraison prévue (une course reportée compte le jour de sa nouvelle date)</p>
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
      <StatCard v-if="summary.amounts.other_fees" title="Autres frais" :value="money(summary.amounts.other_fees)" icon="💸" />
      <StatCard :title="isMerchant ? 'Net à recevoir' : 'Net à reverser'" :value="money(summary.amounts.net_to_merchant)" icon="🏦" />
      <StatCard title="Taux de livraison" :value="summary.delivery_rate === null ? '—' : `${summary.delivery_rate} %`" icon="📈" />
    </div>

    <!-- Nouvelles courses : l'activité des e-commerçants, selon le jour de création -->
    <section v-if="!isMerchant && created" class="card p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">🆕 Nouvelles courses {{ isOneDay ? (period.from === today() ? 'du jour' : `du ${date(period.from)}`) : 'de la période' }}</h2>
        <RouterLink :to="{ path: '/admin/courses', query: { date_basis: 'created' } }" class="text-sm text-blue-600">Voir par date de création</RouterLink>
      </div>
      <p class="mt-2 text-sm text-slate-700">
        <strong class="text-2xl text-slate-900 align-middle">{{ created.counts.total }}</strong>
        course{{ created.counts.total > 1 ? 's' : '' }} créée{{ created.counts.total > 1 ? 's' : '' }}<template v-if="createdLater">,
          dont <strong>{{ createdLater }}</strong> à livrer plus tard</template>.
      </p>
    </section>

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
        <li v-if="!recent.length" class="p-4 text-sm text-slate-600">Aucune course pour le moment.</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http from '../../bootstrap/axios'
import StaffDashboard from '../../components/StaffDashboard.vue'
import StatCard from '../../components/StatCard.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { date, money, today } from '../../utils/format'

const auth = useAuthStore()
const isMerchant = computed(() => !!auth.user?.merchant_id)

// Équipe du dispatch : tableau de bord à part (à faire maintenant, point du jour, livreurs)
const isDispatchStaff = computed(() => !isMerchant.value && auth.can('orders.dispatch'))

const period = reactive({ from: isMerchantStart(), to: today() })
const summary = ref(null)
// Personnel : les courses créées sur la période (activité), dont celles à livrer après la période
const created = ref(null)
const createdLater = ref(0)
const isOneDay = computed(() => period.from && period.from === period.to)
const recent = ref([])

// Marchand : depuis le début du mois ; personnel : aujourd'hui
function isMerchantStart() {
  return useAuthStore().user?.merchant_id ? `${today().slice(0, 8)}01` : today()
}

async function loadSummary() {
  const [delivery, byCreation, later] = await Promise.all([
    http.get('/reports/summary', { params: { ...period, date_basis: 'delivery' } }),
    isMerchant.value ? null : http.get('/reports/summary', { params: { ...period, date_basis: 'created' } }),
    isMerchant.value ? null : http.get('/orders', { params: { ...period, date_basis: 'created', queue: 'scheduled', per_page: 1 } }),
  ])
  summary.value = delivery.data.data
  created.value = byCreation?.data.data ?? null
  createdLater.value = later?.data.meta?.total ?? 0
}

async function loadRecent() {
  recent.value = (await http.get('/orders', { params: { per_page: 8 } })).data.data
}

function refresh() {
  if (isDispatchStaff.value) return
  loadSummary()
  loadRecent()
}

watch(orderChanges, refresh)
onMounted(refresh)
</script>
