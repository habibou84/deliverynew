<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">Outil live</h1>
      <p class="text-sm text-slate-600">L'outil « Préparer mon live » des e-commerçants : prix, fiches produit par l'IA, règles du live, et ce que ça rapporte ce mois-ci.</p>
    </div>
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <template v-if="data">
      <!-- Suivi du mois -->
      <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div v-for="k in kpis" :key="k.label" class="card p-4">
          <p class="text-sm text-slate-600">{{ k.label }}</p>
          <p :class="['text-xl font-bold', k.cls || '']">{{ k.value }}</p>
          <p v-if="k.hint" class="text-xs text-slate-600">{{ k.hint }}</p>
        </div>
      </div>

      <!-- Paiements en ligne (PayDunya) et crédit des agences -->
      <section v-if="pay" class="card space-y-3 p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="font-semibold">Paiements en ligne (PayDunya)</h2>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', pay.configured ? (pay.mode === 'live' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800') : 'bg-slate-100 text-slate-600']">
            {{ pay.configured ? (pay.mode === 'live' ? 'Réel' : 'Mode test') : 'Non configuré : frais retenus sur les reversements' }}
          </span>
        </div>
        <p class="text-sm text-slate-600">Ce mois-ci : <b>{{ money(pay.month.paid) }}</b> reçus ({{ pay.month.count }} paiement(s)). La part de chaque agence va sur son crédit, déduit de ses factures.</p>
        <!-- Ventes du mois par type, et lives comptés au palier -->
        <div class="grid gap-2 sm:grid-cols-4">
          <div v-for="k in monthKinds" :key="k.label" class="rounded-lg bg-slate-50 p-3">
            <p class="text-xs text-slate-600">{{ k.label }}</p>
            <p class="font-bold tabular-nums">{{ k.value }}</p>
            <p class="text-xs text-slate-600">{{ k.hint }}</p>
          </div>
        </div>

        <div v-if="pay.credits.length" class="overflow-x-auto">
          <p class="mb-1 text-sm font-medium">Crédit des agences</p>
          <table class="w-full text-sm">
            <thead class="text-left text-slate-600"><tr><th class="py-1">Agence</th><th class="py-1 text-right">Gagné</th><th class="py-1 text-right">Solde</th><th /></tr></thead>
            <tbody>
              <tr v-for="c in pay.credits" :key="c.company_id" class="border-t">
                <td class="py-2">{{ c.company }}</td>
                <td class="py-2 text-right tabular-nums">{{ money(c.earned) }}</td>
                <td class="py-2 text-right font-semibold tabular-nums">{{ money(c.balance) }}</td>
                <td class="py-2 text-right"><button v-if="isOwner && c.balance > 0" type="button" class="text-blue-600" @click="payout(c)">Versé…</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <details v-if="pay.payments.length">
          <summary class="cursor-pointer text-sm font-medium">Derniers paiements ({{ pay.payments.length }})</summary>
          <div class="mt-2 overflow-x-auto">
            <table class="w-full text-sm">
              <tbody>
                <tr v-for="p in pay.payments" :key="p.id" class="border-t">
                  <td class="py-1.5 whitespace-nowrap text-slate-600">{{ new Date(p.date).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) }}</td>
                  <td class="py-1.5">{{ p.merchant }} <span class="text-slate-600">· {{ p.company }}</span></td>
                  <td class="py-1.5">{{ ({ credit: 'Crédit live', live: 'Live', monthly: 'Forfait', pack: 'Fiches IA' })[p.kind] }}</td>
                  <td class="py-1.5 text-right tabular-nums">{{ money(p.amount) }}</td>
                  <td class="py-1.5 text-right"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', statusClass(p.status)]" :title="p.error || ''">{{ statusLabel(p.status) }}{{ p.error ? ' ⚠️' : '' }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </section>

      <!-- Offrir à un e-commerçant : commandes en live, crédit live, 30 jours -->
      <section class="card space-y-3 p-4">
        <h2 class="font-semibold">Offrir à un e-commerçant</h2>
        <p class="text-sm text-slate-600">Des commandes en live (en plus de celles du départ), du crédit live, ou 30 jours de lives sans limite. Rien n'est encaissé ; l'action est notée dans le journal.</p>
        <input v-model="giftSearch" type="search" class="input w-full" placeholder="Nom de la boutique ou numéro" @input="searchMerchants">
        <div v-for="m in giftMerchants" :key="m.id" class="space-y-2 rounded-lg border p-3 text-sm">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <span><b>{{ m.name }}</b> <span class="text-slate-600">· {{ m.company }}</span></span>
            <span class="text-slate-600">{{ m.free_left }} commandes offertes · crédit {{ money(m.credit) }}<template v-if="m.monthly_until"> · forfait jusqu'au {{ new Date(m.monthly_until).toLocaleDateString('fr-FR') }}</template></span>
          </div>
          <div v-if="isOwner" class="flex flex-wrap gap-2">
            <button type="button" class="btn-secondary" @click="gift(m, 'orders')">Offrir des commandes…</button>
            <button type="button" class="btn-secondary" @click="gift(m, 'credit')">Offrir du crédit…</button>
            <button type="button" class="btn-secondary" @click="gift(m, 'month')">Offrir 30 jours</button>
          </div>
        </div>
        <p v-if="giftSearch.trim().length >= 2 && !giftMerchants.length && !giftLoading" class="text-sm text-slate-600">Aucun e-commerçant trouvé.</p>
      </section>

      <section v-if="data.overview.by_company.length" class="card overflow-x-auto p-4">
        <h2 class="mb-2 font-semibold">Lives du mois par entreprise</h2>
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="c in data.overview.by_company" :key="c.id" class="border-t first:border-0">
              <td class="py-2">{{ c.name }}</td>
              <td class="py-2 text-right font-semibold tabular-nums">{{ c.lives }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <form class="space-y-4" @submit.prevent="save">
        <section class="card space-y-3 p-4">
          <h2 class="font-semibold">Activation</h2>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.enabled" type="checkbox" :disabled="!isOwner"> Outil live ouvert aux e-commerçants</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.paid" type="checkbox" :disabled="!isOwner"> Lives payants (décoché : tous les lives sont gratuits, rien n'est compté)</label>
        </section>

        <section class="card space-y-2 p-4">
          <h2 class="font-semibold">Commandes WhatsApp automatiques</h2>
          <p class="text-sm text-slate-600">Par défaut, l'e-commerçant relie son numéro WhatsApp Business par la <b>connexion officielle de WhatsApp</b> (fenêtre Facebook) : il garde son application, sans risque de blocage.</p>
          <label class="flex items-start gap-2 text-sm"><input v-model="form.qr_whatsapp" type="checkbox" class="mt-0.5" :disabled="!isOwner"> <span>Proposer aussi la connexion par <b>code d'association ou QR code</b>, à côté de la connexion officielle<br><span class="text-amber-700">Connexion non officielle : WhatsApp peut suspendre un numéro qui répond automatiquement.</span></span></label>
        </section>

        <section v-for="g in GROUPS" :key="g.title" class="card space-y-3 p-4">
          <h2 class="font-semibold">{{ g.title }}</h2>
          <p v-if="g.help" class="text-sm text-slate-600">{{ g.help }}</p>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label v-for="f in g.fields" :key="f.key" class="block text-sm">
              <span class="label">{{ f.label }}</span>
              <span class="flex items-center gap-2">
                <input v-model.number="form[f.key]" type="number" min="0" class="input" :disabled="!isOwner">
                <span v-if="f.unit" class="shrink-0 text-slate-600">{{ f.unit }}</span>
              </span>
            </label>
          </div>
        </section>

        <button v-if="isOwner" class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        <p v-else class="text-sm text-slate-600">Seul le Propriétaire peut changer ces réglages.</p>
      </form>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { consoleCan } from '../../roles'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'
import { promptDialog } from '../../composables/useDialog'

/** Console : réglages et suivi de l'outil live */
const GROUPS = [
  {
    title: 'Prix selon les commandes du live',
    help: 'Compté à la fin du live : toutes les commandes créées pendant le live, même annulées ensuite. Le prix est pris sur le crédit live de l\'e-commerçant (rechargé par PayDunya ; retenu sur son reversement sans PayDunya), ou payé par l\'agence quand elle offre les lives.',
    fields: [
      { key: 'free_upto', label: 'Gratuit jusqu\'à (commandes)' },
      { key: 'tier2_upto', label: 'Palier 2 : jusqu\'à (commandes)' },
      { key: 'tier2_price', label: 'Prix du palier 2', unit: 'F' },
      { key: 'tier3_upto', label: 'Palier 3 : jusqu\'à (commandes)' },
      { key: 'tier3_price', label: 'Prix du palier 3', unit: 'F' },
      { key: 'top_price', label: 'Prix au-delà du palier 3', unit: 'F' },
      { key: 'free_orders', label: 'Commandes en live offertes au départ' },
      { key: 'min_credit', label: 'Crédit minimum pour démarrer un live', unit: 'F' },
    ],
  },
  {
    title: 'Forfait, fiches IA et agence',
    help: 'Le forfait du mois couvre 30 jours de lives sans compter les commandes.',
    fields: [
      { key: 'welcome_cards', label: 'Fiches IA offertes au premier live' },
      { key: 'cards_per_live', label: 'Fiches IA comprises dans un live' },
      { key: 'price_month', label: 'Forfait du mois (lives sans limite pendant 30 jours)', unit: 'F' },
      { key: 'cards_per_month', label: 'Fiches IA du forfait' },
      { key: 'pack_price', label: 'Pack de fiches en plus', unit: 'F' },
      { key: 'pack_cards', label: 'Fiches dans le pack' },
      { key: 'agency_share', label: 'Part laissée à l\'agence', unit: '%' },
    ],
  },
  {
    title: 'IA de la fiche produit',
    help: 'Le seul usage de l\'IA : la photo donne le nom et la description du produit.',
    fields: [
      { key: 'retakes', label: 'Reprises de photo gratuites' },
      { key: 'cards_per_day', label: 'Fiches par e-commerçant et par jour' },
      { key: 'monthly_ai_cap', label: 'Plafond de dépense IA du mois', unit: 'F' },
    ],
  },
  {
    title: 'Règles du live',
    fields: [
      { key: 'items_per_card', label: 'Produits par carte (9 au plus)' },
      { key: 'low_stock', label: 'Conseil « il en reste » à partir de' },
      { key: 'idle_minutes', label: 'Conseil « rien depuis »', unit: 'min' },
      { key: 'risk_refusals', label: 'Alerte client à partir de', unit: 'refus' },
      { key: 'min_sales_range', label: 'Ventes minimum pour la fourchette de prix' },
    ],
  },
]

const auth = useAuthStore()
const toasts = useToastStore()
const data = ref(null)
const error = ref('')
const saving = ref(false)
const form = reactive({})
const isOwner = computed(() => consoleCan(auth.role, 'owner'))

const kpis = computed(() => {
  const o = data.value.overview
  return [
    { label: 'Lives du mois', value: o.lives, hint: `${o.merchants} e-commerçant(s)` },
    { label: 'Commandes des lives', value: o.orders },
    { label: 'Payé par les e-commerçants', value: money(o.paid_by_merchants) },
    { label: 'Part de Jibiat', value: money(o.platform_amount), cls: 'text-emerald-700' },
    { label: 'Fiches IA', value: o.cards, hint: o.card_cost_avg !== null ? `${money(o.card_cost_avg)} par fiche en moyenne` : '' },
    { label: 'Coût de l\'IA', value: money(o.ai_cost), cls: o.ai_cost > o.platform_amount ? 'text-red-700' : '' },
  ]
})

// Paiements PayDunya et crédit des agences
const pay = ref(null)
const statusLabel = (s) => ({ pending: 'En attente', paid: 'Payé', cancelled: 'Annulé', failed: 'Échoué' })[s] || s
const statusClass = (s) => ({ paid: 'bg-emerald-100 text-emerald-800', pending: 'bg-amber-100 text-amber-800' })[s] || 'bg-slate-100 text-slate-600'

// Ventes du mois par type
const monthKinds = computed(() => {
  const m = pay.value?.month
  if (!m) return []
  const k = (kind) => m.by_kind?.[kind] || { count: 0, total: 0 }
  const b = m.billed || { lives: 0, orders: 0, offered: 0, amount: 0, by_agency: 0 }
  return [
    { label: 'Recharges du crédit live', value: money(k('credit').total), hint: `${k('credit').count} recharge(s)` },
    { label: 'Forfaits du mois', value: money(k('monthly').total), hint: `${k('monthly').count} forfait(s)` },
    { label: 'Packs de fiches IA', value: money(k('pack').total), hint: `${k('pack').count} pack(s)` },
    { label: 'Lives comptés au palier', value: money(b.amount), hint: `${b.lives} live(s) · ${b.orders} commandes dont ${b.offered} offertes${b.by_agency ? ` · ${b.by_agency} payé(s) par l'agence` : ''}` },
  ]
})

// Offrir à un e-commerçant
const giftSearch = ref('')
const giftMerchants = ref([])
const giftLoading = ref(false)
let giftTimer = null
function searchMerchants() {
  clearTimeout(giftTimer)
  giftTimer = setTimeout(async () => {
    const q = giftSearch.value.trim()
    if (q.length < 2) {
      giftMerchants.value = []
      return
    }
    giftLoading.value = true
    try {
      giftMerchants.value = (await http.get('/console/live/merchants', { params: { search: q } })).data.data
    } catch (e) {
      toasts.error(apiErrorMessage(e))
    } finally {
      giftLoading.value = false
    }
  }, 300)
}
async function gift(m, kind) {
  let body = { month: true }
  if (kind !== 'month') {
    const answer = await promptDialog(kind === 'orders' ? `Nombre de commandes en live à offrir à ${m.name} :` : `Crédit live à offrir à ${m.name} (F) :`, kind === 'orders' ? '50' : '2000')
    if (!answer) return
    body = { [kind]: Number(answer.replace(/\D/g, '')) }
  }
  try {
    const updated = (await http.post(`/console/live/merchants/${m.id}/gift`, body)).data.data
    giftMerchants.value = giftMerchants.value.map((x) => (x.id === m.id ? updated : x))
    toasts.success('Offert à ' + m.name + '.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function payout(c) {
  const answer = await promptDialog(`Montant versé à ${c.company} (solde : ${money(c.balance)}) :`, String(c.balance))
  if (!answer) return
  try {
    pay.value = (await http.post(`/console/live/credits/${c.company_id}/payout`, { amount: Number(answer.replace(/\D/g, '')) })).data.data
    toasts.success('Versement enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function load() {
  try {
    data.value = (await http.get('/console/live')).data.data
    pay.value = (await http.get('/console/live/payments')).data.data
    Object.assign(form, data.value.settings)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save() {
  saving.value = true
  try {
    data.value = (await http.put('/console/live', { ...form })).data.data
    Object.assign(form, data.value.settings)
    toasts.success('Réglages enregistrés.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
