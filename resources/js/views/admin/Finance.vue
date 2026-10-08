<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Caisse</h1>

    <div class="flex gap-2 overflow-x-auto">
      <button
        v-for="t in tabs"
        :key="t.value"
        :class="['btn whitespace-nowrap', tab === t.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']"
        @click="setTab(t.value)"
      >
        {{ t.label }}
      </button>
    </div>

    <!-- Argent chez les livreurs -->
    <template v-if="tab === 'cash'">
      <div v-if="cash" class="grid sm:grid-cols-3 gap-3">
        <StatCard title="Argent chez les livreurs" :value="money(cash.totals.cash_in_hands)" icon="🛵" />
        <StatCard title="Encaissé aujourd'hui" :value="money(cash.totals.collected_today)" icon="💵" />
        <StatCard title="Versé à la caisse aujourd'hui" :value="money(cash.totals.received_today)" icon="🏦" />
      </div>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Livreur</th><th class="p-2 text-right">En main</th><th class="p-2">Encaissements</th><th class="p-2">Plus ancien</th><th class="p-2 text-right">Gains non payés</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="c in cash?.data || []" :key="c.courier_id">
              <td class="p-2 font-medium">{{ c.name }}<div class="text-xs text-gray-500">{{ c.phone }}</div></td>
              <td class="p-2 text-right font-semibold">{{ money(c.cash_in_hand) }}</td>
              <td class="p-2">{{ c.pending_collections }}</td>
              <td class="p-2 text-xs">{{ c.oldest_collected_at ? dateTime(c.oldest_collected_at) : '—' }}</td>
              <td :class="['p-2 text-right', signedClass(c.unpaid)]">{{ money(c.unpaid) }}</td>
              <td class="p-2 text-right">
                <button v-if="c.cash_in_hand > 0 && canManage" class="btn-primary" @click="openRemit(c)">Recevoir le versement</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h2 class="font-semibold p-4 border-b">Derniers versements</h2>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Date</th><th class="p-2">Livreur</th><th class="p-2 text-right">Attendu</th><th class="p-2 text-right">Reçu</th><th class="p-2 text-right">Écart</th><th class="p-2">Reçu par</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="r in remittances" :key="r.id">
              <td class="p-2 text-xs">{{ dateTime(r.received_at) }}</td>
              <td class="p-2">{{ r.courier_name }}</td>
              <td class="p-2 text-right">{{ money(r.amount_expected) }}</td>
              <td class="p-2 text-right">{{ money(r.amount_received) }}</td>
              <td :class="['p-2 text-right font-medium', signedClass(r.difference)]">{{ r.difference ? money(r.difference) : '—' }}</td>
              <td class="p-2 text-xs">{{ r.received_by }}<span v-if="r.notes" class="block text-gray-500">{{ r.notes }}</span></td>
            </tr>
            <tr v-if="!remittances.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucun versement.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Soldes des marchands -->
    <template v-if="tab === 'merchants'">
      <p class="text-sm text-gray-600">
        « Disponible » = argent arrivé en caisse, prêt à être reversé. « En attente » = courses livrées dont l'argent est encore chez un livreur.
      </p>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">E-commerçant</th><th class="p-2 text-right">Disponible</th><th class="p-2 text-right">En attente</th><th class="p-2 text-right">Total non reversé</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="m in merchantBalances" :key="m.merchant_id">
              <td class="p-2 font-medium">{{ m.business_name }}</td>
              <td :class="['p-2 text-right font-semibold', signedClass(m.available)]">{{ money(m.available) }}</td>
              <td class="p-2 text-right text-gray-600">{{ money(m.pending_cash) }}</td>
              <td class="p-2 text-right">{{ money(m.unpaid_total) }}</td>
              <td class="p-2 text-right whitespace-nowrap">
                <button class="text-blue-600 mr-3" @click="openLedger(m)">Grand livre</button>
                <button v-if="canManage && m.available !== 0" class="btn-primary" @click="preparePayout(m)">Préparer le reversement</button>
              </td>
            </tr>
            <tr v-if="!merchantBalances.length"><td colspan="5" class="p-4 text-center text-gray-500">Aucun montant en attente.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Reversements -->
    <template v-if="tab === 'payouts'">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Référence</th><th class="p-2">E-commerçant</th><th class="p-2">Période</th><th class="p-2 text-right">Net</th><th class="p-2">Statut</th><th class="p-2">Payé le</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in payouts" :key="p.id" class="hover:bg-slate-50 cursor-pointer" @click="router.push(`/admin/caisse/reversements/${p.id}`)">
              <td class="p-2 font-mono text-xs">{{ p.reference }}</td>
              <td class="p-2">{{ p.merchant.business_name }}</td>
              <td class="p-2 text-xs">{{ date(p.period_start) }} → {{ date(p.period_end) }}</td>
              <td :class="['p-2 text-right font-semibold', signedClass(p.net_amount)]">{{ money(p.net_amount) }}</td>
              <td class="p-2"><PayoutBadge :status="p.status" :label="p.status_label" /></td>
              <td class="p-2 text-xs">{{ p.paid_at ? `${dateTime(p.paid_at)} · ${p.method_label}` : '—' }}</td>
            </tr>
            <tr v-if="!payouts.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucun reversement.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Paie des livreurs -->
    <template v-if="tab === 'payroll'">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Livreur</th><th class="p-2 text-right">Gains non payés</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="c in cash?.data || []" :key="c.courier_id">
              <td class="p-2 font-medium">{{ c.name }}</td>
              <td :class="['p-2 text-right font-semibold', signedClass(c.unpaid)]">{{ money(c.unpaid) }}</td>
              <td class="p-2 text-right whitespace-nowrap">
                <button v-if="canManage" class="text-blue-600 mr-3" @click="openAdjust(c)">Prime / retenue</button>
                <button v-if="canManage && c.unpaid !== 0" class="btn-primary" @click="prepareCourierPayout(c)">Préparer la paie</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card overflow-x-auto">
        <h2 class="font-semibold p-4 border-b">Fiches de paie</h2>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Référence</th><th class="p-2">Livreur</th><th class="p-2 text-right">Montant</th><th class="p-2">Statut</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in courierPayouts" :key="p.id">
              <td class="p-2 font-mono text-xs">{{ p.reference }}</td>
              <td class="p-2">{{ p.courier_name }}</td>
              <td :class="['p-2 text-right font-semibold', signedClass(p.amount)]">{{ money(p.amount) }}</td>
              <td class="p-2"><PayoutBadge :status="p.status" :label="p.status_label" /></td>
              <td class="p-2 text-right"><button class="text-blue-600" @click="openCourierPayout(p)">Détail</button></td>
            </tr>
            <tr v-if="!courierPayouts.length"><td colspan="5" class="p-4 text-center text-gray-500">Aucune fiche de paie.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Versement d'un livreur -->
    <Modal :open="remit.open" :title="`Versement de ${remit.courier?.name}`" @close="remit.open = false">
      <form class="space-y-3" @submit.prevent="saveRemit">
        <p class="text-sm text-gray-600">Décochez les encaissements que le livreur ne verse pas aujourd'hui.</p>
        <ul class="border rounded divide-y max-h-64 overflow-y-auto text-sm">
          <li v-for="c in remit.collections" :key="c.id" class="flex items-center gap-2 p-2">
            <input v-model="remit.selected" type="checkbox" :value="c.id" :aria-label="c.tracking_code">
            <span class="font-mono text-xs">{{ c.tracking_code }}</span>
            <span class="flex-1 truncate">{{ c.recipient_name }}</span>
            <span class="text-xs text-gray-500">{{ c.method_label }}</span>
            <span class="font-medium">{{ money(c.amount_collected) }}</span>
          </li>
        </ul>
        <p class="text-sm">Montant attendu : <strong>{{ money(remitExpected) }}</strong></p>
        <div>
          <label class="label" for="received">Montant reçu (F) *</label>
          <input id="received" v-model.number="remit.amount_received" type="number" min="0" class="input" required>
          <p v-if="remitDifference" :class="['text-sm mt-1', signedClass(remitDifference)]">
            Écart : {{ money(remitDifference) }}{{ remitDifference < 0 ? ' (sera retenu sur la paie du livreur)' : '' }}
          </p>
        </div>
        <div><label class="label" for="rnotes">Note</label><input id="rnotes" v-model="remit.notes" class="input"></div>
        <p v-if="remit.error" class="field-error">{{ remit.error }}</p>
        <button class="btn-primary w-full" :disabled="!remit.selected.length">Valider le versement</button>
      </form>
    </Modal>

    <!-- Grand livre d'un marchand -->
    <Modal :open="ledger.open" :title="`Grand livre · ${ledger.merchant?.business_name}`" @close="ledger.open = false">
      <LedgerTable :entries="ledger.entries" />
      <form v-if="canManage" class="border-t mt-4 pt-3 space-y-2" @submit.prevent="saveMerchantAdjust">
        <p class="text-sm font-medium">Ajustement (+ en faveur du marchand, − à sa charge)</p>
        <div class="flex gap-2">
          <input v-model.number="ledger.adjust.amount" type="number" class="input w-32" placeholder="Montant" required>
          <input v-model="ledger.adjust.description" class="input" placeholder="Motif" required>
          <button class="btn-secondary">Ajouter</button>
        </div>
        <p v-if="ledger.error" class="field-error">{{ ledger.error }}</p>
      </form>
    </Modal>

    <!-- Prime / retenue livreur -->
    <Modal :open="adjust.open" :title="`Prime ou retenue · ${adjust.courier?.name}`" @close="adjust.open = false">
      <form class="space-y-3" @submit.prevent="saveCourierAdjust">
        <div><label class="label">Montant (F, négatif pour une retenue)</label><input v-model.number="adjust.amount" type="number" class="input" required></div>
        <div><label class="label">Motif</label><input v-model="adjust.description" class="input" required></div>
        <p v-if="adjust.error" class="field-error">{{ adjust.error }}</p>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>

    <!-- Fiche de paie -->
    <Modal :open="courierPayout.open" :title="`Paie ${courierPayout.data?.reference || ''}`" @close="courierPayout.open = false">
      <div v-if="courierPayout.data" class="space-y-3 text-sm">
        <p>{{ courierPayout.data.courier_name }} · {{ date(courierPayout.data.period_start) }} → {{ date(courierPayout.data.period_end) }}</p>
        <ul class="border rounded divide-y max-h-64 overflow-y-auto">
          <li v-for="e in courierPayout.data.earnings" :key="e.id" class="flex justify-between p-2">
            <span>{{ e.type_label }} <span class="text-gray-500">{{ e.tracking_code || e.description }}</span></span>
            <span :class="signedClass(e.amount)">{{ money(e.amount) }}</span>
          </li>
        </ul>
        <p class="text-lg">Total : <strong>{{ money(courierPayout.data.amount) }}</strong></p>
        <PayForm
          v-if="courierPayout.data.status === 'draft' && canManage"
          @pay="(p) => payCourierPayout(p)"
          @cancel="cancelCourierPayout"
        />
        <p v-else>{{ courierPayout.data.status_label }} {{ courierPayout.data.paid_at ? `le ${dateTime(courierPayout.data.paid_at)} (${courierPayout.data.method_label})` : '' }}</p>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import StatCard from '../../components/StatCard.vue'
