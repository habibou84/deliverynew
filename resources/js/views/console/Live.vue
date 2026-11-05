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

/** Console : réglages et suivi de l'outil live */
const GROUPS = [
  {
    title: 'Prix',
    help: 'Payé par l\'e-commerçant (retenu sur son reversement), ou par l\'agence quand elle offre le live. La part de Jibiat va sur la facture de l\'entreprise.',
    fields: [
      { key: 'price_live', label: 'Prix d\'un live', unit: 'F' },
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
      { key: 'risk_refusals', label: 'Alerte cliente à partir de', unit: 'refus' },
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

async function load() {
  try {
    data.value = (await http.get('/console/live')).data.data
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
