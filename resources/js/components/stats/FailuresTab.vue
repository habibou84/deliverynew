<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
      <KpiTile title="Échecs de livraison" :value="data.kpis.failed_attempts" :previous="data.previous_kpis.failed_attempts" :higher-is-better="false" />
      <KpiTile title="Taux d'échec" kind="percent" :value="data.kpis.failure_rate" :previous="data.previous_kpis.failure_rate" :higher-is-better="false" hint="Passages ratés parmi tous les passages" />
      <KpiTile title="Annulations" :value="data.kpis.cancelled" :previous="data.previous_kpis.cancelled" :higher-is-better="false" />
      <KpiTile title="Colis revenus" :value="data.kpis.returned" :previous="data.previous_kpis.returned" :higher-is-better="false" />
      <KpiTile v-if="data.kpis.lost_fees !== null" title="Frais non gagnés" kind="money" :value="data.kpis.lost_fees" hint="Frais de livraison des colis revenus" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card space-y-3 p-4">
        <h2 class="font-semibold">Pourquoi les livraisons échouent</h2>
        <BarList :items="data.reasons" :color="SERIES[1]" empty="Aucun échec sur la période. 👏" />
      </section>
      <section class="card space-y-3 p-4">
        <h2 class="font-semibold">Clientes qui refusent souvent <span class="text-sm font-normal text-slate-500">· 3 colis revenus ou plus</span></h2>
        <ul v-if="data.customers.length" class="divide-y text-sm">
          <li v-for="c in data.customers" :key="c.phone" class="flex items-center justify-between gap-2 py-2">
            <span><b>{{ c.name || 'Sans nom' }}</b> · {{ localPhone(c.phone) }}</span>
            <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', c.refusals >= 5 ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800']">{{ c.refusals }} refus · {{ c.refusals >= 5 ? 'acompte conseillé' : 'à surveiller' }}</span>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-500">Aucune cliente à surveiller sur la période.</p>
      </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <SortableTable :exportable="exportable" title="Où ça échoue : par commune" :columns="zoneColumns" :rows="data.by_zone" initial-sort="failure_rate" :filename="`echecs-communes_${period}.csv`" empty="Pas assez de passages pour comparer.">
        <template #failure_rate="{ row }"><span :class="rateClass(row.failure_rate)">{{ pct(row.failure_rate) }}</span></template>
      </SortableTable>
      <SortableTable :exportable="exportable" title="Chez quels e-commerçants" :columns="merchantColumns" :rows="data.by_merchant" initial-sort="failure_rate" :filename="`echecs-e-commercants_${period}.csv`" empty="Pas assez de passages pour comparer.">
        <template #failure_rate="{ row }"><span :class="rateClass(row.failure_rate)">{{ pct(row.failure_rate) }}</span></template>
      </SortableTable>
    </div>

    <!-- Annulations : par e-commerçant, par membre de l'équipe, par motif (même période) -->
    <h2 class="pt-2 text-lg font-semibold">Annulations</h2>
    <Cancellations section="report" embedded :from="data.period.from" :to="data.period.to" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import Cancellations from '../../views/admin/Cancellations.vue'
import { localPhone } from '../../utils/format'
import BarList from './BarList.vue'
import KpiTile from './KpiTile.vue'
import SortableTable from './SortableTable.vue'
import { SERIES } from './chart'

/** Statistiques › Échecs et annulations */
const props = defineProps({
  data: { type: Object, required: true },
  exportable: { type: Boolean, default: true },
})

const period = computed(() => `${props.data.period.from}_${props.data.period.to}`)
const pct = (v) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')} %`)
const rateClass = (v) => (v >= 20 ? 'font-semibold text-red-700' : v >= 10 ? 'text-amber-700' : 'text-emerald-700')
const zoneColumns = [
  { key: 'name', label: 'Commune', asc: true },
  { key: 'delivered', label: 'Livrées', align: 'right' },
  { key: 'failed', label: 'Échecs', align: 'right' },
  { key: 'failure_rate', label: 'Taux d\'échec', align: 'right' },
]
const merchantColumns = [
  { key: 'name', label: 'E-commerçant', asc: true },
  { key: 'failed', label: 'Échecs', align: 'right' },
  { key: 'failure_rate', label: 'Taux d\'échec', align: 'right' },
  { key: 'cancelled', label: 'Annulées', align: 'right' },
]
</script>
