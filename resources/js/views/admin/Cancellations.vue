<template>
  <div class="space-y-4">
    <div v-if="!embedded" class="flex flex-wrap items-center gap-2">
      <div class="flex-1">
        <RouterLink :to="section === 'rules' ? '/admin/reglages' : '/admin'" class="text-sm text-blue-600">‹ {{ section === 'rules' ? 'Réglages' : 'Tableau de bord' }}</RouterLink>
        <h1 class="text-xl font-bold">{{ TITLES[section] }}</h1>
      </div>
      <RouterLink v-if="section === 'requests'" to="/admin/statistiques?onglet=cancellations" class="text-sm text-blue-600">📊 Point des annulations</RouterLink>
      <RouterLink v-if="section === 'requests' && report?.is_admin" to="/admin/reglages/annulations" class="text-sm text-blue-600">⚙️ Règles</RouterLink>
    </div>

    <div v-if="shows('report') && !from" class="flex flex-wrap items-center gap-2 text-sm">
      <button v-for="p in PERIODS" :key="p.key" type="button" :class="['rounded-full px-3 py-1', period.key === p.key ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white']" @click="choose(p.key)">{{ p.label }}</button>
      <template v-if="period.key === 'custom'">
        <input v-model="period.from" type="date" class="input !w-auto" @change="load">
        <span>→</span>
        <input v-model="period.to" type="date" class="input !w-auto" @change="load">
      </template>
    </div>

    <!-- Demandes à valider (au-delà de la limite du jour) -->
    <p v-if="section === 'requests' && report && !report.requests?.length" class="card p-6 text-center text-gray-500">✅ Aucune demande d'annulation à valider.</p>
    <section v-if="shows('requests') && report?.requests?.length" class="card space-y-2 border-amber-300 bg-amber-50 p-4">
      <h2 class="font-semibold text-amber-900">⏳ {{ report.requests.length }} demande{{ report.requests.length > 1 ? 's' : '' }} d'annulation à valider</h2>
      <div v-for="r in report.requests" :key="r.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-amber-200 pt-2 text-sm">
        <RouterLink :to="`/admin/courses/${r.order_id}`" class="font-mono text-emerald-700">{{ r.tracking_code }}</RouterLink>
        <span>{{ r.merchant }}</span>
        <span class="text-gray-700">{{ r.to_status === 'rejected' ? 'Refus' : 'Annulation' }} demandé{{ r.to_status === 'rejected' ? '' : 'e' }} par <b>{{ r.requested_by }}</b>, {{ ago(r.created_at) }}</span>
        <span class="flex-1">{{ r.reason }}<template v-if="r.note"> · « {{ r.note }} »</template></span>
        <span v-if="report.is_admin" class="flex gap-2">
          <button type="button" class="btn-primary" :disabled="busy" @click="decide(r, true)">Valider</button>
          <button type="button" class="btn-secondary" :disabled="busy" @click="decide(r, false)">Refuser</button>
        </span>
        <span v-else class="text-xs text-amber-800">En attente d'un administrateur</span>
      </div>
    </section>

    <!-- Règles (administrateurs) -->
    <p v-if="section === 'rules' && report && !report.is_admin" class="card p-4 text-sm text-gray-600">Seul un administrateur peut changer les règles d'annulation.</p>
    <section v-if="shows('rules') && report?.is_admin && rules" class="card space-y-3 p-4 text-sm">
      <h2 v-if="section !== 'rules'" class="font-semibold">⚙️ Règles d'annulation</h2>
      <p class="text-gray-600">Les administrateurs annulent sans limite. Les autres membres de l'équipe (dispatch) ont une limite par jour : au-delà, un administrateur valide.</p>
      <label class="flex flex-wrap items-center gap-2">
        <input v-model="limits.staff" type="checkbox"> Dispatch : jusqu'à
        <input v-model.number="rules.staff_daily_limit" type="number" min="0" class="input !w-20" :disabled="!limits.staff"> annulations par jour, ensuite validation d'un administrateur
      </label>
      <p class="pt-1 font-medium">Alertes aux administrateurs (cloche et notification en direct)</p>
      <label class="flex flex-wrap items-center gap-2">
        <input v-model="limits.hourly" type="checkbox"> Plus de
        <input v-model.number="rules.hourly_alert" type="number" min="1" class="input !w-20" :disabled="!limits.hourly"> annulations par une même personne en une heure
      </label>
      <label class="flex flex-wrap items-center gap-2">
        <input v-model="limits.merchant" type="checkbox"> E-commerçant à plus de
        <input v-model.number="rules.merchant_rate_alert" type="number" min="1" max="100" class="input !w-20" :disabled="!limits.merchant"> % d'annulations sur 7 jours (vérifié chaque matin, à partir de 10 courses)
      </label>
      <label class="flex items-center gap-2"><input v-model="rules.weekly_report" type="checkbox"> Dans le point de la semaine</label>
      <button type="button" class="btn-primary" :disabled="busy" @click="saveRules">Enregistrer les règles</button>
    </section>

    <template v-if="report && shows('report')">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs text-gray-500">Courses annulées</p><p class="text-2xl font-bold">{{ report.totals.cancelled }}</p><p class="text-xs text-gray-500">sur {{ report.totals.created }} créées<template v-if="report.totals.rate !== null"> · {{ pct(report.totals.rate) }}</template></p></div>
        <div class="card p-4"><p class="text-xs text-gray-500">Par les e-commerçants</p><p class="text-2xl font-bold">{{ report.totals.by_merchants }}</p><p class="text-xs text-gray-500">{{ share(report.totals.by_merchants) }}</p></div>
        <div class="card p-4"><p class="text-xs text-gray-500">Par l'équipe</p><p class="text-2xl font-bold">{{ report.totals.by_company }}</p><p class="text-xs text-gray-500">dont {{ report.totals.rejected }} refusée{{ report.totals.rejected > 1 ? 's' : '' }} à la création</p></div>
        <div :class="['card p-4', report.totals.flagged ? 'border-red-300' : '']"><p :class="['text-xs', report.totals.flagged ? 'text-red-700' : 'text-gray-500']">À surveiller</p><p :class="['text-2xl font-bold', report.totals.flagged ? 'text-red-700' : '']">{{ report.totals.flagged }}</p><p class="text-xs text-gray-500">taux ou volume anormal</p></div>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <section class="card overflow-x-auto p-4">
          <h2 class="mb-2 font-semibold">Par e-commerçant</h2>
          <table class="w-full text-sm">
            <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">E-commerçant</th><th>Annulées</th><th>Taux</th><th>Motif principal</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="m in report.merchants" :key="m.merchant_id" :class="m.flagged ? 'bg-red-50' : ''">
                <td class="py-2">{{ m.flagged ? '🔴 ' : '' }}{{ m.name }}</td>
                <td>{{ m.cancelled }}</td>
                <td :class="m.flagged ? 'font-semibold text-red-700' : ''">{{ pct(m.rate) }}</td>
                <td>{{ m.top_reason }}</td>
              </tr>
              <tr v-if="!report.merchants.length"><td colspan="4" class="py-3 text-center text-gray-500">Aucune annulation par les e-commerçants.</td></tr>
            </tbody>
          </table>
        </section>
        <section class="card overflow-x-auto p-4">
          <h2 class="mb-2 font-semibold">Par membre de l'équipe</h2>
          <table class="w-full text-sm">
            <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Personne</th><th>Annulées</th><th>Refusées</th><th>Motif principal</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="s in report.staff" :key="s.user_id" :class="s.flagged ? 'bg-red-50' : ''">
                <td class="py-2">{{ s.flagged ? '🔴 ' : '' }}{{ s.name }}<span v-if="s.role" class="text-xs text-gray-500"> ({{ s.role }})</span></td>
                <td>{{ s.cancelled }}</td>
                <td>{{ s.rejected }}</td>
                <td>{{ s.top_reason }}</td>
              </tr>
              <tr v-if="!report.staff.length"><td colspan="4" class="py-3 text-center text-gray-500">Aucune annulation par l'équipe.</td></tr>
            </tbody>
          </table>
        </section>
      </div>

      <section class="card p-4">
        <h2 class="mb-2 font-semibold">Motifs</h2>
        <div v-if="report.reasons.length" class="space-y-2 text-sm">
          <div v-for="r in report.reasons" :key="`${r.by}-${r.code}`">
            <div class="flex justify-between gap-2"><span>{{ r.label }} <span class="text-xs text-gray-500">· {{ r.by === 'merchant' ? 'e-commerçants' : 'équipe' }}</span></span><span>{{ r.count }}</span></div>
            <div class="h-2 rounded bg-slate-100"><div class="h-2 rounded bg-slate-700" :style="{ width: `${Math.round(r.count * 100 / maxReason)}%` }" /></div>
          </div>
        </div>
        <p v-else class="text-sm text-gray-500">Aucune annulation sur la période.</p>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { shift } from '../../utils/periods'