import LedgerTable from '../../components/LedgerTable.vue'
import PayoutBadge from '../../components/PayoutBadge.vue'
import PayForm from '../../components/PayForm.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money, signedClass } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const route = useRoute()
const canManage = computed(() => auth.can('finance.manage'))

const tabs = [
  { value: 'cash', label: 'Argent chez les livreurs' },
  { value: 'merchants', label: 'Soldes marchands' },
  { value: 'payouts', label: 'Reversements' },
  { value: 'payroll', label: 'Paie des livreurs' },
]
const tab = ref(route.query.tab || 'cash')

const cash = ref(null)
const remittances = ref([])
const merchantBalances = ref([])
const payouts = ref([])
const courierPayouts = ref([])

const remit = reactive({ open: false, courier: null, collections: [], selected: [], amount_received: 0, notes: '', error: '' })
const ledger = reactive({ open: false, merchant: null, entries: [], adjust: { amount: null, description: '' }, error: '' })
const adjust = reactive({ open: false, courier: null, amount: null, description: '', error: '' })
const courierPayout = reactive({ open: false, data: null })

const remitExpected = computed(() => remit.collections.filter((c) => remit.selected.includes(c.id)).reduce((s, c) => s + c.amount_collected, 0))
const remitDifference = computed(() => (remit.amount_received ?? 0) - remitExpected.value)

