<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="E-commerçants actifs" :value="data.totals.active" :hint="`ont créé des courses, sur ${data.totals.total}`" />
      <KpiTile title="Nouveaux sur la période" :value="data.totals.new" />
      <KpiTile title="À relancer" :value="data.totals.inactive" :hint="`aucune course depuis ${data.inactive_days} jours`" />
      <KpiTile title="Part des 5 premiers" kind="percent" :value="data.totals.top5_share" hint="Plus c'est haut, plus vous dépendez de peu de clients" />
      <KpiTile title="📈 Qui montent" :value="data.totals.growing" hint="+20 % de courses ou plus" />
      <KpiTile title="📉 Qui baissent" :value="data.totals.declining" hint="−20 % de courses ou plus" />
      <KpiTile v-if="data.totals.losing !== null" title="Font perdre de l'argent" :value="data.totals.losing" hint="Rentabilité négative sur la période" />
    </div>

    <!-- Qui montent, qui baissent -->
    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card space-y-2 p-4">
        <h2 class="font-semibold">📈 Qui montent</h2>
        <ul v-if="growing.length" class="divide-y text-sm">
          <li v-for="m in growing" :key="m.id" class="flex justify-between py-1.5"><span>{{ m.name }}</span><b class="text-emerald-700">{{ m.evolution === null ? 'nouveau' : `+${m.evolution} %` }}</b></li>
        </ul>
        <p v-else class="text-sm text-slate-600">Personne ne monte fortement sur la période.</p>
      </section>
      <section class="card space-y-2 p-4">
        <h2 class="font-semibold">📉 Qui baissent <span class="text-sm font-normal text-slate-600">· à appeler</span></h2>
        <ul v-if="declining.length" class="divide-y text-sm">
          <li v-for="m in declining" :key="m.id" class="flex items-center justify-between gap-2 py-1.5">
            <span>{{ m.name }} <b class="text-red-600">{{ m.evolution }} %</b></span>
            <a v-if="phoneOf(m)" :href="whatsappLink(phoneOf(m))" target="_blank" rel="noopener" class="whitespace-nowrap rounded-lg bg-emerald-50 px-3 py-1 text-emerald-800 hover:bg-emerald-100">💬 Écrire</a>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-600">Personne ne baisse fortement. 👏</p>
      </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Top e-commerçants <span class="text-sm font-normal text-slate-600">· courses créées</span></h2>
        <BarList :items="top" empty="Aucune course sur la période." />
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">À relancer <span class="text-sm font-normal text-slate-600">· plus de course depuis {{ data.inactive_days }} jours</span></h2>
        <ul v-if="data.inactive.length" class="max-h-72 divide-y overflow-y-auto text-sm">
          <li v-for="m in data.inactive" :key="m.id" class="flex items-center justify-between gap-2 py-2">
            <div>
              <p class="font-medium">{{ m.name }}</p>
              <p class="text-xs text-slate-600">{{ m.contact_name }} · dernière course il y a {{ m.days }} jours</p>
            </div>
            <a v-if="m.phone" :href="whatsappLink(m.phone)" target="_blank" rel="noopener" class="whitespace-nowrap rounded-lg bg-emerald-50 px-3 py-1.5 text-emerald-800 hover:bg-emerald-100">💬 Écrire</a>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-600">Tous vos e-commerçants commandent régulièrement. 👏</p>
      </section>
    </div>

    <SortableTable :exportable="exportable" title="Tous les e-commerçants actifs" :columns="columns" :rows="data.merchants" initial-sort="created" :filename="`e-commercants_${period}.csv`">
      <template #evolution="{ row }">
        <span v-if="row.trend === 'new'" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800">nouveau</span>
        <span v-else-if="row.evolution !== null" :class="row.trend === 'up' ? 'font-semibold text-emerald-700' : row.trend === 'down' ? 'font-semibold text-red-600' : 'text-slate-600'">{{ row.evolution > 0 ? '+' : '' }}{{ row.evolution }} %</span>
        <span v-else>—</span>
      </template>
      <template #margin="{ row }"><span :class="row.margin < 0 ? 'font-semibold text-red-600' : 'text-emerald-700'">{{ money(row.margin) }}</span></template>
      <template #success_rate="{ row }">
        <span v-if="row.success_rate !== null" :class="row.success_rate >= 90 ? 'text-emerald-700' : row.success_rate < 75 ? 'text-red-600' : ''">{{ row.success_rate }} %</span>
        <span v-else>—</span>
      </template>
    </SortableTable>
    <p class="text-xs text-slate-600">Évolution : courses créées face à la période précédente. Réussite : livrées parmi les courses terminées (livrées, retournées, perdues). Rentabilité : frais de livraison moins la paie des livreurs et les frais pris en charge par l'agence (la paie fixe est répartie selon les courses livrées) ; en rouge, l'agence perd de l'argent. À reverser : solde actuel, tous mois confondus.</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { money, whatsappLink } from '../../utils/format'
import BarList from './BarList.vue'
import KpiTile from './KpiTile.vue'
import SortableTable from './SortableTable.vue'

const props = defineProps({
  data: { type: Object, required: true },
  withAmounts: { type: Boolean, default: false },
  exportable: { type: Boolean, default: true },
})

const period = computed(() => `${props.data.period.from}_${props.data.period.to}`)
const top = computed(() => [...props.data.merchants].filter((m) => m.created).sort((a, b) => b.created - a.created).slice(0, 8)
  .map((m) => ({ label: m.name, count: m.created })))

const growing = computed(() => props.data.merchants.filter((m) => m.trend === 'up' || (m.trend === 'new' && m.created >= 5))
  .sort((a, b) => (b.evolution ?? 1000) - (a.evolution ?? 1000)).slice(0, 6))
const declining = computed(() => props.data.merchants.filter((m) => m.trend === 'down').sort((a, b) => a.evolution - b.evolution).slice(0, 6))
const phoneOf = (m) => props.data.inactive.find((x) => x.id === m.id)?.phone || m.phone

const columns = computed(() => [
  { key: 'name', label: 'E-commerçant', asc: true, class: () => 'font-medium' },
  { key: 'created', label: 'Créées', align: 'right' },
  { key: 'evolution', label: 'Évolution', align: 'right' },
  { key: 'delivered', label: 'Livrées', align: 'right' },
  { key: 'returned', label: 'Retournées', align: 'right' },
  { key: 'cancelled', label: 'Annulées', align: 'right' },
  { key: 'success_rate', label: 'Réussite', align: 'right' },
  ...(props.withAmounts
    ? [
        { key: 'revenue', label: 'Chiffre d\'affaires', align: 'right', format: money },
        { key: 'margin', label: 'Rentabilité', align: 'right', format: money },
        { key: 'margin_per_order', label: 'Par course', align: 'right', format: (v) => (v === null ? '—' : money(v)), class: (r) => (r.margin_per_order < 0 ? 'text-red-600' : '') },
        { key: 'collected', label: 'Encaissé', align: 'right', format: money },
        { key: 'to_pay', label: 'À reverser', align: 'right', format: money, class: (r) => (r.to_pay < 0 ? 'text-red-600' : '') },
      ]
    : []),
])
</script>
