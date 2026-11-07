<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">Outil live</h1>
      <p class="text-sm text-gray-500">L'outil « Préparer mon live » des e-commerçants : prix, fiches produit par l'IA, règles du live, et ce que ça rapporte ce mois-ci.</p>
    </div>
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <template v-if="data">
      <!-- Suivi du mois -->
      <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div v-for="k in kpis" :key="k.label" class="card p-4">
          <p class="text-sm text-gray-500">{{ k.label }}</p>
          <p :class="['text-xl font-bold', k.cls || '']">{{ k.value }}</p>
          <p v-if="k.hint" class="text-xs text-gray-500">{{ k.hint }}</p>
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
        <p class="text-sm text-gray-600">Ce mois-ci : <b>{{ money(pay.month.paid) }}</b> reçus ({{ pay.month.count }} paiement(s)). La part de chaque agence va sur son crédit, déduit de ses factures.</p>

        <div v-if="pay.credits.length" class="overflow-x-auto">
          <p class="mb-1 text-sm font-medium">Crédit des agences</p>
          <table class="w-full text-sm">
            <thead class="text-left text-gray-500"><tr><th class="py-1">Agence</th><th class="py-1 text-right">Gagné</th><th class="py-1 text-right">Solde</th><th /></tr></thead>
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
                  <td class="py-1.5 whitespace-nowrap text-gray-500">{{ new Date(p.date).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) }}</td>
                  <td class="py-1.5">{{ p.merchant }} <span class="text-gray-500">· {{ p.company }}</span></td>
                  <td class="py-1.5">{{ ({ live: 'Live', monthly: 'Forfait', pack: 'Fiches IA' })[p.kind] }}</td>
                  <td class="py-1.5 text-right tabular-nums">{{ money(p.amount) }}</td>
                  <td class="py-1.5 text-right"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', statusClass(p.status)]" :title="p.error || ''">{{ statusLabel(p.status) }}{{ p.error ? ' ⚠️' : '' }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
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
        </section>

        <section class="card space-y-2 p-4">
          <h2 class="font-semibold">Commandes WhatsApp automatiques</h2>
          <p class="text-sm text-gray-600">Par défaut, l'e-commerçant relie son numéro WhatsApp Business par la <b>connexion officielle de WhatsApp</b> (fenêtre Facebook) : il garde son application, sans risque de blocage.</p>
          <label class="flex items-start gap-2 text-sm"><input v-model="form.qr_whatsapp" type="checkbox" class="mt-0.5" :disabled="!isOwner"> <span>Proposer aussi la connexion par <b>code d'association ou QR code</b>, à côté de la connexion officielle<br><span class="text-amber-700">Connexion non officielle : WhatsApp peut suspendre un numéro qui répond automatiquement.</span></span></label>
        </section>

        <section v-for="g in GROUPS" :key="g.title" class="card space-y-3 p-4">
          <h2 class="font-semibold">{{ g.title }}</h2>
          <p v-if="g.help" class="text-sm text-gray-500">{{ g.help }}</p>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label v-for="f in g.fields" :key="f.key" class="block text-sm">
              <span class="label">{{ f.label }}</span>
              <span class="flex items-center gap-2">
                <input v-model.number="form[f.key]" type="number" min="0" class="input" :disabled="!isOwner">
                <span v-if="f.unit" class="shrink-0 text-gray-500">{{ f.unit }}</span>
              </span>
            </label>
          </div>
        </section>

        <button v-if="isOwner" class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        <p v-else class="text-sm text-gray-500">Seul le Propriétaire peut changer ces réglages.</p>
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
    title: 'Prix',
    help: 'Payé en ligne par l\'e-commerçant (ou retenu sur son reversement sans PayDunya), ou par l\'agence quand elle offre le live. Au-delà du plafond du mois, les lives sont gratuits jusqu\'à la fin du mois.',
    fields: [
      { key: 'price_live', label: 'Prix d\'un live', unit: 'F' },
      { key: 'monthly_cap', label: 'Plafond par mois et par e-commerçant (0 = sans plafond)', unit: 'F' },
      { key: 'welcome_cards', label: 'Fiches IA offertes au premier live' },
      { key: 'cards_per_live', label: 'Fiches IA comprises dans un live' },
      { key: 'price_month', label: 'Forfait du mois (lives sans limite)', unit: 'F' },
      { key: 'cards_per_month', label: 'Fiches IA du forfait' },
      { key: 'pack_price', label: 'Pack de fiches en plus', unit: 'F' },
      { key: 'pack_cards', label: 'Fiches dans le pack' },
      { key: 'trial_lives', label: 'Lives offerts pour découvrir' },
      { key: 'trial_cards', label: 'Fiches IA du live offert' },
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
