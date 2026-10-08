<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Mes paiements</h1>

    <div v-if="summary" class="grid sm:grid-cols-3 gap-3">
      <StatCard title="Disponible (prochain reversement)" :value="money(summary.available)" icon="🏦" />
      <StatCard title="En attente (argent chez le livreur)" :value="money(summary.pending_cash)" icon="🛵" />
      <StatCard title="Total non reversé" :value="money(summary.unpaid_total)" icon="💰" />
    </div>

    <div class="card">
      <h2 class="font-semibold p-4 border-b">Mes reversements</h2>
      <ul class="divide-y">
        <li v-for="p in payouts" :key="p.id">
          <RouterLink :to="`/marchand/paiements/${p.id}`" class="flex flex-wrap items-center gap-3 p-3 hover:bg-slate-50 text-sm">
            <span class="font-mono text-xs">{{ p.reference }}</span>
            <span class="flex-1">{{ date(p.period_start) }} → {{ date(p.period_end) }}</span>
            <span :class="['font-semibold', signedClass(p.net_amount)]">{{ money(p.net_amount) }}</span>
            <PayoutBadge :status="p.status" :label="p.status_label" />
          </RouterLink>
        </li>
        <li v-if="!payouts.length" class="p-4 text-sm text-gray-500">Aucun reversement pour le moment.</li>
      </ul>
    </div>

    <div class="card">
      <h2 class="font-semibold p-4 border-b">Détail des opérations</h2>
      <LedgerTable :entries="entries" />
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import StatCard from '../../components/StatCard.vue'
import LedgerTable from '../../components/LedgerTable.vue'
import PayoutBadge from '../../components/PayoutBadge.vue'
import { useAuthStore } from '../../stores/auth'
import { date, money, signedClass } from '../../utils/format'

const auth = useAuthStore()
const summary = ref(null)
const entries = ref([])
const payouts = ref([])

onMounted(async () => {
  const [ledger, list] = await Promise.all([
    http.get(`/finance/merchants/${auth.user.merchant_id}/ledger`),
    http.get('/finance/payouts'),
  ])
  summary.value = ledger.data.summary
  entries.value = ledger.data.data
  payouts.value = list.data.data
})
</script>
