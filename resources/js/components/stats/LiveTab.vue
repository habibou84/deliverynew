<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
      <KpiTile title="Lives" :value="data.kpis.lives" :previous="data.previous_kpis.lives" />
      <KpiTile title="E-commerçants en live" :value="data.kpis.merchants" />
      <KpiTile title="Commandes prises en live" :value="data.kpis.orders" :previous="data.previous_kpis.orders" />
      <KpiTile title="Colis du live livrés" kind="percent" :value="data.kpis.delivered_rate" />
      <KpiTile title="Part des courses venant du live" kind="percent" :value="data.kpis.share" :previous="data.previous_kpis.share" />
      <KpiTile v-if="data.kpis.agency_amount !== null" title="Gagné par l'agence sur le live" kind="money" :value="data.kpis.agency_amount" hint="Part laissée à l'agence" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <SortableTable :exportable="exportable" title="Lives par e-commerçant" :columns="columns" :rows="data.merchants" initial-sort="orders" :filename="`lives_${period}.csv`" empty="Aucun live sur la période.">
        <template #delivered_rate="{ row }"><span :class="row.delivered_rate !== null && row.delivered_rate < 75 ? 'text-red-600' : ''">{{ row.delivered_rate === null ? '—' : `${row.delivered_rate} %` }}</span></template>
      </SortableTable>
      <section class="card space-y-3 p-4">
        <h2 class="font-semibold">Meilleurs moments du live <span class="text-sm font-normal text-slate-600">· commandes par tranche de 5 minutes</span></h2>
        <BarList :items="moments" :color="SERIES[2] || SERIES[0]" empty="Pas encore de commande en live." />
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import BarList from './BarList.vue'
import KpiTile from './KpiTile.vue'
import SortableTable from './SortableTable.vue'
import { SERIES } from './chart'

/** Statistiques › Live : lives des e-commerçants et commandes prises */
const props = defineProps({
  data: { type: Object, required: true },
  exportable: { type: Boolean, default: true },
})

const period = computed(() => `${props.data.period.from}_${props.data.period.to}`)
const moments = computed(() => props.data.minutes
  .map((count, i) => ({ label: i === 12 ? 'après 1 h' : `${i * 5}e – ${i * 5 + 5}e minute`, count }))
  .filter((m) => m.count))
const columns = [
  { key: 'name', label: 'E-commerçant', asc: true },
  { key: 'lives', label: 'Lives', align: 'right' },
  { key: 'orders', label: 'Commandes', align: 'right' },
  { key: 'delivered_rate', label: 'Livrées', align: 'right' },
]
</script>
