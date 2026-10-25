<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Tableau de bord de la plateforme</h1>
        <p class="text-sm text-gray-600">{{ PERIODS.find((p) => p.value === period)?.hint }} · mis à jour {{ updatedAt ? dateTime(updatedAt) : '…' }}</p>
      </div>
      <div class="flex gap-1 text-sm">
        <button
          v-for="p in PERIODS" :key="p.value" type="button"
          :class="['rounded-lg px-3 py-1.5', period === p.value ? 'bg-slate-900 text-white' : 'bg-white ring-1 ring-slate-200 hover:bg-slate-50']"
          @click="period = p.value; load()"
        >{{ p.label }}</button>
      </div>
    </div>

    <p v-if="loading && !data" class="text-gray-500">Chargement…</p>

    <template v-if="data">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4">
          <p class="text-sm text-gray-500">Revenu facturé</p>
          <p class="text-2xl font-bold">{{ money(data.kpis.revenue.value) }}</p>
          <p class="text-xs"><Change :value="data.kpis.revenue.change" /> · encaissé {{ money(data.kpis.collected) }}</p>
        </div>
        <div class="card p-4">
          <p class="text-sm text-gray-500">Reste à encaisser</p>
          <p :class="['text-2xl font-bold', data.kpis.receivable.late ? 'text-amber-700' : '']">{{ money(data.kpis.receivable.value) }}</p>
          <p class="text-xs text-gray-500">{{ data.kpis.receivable.invoices }} facture(s) · {{ data.kpis.receivable.late }} en retard</p>
        </div>
        <div class="card p-4">
          <p class="text-sm text-gray-500">Courses</p>
          <p class="text-2xl font-bold">{{ data.kpis.orders.value.toLocaleString('fr-FR') }}</p>
          <p class="text-xs"><Change :value="data.kpis.orders.change" /> · {{ data.kpis.orders.today }} aujourd'hui</p>
        </div>
        <div class="card p-4">
          <p class="text-sm text-gray-500">Entreprises actives</p>
          <p class="text-2xl font-bold">{{ data.kpis.companies.active }}</p>
          <p class="text-xs text-gray-500">+{{ data.kpis.companies.new }} sur la période · {{ data.kpis.companies.on_trial }} en essai</p>
        </div>
      </div>

      <div class="grid gap-3 lg:grid-cols-3">
        <div class="card p-4 lg:col-span-2">
          <p class="mb-3 font-semibold">Courses {{ period === 'year' ? 'par mois' : 'par jour' }} (toute la plateforme)</p>
          <div class="flex h-40 items-end gap-1">
            <div
              v-for="point in data.series" :key="point.date" class="group relative flex-1 rounded-t bg-sky-500 hover:bg-sky-600"
              :style="{ height: `${Math.max(2, (point.orders / maxOrders) * 100)}%` }" :title="`${label(point.date)} : ${point.orders} course(s)`"
            ></div>
          </div>
          <div v-if="data.series.length" class="mt-1 flex justify-between text-xs text-gray-400">
            <span>{{ label(data.series[0].date) }}</span><span>{{ label(data.series.at(-1).date) }}</span>
          </div>
        </div>

        <div class="card space-y-2 p-4">
          <p class="font-semibold">⚠️ À surveiller</p>
          <p v-if="!data.watch.dropping.length && !data.watch.trials_ending.length && !data.watch.late_invoices.count" class="text-sm text-emerald-700">✓ Rien à signaler.</p>
          <div v-if="data.watch.dropping.length" class="rounded-lg bg-red-50 p-2 text-sm text-red-800">
            <b>{{ data.watch.dropping.length }} entreprise(s) décrochent</b> : aucune course depuis 7 jours
            <p class="text-xs"><template v-for="(c, i) in data.watch.dropping" :key="c.id"><RouterLink :to="`/console/entreprises/${c.id}`" class="underline">{{ c.name }}</RouterLink>{{ i < data.watch.dropping.length - 1 ? ' · ' : '' }}</template></p>
          </div>
          <div v-if="data.watch.trials_ending.length" class="rounded-lg bg-amber-50 p-2 text-sm text-amber-900">
            <b>{{ data.watch.trials_ending.length }} fin(s) d'essai cette semaine</b>
            <p class="text-xs"><template v-for="(c, i) in data.watch.trials_ending" :key="c.id"><RouterLink :to="`/console/entreprises/${c.id}`" class="underline">{{ c.name }}</RouterLink> ({{ date(c.trial_ends_at) }}){{ i < data.watch.trials_ending.length - 1 ? ' · ' : '' }}</template></p>
          </div>
          <component
            :is="canBill ? 'RouterLink' : 'div'" v-if="data.watch.late_invoices.count" to="/console/facturation"
            class="block rounded-lg bg-amber-50 p-2 text-sm text-amber-900"
          ><b>{{ data.watch.late_invoices.count }} facture(s) en retard</b> : {{ money(data.watch.late_invoices.amount) }}</component>
        </div>
      </div>

      <div class="card overflow-x-auto p-4">
        <p class="mb-2 font-semibold">Entreprises les plus actives</p>
        <p v-if="!data.top.length" class="text-sm text-gray-500">Aucune course sur la période.</p>
        <table v-else class="w-full min-w-[36rem] text-sm">
          <thead class="text-left text-gray-500"><tr><th class="py-1">Entreprise</th><th>Courses</th><th>Évolution</th><th>Livreurs actifs</th><th>Formule</th><th>Facture</th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="c in data.top" :key="c.id">
              <td class="py-2 font-medium"><RouterLink :to="`/console/entreprises/${c.id}`" class="hover:underline">{{ c.name }}</RouterLink></td>
              <td>{{ c.orders.toLocaleString('fr-FR') }}</td>
              <td><Change :value="c.change" /></td>
              <td>{{ c.couriers }}</td>
              <td>{{ c.plan || '—' }}</td>
              <td :class="INVOICE[c.invoice]?.cls">{{ INVOICE[c.invoice]?.label ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, h, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { CONSOLE_ROLES } from '../../roles'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money } from '../../utils/format'

const PERIODS = [
  { value: 'month', label: 'Ce mois', hint: 'Depuis le début du mois' },
  { value: '30d', label: '30 jours', hint: 'Les 30 derniers jours' },
  { value: 'year', label: 'Année', hint: 'Depuis le début de l\'année' },
]
const INVOICE = {
  paid: { label: 'Payée', cls: 'text-emerald-700' },
  due: { label: 'À payer', cls: 'text-amber-700' },
  late: { label: 'En retard', cls: 'text-red-600' },
}

// Évolution par rapport à la période précédente de même durée
const Change = (props) => props.value === null || props.value === undefined
  ? h('span', { class: 'text-gray-400' }, '—')
  : h('span', { class: props.value >= 0 ? 'text-emerald-700' : 'text-red-600' }, `${props.value >= 0 ? '▲ +' : '▼ '}${props.value} %`)
Change.props = ['value']

const auth = useAuthStore()
const toasts = useToastStore()
const period = ref('month')
const data = ref(null)
const loading = ref(false)
const updatedAt = ref(null)
const canBill = computed(() => CONSOLE_ROLES.accounting.includes(auth.role))
const maxOrders = computed(() => Math.max(1, ...(data.value?.series ?? []).map((p) => p.orders)))

function label(value) {
  if (/^\d{4}-\d{2}$/.test(value)) return new Date(`${value}-01`).toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' })
  return new Date(value).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}

async function load() {
  loading.value = true
  try {
    data.value = (await http.get('/console/dashboard', { params: { period: period.value } })).data.data
    updatedAt.value = new Date().toISOString()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