/**
 * Annulations, en trois parties montrées chacune à sa place : demandes à valider (depuis le tableau de bord),
 * règles (Réglages) et point par e-commerçant, par personne et par motif (Statistiques).
 */
const props = defineProps({
  section: { type: String, default: 'all' },
  embedded: Boolean,
  // Période imposée (onglet des statistiques)
  from: { type: String, default: null },
  to: { type: String, default: null },
})
const TITLES = { all: 'Annulations', requests: 'Demandes d\'annulation', rules: 'Règles d\'annulation', report: 'Point des annulations' }
const shows = (part) => props.section === 'all' || props.section === part
const toasts = useToastStore()
const PERIODS = [
  { key: 'today', label: 'Aujourd\'hui', from: () => shift(0) },
  { key: 'week', label: '7 derniers jours', from: () => shift(-6) },
  { key: 'month', label: '30 derniers jours', from: () => shift(-29) },
  { key: 'custom', label: 'Dates…', from: () => shift(-6) },
]
const period = reactive({ key: 'week', from: props.from || shift(-6), to: props.to || shift(0) })
watch(() => [props.from, props.to], ([f, t]) => {
  if (!f || !t) return
  Object.assign(period, { from: f, to: t })
  load()
})
const report = ref(null)
const rules = ref(null)
const limits = reactive({ staff: true, hourly: true, merchant: true })
const busy = ref(false)