function setTab(value) {
  tab.value = value
  router.replace({ query: { tab: value } })
  load()
}

async function load() {
  if (tab.value === 'cash' || tab.value === 'payroll') {
    cash.value = (await http.get('/finance/cash')).data
  }
  if (tab.value === 'cash') remittances.value = (await http.get('/finance/remittances')).data.data
  if (tab.value === 'merchants') merchantBalances.value = (await http.get('/finance/merchants')).data.data
  if (tab.value === 'payouts') payouts.value = (await http.get('/finance/payouts')).data.data
  if (tab.value === 'payroll') courierPayouts.value = (await http.get('/finance/courier-payouts')).data.data
}

async function openRemit(courier) {
  const { data } = await http.get(`/finance/couriers/${courier.courier_id}/collections`)
  Object.assign(remit, {
    open: true,
    courier,
    collections: data.data,
    selected: data.data.map((c) => c.id),
    amount_received: courier.cash_in_hand,
    notes: '',
    error: '',
  })
}

async function saveRemit() {
  remit.error = ''
  try {
    const allSelected = remit.selected.length === remit.collections.length
    await http.post('/finance/remittances', {
      courier_id: remit.courier.courier_id,
      amount_received: remit.amount_received,
      collection_ids: allSelected ? undefined : remit.selected,
      notes: remit.notes || undefined,
    })
    remit.open = false
    toasts.success('Versement enregistré.')
    load()
  } catch (e) {
    remit.error = apiErrorMessage(e)
  }
}

async function openLedger(merchant) {
  ledger.merchant = merchant
  ledger.error = ''
  ledger.adjust = { amount: null, description: '' }
  ledger.entries = (await http.get(`/finance/merchants/${merchant.merchant_id}/ledger`)).data.data
  ledger.open = true
}

async function saveMerchantAdjust() {
  ledger.error = ''
  try {
    await http.post(`/finance/merchants/${ledger.merchant.merchant_id}/adjustments`, ledger.adjust)
    await openLedger(ledger.merchant)
    load()
  } catch (e) {
    ledger.error = apiErrorMessage(e)
  }
}

async function preparePayout(merchant) {
  try {
    const { data } = await http.post('/finance/payouts', { merchant_id: merchant.merchant_id })
    router.push(`/admin/caisse/reversements/${data.data.id}`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function openAdjust(courier) {
  Object.assign(adjust, { open: true, courier, amount: null, description: '', error: '' })
}

async function saveCourierAdjust() {
  adjust.error = ''
  try {
    await http.post(`/finance/couriers/${adjust.courier.courier_id}/adjustments`, { amount: adjust.amount, description: adjust.description })
    adjust.open = false
    load()
  } catch (e) {
    adjust.error = apiErrorMessage(e)
  }
}

async function prepareCourierPayout(courier) {
  try {
    const { data } = await http.post('/finance/courier-payouts', { courier_id: courier.courier_id })
    courierPayout.data = data.data
    courierPayout.open = true
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function openCourierPayout(payout) {
  courierPayout.data = (await http.get(`/finance/courier-payouts/${payout.id}`)).data.data
  courierPayout.open = true
}

async function payCourierPayout(payment) {
  try {
    courierPayout.data = (await http.post(`/finance/courier-payouts/${courierPayout.data.id}/pay`, payment)).data.data
    toasts.success('Paie enregistrée.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function cancelCourierPayout() {
  await http.post(`/finance/courier-payouts/${courierPayout.data.id}/cancel`)
  courierPayout.open = false
  load()
}

onMounted(load)
</script>