const pct = (v) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')} %`)
const share = (n) => (report.value?.totals.cancelled ? pct(Math.round(n * 1000 / report.value.totals.cancelled) / 10) : '—')
const maxReason = computed(() => Math.max(1, ...(report.value?.reasons || []).map((r) => r.count)))

function ago(iso) {
  const min = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000))
  return min < 60 ? `il y a ${min} min` : min < 1440 ? `il y a ${Math.round(min / 60)} h` : `le ${new Date(iso).toLocaleDateString('fr-FR')}`
}

function choose(key) {
  const p = PERIODS.find((x) => x.key === key)
  Object.assign(period, { key, from: p.from(), to: shift(0) })
  load()
}

function applyRules(r) {
  rules.value = { staff_daily_limit: r.staff_daily_limit ?? 5, hourly_alert: r.hourly_alert ?? 10, merchant_rate_alert: r.merchant_rate_alert ?? 15, weekly_report: !!r.weekly_report }
  Object.assign(limits, { staff: r.staff_daily_limit !== null, hourly: r.hourly_alert !== null, merchant: r.merchant_rate_alert !== null })
}

async function load() {
  try {
    const { data } = await http.get('/cancellations', { params: { from: period.from, to: period.to } })
    report.value = data.data
    if (!rules.value) applyRules(data.data.rules)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function decide(r, approve) {
  let note = null
  if (!approve) {
    note = window.prompt('Pourquoi refusez-vous ? (facultatif, la personne le verra)')
    if (note === null) return
  } else if (!window.confirm(`Valider l'annulation de ${r.tracking_code} ?`)) return
  busy.value = true
  try {
    const { data } = await http.post(`/cancellation-requests/${r.id}/decision`, { approve, note: note || undefined })
    toasts.success(data.message)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
  load()
}

async function saveRules() {
  busy.value = true
  try {
    const { data } = await http.put('/cancellations/rules', {
      staff_daily_limit: limits.staff ? rules.value.staff_daily_limit : null,
      hourly_alert: limits.hourly ? rules.value.hourly_alert : null,
      merchant_rate_alert: limits.merchant ? rules.value.merchant_rate_alert : null,
      weekly_report: rules.value.weekly_report,
    })
    applyRules(data.data)
    toasts.success('Règles enregistrées.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
  load()
}

onMounted(load)
</script>
