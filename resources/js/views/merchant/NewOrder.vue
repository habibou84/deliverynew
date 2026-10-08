<template>
  <!-- Course enregistrée -->
  <div v-if="created" class="text-center space-y-5 pt-6">
    <div class="mx-auto h-20 w-20 rounded-full bg-emerald-100 grid place-items-center text-4xl">✅</div>
    <div>
      <p class="text-2xl font-bold">Course enregistrée</p>
      <p class="text-slate-500">{{ created.from_warehouse ? 'L\'agence prépare votre commande à l\'entrepôt.' : 'Nous allons envoyer un livreur récupérer le colis.' }}</p>
    </div>
    <div class="m-card p-4 space-y-1">
      <p class="text-sm text-slate-500">Code de suivi</p>
      <p class="font-mono text-xl font-bold tracking-wider">{{ created.tracking_code }}</p>
      <p class="text-sm text-slate-500 pt-2">Code de livraison à donner au client</p>
      <p class="font-mono text-2xl font-bold tracking-[0.3em]">{{ created.delivery_code }}</p>
    </div>
    <a :href="shareLink" target="_blank" class="m-btn-success">💬 Envoyer le suivi au client sur WhatsApp</a>
    <div class="grid grid-cols-2 gap-2">
      <RouterLink :to="`/marchand/courses/${created.id}`" class="m-btn-secondary">Voir la course</RouterLink>
      <button class="m-btn-primary" @click="reset">+ Nouvelle</button>
    </div>
  </div>

  <div v-else class="space-y-5">
    <!-- Progression -->
    <div>
      <div class="flex justify-between text-sm mb-2">
        <span class="font-semibold">{{ steps[step].title }}</span>
        <span class="text-slate-500">Étape {{ step + 1 }} / {{ steps.length }}</span>
      </div>
      <div class="h-2 rounded-full bg-slate-200 overflow-hidden">
        <div class="h-full rounded-full transition-all" :style="{ width: `${((step + 1) / steps.length) * 100}%`, backgroundColor: 'var(--app-color)' }" />
      </div>
    </div>

    <div v-if="error" class="rounded-xl bg-red-50 text-red-700 px-4 py-3 text-sm">{{ error }}</div>

    <!-- 1. Destinataire -->
    <section v-show="step === 0" class="space-y-4">
      <div class="relative">
        <label class="m-label" for="phone">Téléphone du client *</label>
        <input id="phone" v-model="form.recipient_phone" class="m-input text-lg" inputmode="tel" autocomplete="off" placeholder="07 00 00 00 00" @input="searchRecipients">
        <div v-if="suggestions.length" class="absolute z-20 mt-1 w-full m-card overflow-hidden">
          <button v-for="r in suggestions" :key="r.id" type="button" class="w-full text-left px-4 py-3 active:bg-slate-50 border-b last:border-0" @click="pickRecipient(r)">
            <span class="font-medium">{{ r.name || 'Client' }}</span> · {{ r.phone }}
            <span v-if="r.failed_count" class="block text-xs text-red-600">{{ r.failed_count }} livraison(s) échouée(s)</span>
          </button>
        </div>
      </div>
      <div>
        <label class="m-label" for="name">Nom du client</label>
        <input id="name" v-model="form.recipient_name" class="m-input" autocomplete="off" placeholder="Ex. : Jean Kouadio">
      </div>
      <div>
        <label class="m-label" for="phone2">Autre numéro (facultatif)</label>
        <input id="phone2" v-model="form.recipient_phone2" class="m-input" inputmode="tel" placeholder="Si le premier ne répond pas">
      </div>
    </section>

    <!-- 2. Adresse -->
    <section v-show="step === 1" class="space-y-4">
      <div>
        <label class="m-label" for="zone-search">Commune ou quartier *</label>
        <input id="zone-search" v-model="zoneSearch" class="m-input" placeholder="Rechercher…" autocomplete="off">
        <div class="grid grid-cols-2 gap-2 mt-2 max-h-64 overflow-y-auto">
          <button
            v-for="z in filteredZones"
            :key="z.id"
            type="button"
            :class="['tap rounded-xl px-3 py-3 text-left text-sm font-medium ring-1', form.delivery_zone_id === z.id ? 'text-white ring-transparent' : 'bg-white ring-slate-200']"
            :style="form.delivery_zone_id === z.id ? { backgroundColor: 'var(--app-color)' } : {}"
            @click="form.delivery_zone_id = z.id"
          >
            <span v-if="z.is_shipping">🚌 </span>{{ z.full_name }}
          </button>
        </div>
        <p v-if="selectedZone?.is_shipping" class="mt-2 rounded-xl bg-indigo-50 text-indigo-900 text-sm p-3">
          🚌 <strong>Expédition</strong> : notre livreur dépose le colis à la gare et paie l'envoi
          <template v-if="selectedZone.shipping_fee_estimate">(environ {{ money(selectedZone.shipping_fee_estimate) }})</template>.
          Ces frais vous sont facturés au réel et déduits de votre point.
        </p>
      </div>
      <div>
        <label class="m-label" for="address">Adresse</label>
        <input id="address" v-model="form.delivery_address" class="m-input" placeholder="Rue, résidence, immeuble…">
      </div>
      <div>
        <label class="m-label" for="landmark">Repère</label>
        <input id="landmark" v-model="form.delivery_landmark" class="m-input" placeholder="Ex. : face à la pharmacie">
      </div>
      <div>
        <p class="m-label">Livraison souhaitée</p>
        <ChoiceChips v-model="when" :options="whenOptions" :columns="3" label="Date de livraison" />
        <input v-if="when === 'other'" v-model="form.delivery_scheduled_date" type="date" :min="today()" class="m-input mt-2" aria-label="Date de livraison">
      </div>
    </section>

    <!-- 3. Colis et paiement -->
    <section v-show="step === 2" class="space-y-4">
      <div v-if="products.length" class="space-y-3">
        <div v-if="stockHubs.length">
          <p class="m-label">D'où part le colis ?</p>
          <ChoiceChips v-model="source" :options="sourceOptions" :columns="1" label="Provenance du colis" />
        </div>
        <div>
          <p class="m-label">Articles de mon stock <span class="font-normal text-slate-400">(facultatif{{ source ? '' : ' : sinon, décrivez le colis plus bas' }})</span></p>
          <div class="m-card divide-y">
            <div v-for="p in sourceProducts" :key="p.id" class="p-3 flex items-center gap-3">
              <div class="min-w-0 flex-1">
                <p class="font-medium truncate">{{ p.name }}</p>
                <p class="text-xs text-slate-500">{{ money(p.price) }} · {{ availableAt(p) }} dispo</p>
              </div>
              <div class="flex items-center gap-2">
                <button type="button" class="tap h-9 w-9 rounded-full bg-slate-100 text-lg font-bold disabled:opacity-40" :disabled="!picked[p.id]" :aria-label="`Retirer un ${p.name}`" @click="pick(p, -1)">−</button>
                <span class="w-6 text-center font-semibold">{{ picked[p.id] || 0 }}</span>
                <button type="button" class="tap h-9 w-9 rounded-full text-white text-lg font-bold disabled:opacity-40" :style="{ backgroundColor: 'var(--app-color)' }" :disabled="(picked[p.id] || 0) >= availableAt(p)" :aria-label="`Ajouter un ${p.name}`" @click="pick(p, 1)">+</button>
              </div>
            </div>
            <p v-if="!sourceProducts.length" class="p-3 text-sm text-slate-500">Aucun produit disponible {{ source ? 'à cet entrepôt' : 'chez vous' }}.</p>
          </div>
        </div>
      </div>

      <div>
        <label class="m-label" for="amount">Montant des articles à encaisser</label>
        <div class="relative">
          <input id="amount" v-model.number="form.items_amount" type="number" min="0" step="50" inputmode="numeric" class="m-input text-2xl font-bold pr-12">
          <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold">F</span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Mettez 0 si le client a déjà payé.</p>
      </div>
      <div>
        <p class="m-label">Qui paie la livraison ?</p>
        <ChoiceChips
          v-model="form.fee_payer"
          :columns="2"
          label="Payeur des frais"
          :options="[
            { value: 'recipient', label: 'Le client', icon: '🧍', description: 'ajoutée au montant' },
            { value: 'merchant', label: 'Moi', icon: '🏪', description: 'déduite de mon paiement' },
          ]"
        />
      </div>
      <div>
        <label class="m-label" for="desc">Que contient le colis ?</label>
        <input id="desc" v-model="form.description" class="m-input" placeholder="Ex. : 2 robes, 1 sac">
      </div>
      <div class="grid grid-cols-2 gap-2">
        <label :class="['tap flex items-center gap-2 rounded-xl px-4 py-3 ring-1', form.is_express ? 'ring-2 ring-[var(--app-color)]' : 'ring-slate-200 bg-white']">
          <input v-model="form.is_express" type="checkbox" class="h-5 w-5 accent-[var(--app-color)]"> ⚡ Express
        </label>
        <label :class="['tap flex items-center gap-2 rounded-xl px-4 py-3 ring-1', form.is_fragile ? 'ring-2 ring-[var(--app-color)]' : 'ring-slate-200 bg-white']">
          <input v-model="form.is_fragile" type="checkbox" class="h-5 w-5 accent-[var(--app-color)]"> 🥚 Fragile
        </label>
      </div>
      <div>
        <label class="m-label" for="note">Message pour le livreur</label>
        <textarea id="note" v-model="form.merchant_note" rows="2" class="m-input" placeholder="Ex. : appeler avant d'arriver" />
      </div>
    </section>

    <!-- 4. Récapitulatif -->
    <section v-show="step === 3" class="space-y-3">
      <div class="m-card divide-y">
        <button type="button" class="w-full text-left p-4 flex justify-between gap-3" @click="step = 0">
          <span><span class="block text-xs text-slate-500">Client</span>{{ form.recipient_name || '—' }} · {{ form.recipient_phone }}</span><span class="text-[var(--app-color)] text-sm">Modifier</span>
        </button>
        <button type="button" class="w-full text-left p-4 flex justify-between gap-3" @click="step = 1">
          <span><span class="block text-xs text-slate-500">Adresse</span>{{ zoneName }}<span v-if="form.delivery_address"> · {{ form.delivery_address }}</span></span><span class="text-[var(--app-color)] text-sm">Modifier</span>
        </button>
        <button type="button" class="w-full text-left p-4 flex justify-between gap-3" @click="step = 2">
          <span>
            <span class="block text-xs text-slate-500">Colis{{ source ? ` · part de ${sourceName}` : '' }}</span>
            <template v-if="pickedItems.length">{{ pickedItems.map((i) => `${i.quantity} × ${i.name}`).join(', ') }}</template>
            <template v-else>{{ form.description || 'Colis' }}</template>
            <span v-if="form.is_express"> · Express</span><span v-if="form.is_fragile"> · Fragile</span>
          </span><span class="text-[var(--app-color)] text-sm">Modifier</span>
        </button>
      </div>

      <div class="m-card p-4 space-y-2">
        <p v-if="quoteError" class="text-red-600 text-sm">{{ quoteError }}</p>
        <template v-else-if="quote">
          <div class="flex justify-between text-sm"><span class="text-slate-500">Articles</span><span>{{ money(form.items_amount || 0) }}</span></div>
          <div class="flex justify-between text-sm">
            <span class="text-slate-500">Livraison ({{ form.fee_payer === 'recipient' ? 'payée par le client' : 'à ma charge' }})</span><span>{{ money(quote.total) }}</span>
          </div>
          <div v-if="quote.is_shipping" class="flex justify-between text-sm">
            <span class="text-slate-500">🚌 Expédition (à votre charge, au réel)</span><span>≈ {{ money(shippingEstimate) }}</span>
          </div>
          <div class="flex justify-between text-lg font-bold pt-2 border-t"><span>Le client paiera</span><span>{{ money(codAmount) }}</span></div>
          <div :class="['flex justify-between text-sm font-medium', merchantNet < 0 ? 'text-slate-700' : 'text-emerald-700']">
            <span>{{ merchantNet < 0 ? 'À votre charge' : 'Vous recevrez' }}</span>
            <span>{{ quote.is_shipping ? '≈ ' : '' }}{{ money(Math.abs(merchantNet)) }}</span>
          </div>
        </template>
        <p v-else class="text-slate-400 text-sm">Calcul du tarif…</p>
      </div>
    </section>

    <!-- Boutons -->
    <div class="fixed bottom-16 inset-x-0 z-20 bg-slate-50/95 backdrop-blur pb-safe">
      <div class="mx-auto max-w-lg px-4 py-3 flex gap-2">
        <button v-if="step > 0" type="button" class="m-btn-secondary w-auto px-6" @click="step--">Retour</button>
        <button v-if="step < steps.length - 1" type="button" class="m-btn-primary" @click="next">Continuer</button>
        <button v-else type="button" class="m-btn-primary" :disabled="saving || !quote" @click="submit">
          {{ saving ? 'Enregistrement…' : 'Confirmer la course' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { useAuthStore } from '../../stores/auth'
import { money, today } from '../../utils/format'

const steps = [
  { title: 'À qui livrer ?' },
  { title: 'Où livrer ?' },
  { title: 'Colis et paiement' },
  { title: 'Vérifiez et confirmez' },
]

const auth = useAuthStore()

const blank = () => ({
  recipient_phone: '',
  recipient_name: '',
  recipient_phone2: '',
  delivery_zone_id: null,
  delivery_address: '',
  delivery_landmark: '',
  delivery_scheduled_date: null,
  items_amount: 0,
  fee_payer: 'recipient',
  description: '',
  is_express: false,
  is_fragile: false,
  merchant_note: '',
})

const form = reactive(blank())
const step = ref(0)
const zones = ref([])
const zoneSearch = ref('')
const suggestions = ref([])
const when = ref('asap')
const quote = ref(null)
const quoteError = ref('')
const error = ref('')
const saving = ref(false)
const created = ref(null)
const products = ref([])
const hubs = ref([])
const source = ref(null) // null = chez moi, sinon identifiant de l'entrepôt
const picked = reactive({})

const whenOptions = [
  { value: 'asap', label: 'Au plus vite' },
  { value: 'tomorrow', label: 'Demain' },
  { value: 'other', label: 'Autre date' },
]

// Entrepôts où le marchand a du stock disponible
const stockHubs = computed(() => hubs.value.filter((h) => products.value.some((p) => p.levels.some((l) => l.hub_id === h.id && l.available > 0))))
const sourceOptions = computed(() => [
  { value: null, label: 'De chez moi', icon: '🏠', description: 'un livreur passe le récupérer' },
  ...stockHubs.value.map((h) => ({ value: h.id, label: h.name, icon: '🏬', description: 'préparé à l\'entrepôt, sans ramassage' })),
])
const sourceName = computed(() => hubs.value.find((h) => h.id === source.value)?.name)
const sourceZoneId = computed(() => hubs.value.find((h) => h.id === source.value)?.zone_id)

function availableAt(product) {
  return product.levels.find((l) => (l.hub_id ?? null) === source.value)?.available ?? 0
}
const sourceProducts = computed(() => products.value.filter((p) => availableAt(p) > 0 || picked[p.id]))
const pickedItems = computed(() => products.value.filter((p) => picked[p.id] > 0).map((p) => ({ product_id: p.id, name: p.name, quantity: picked[p.id], unit_price: p.price })))

function pick(product, delta) {
  picked[product.id] = Math.max(0, Math.min(availableAt(product), (picked[product.id] || 0) + delta))
  // Le montant à encaisser suit les articles choisis (modifiable ensuite)
  form.items_amount = pickedItems.value.reduce((sum, i) => sum + i.quantity * i.unit_price, 0)
}

watch(source, () => {
  Object.keys(picked).forEach((id) => delete picked[id])
  fetchQuote()
})

const filteredZones = computed(() => {
  const term = zoneSearch.value.trim().toLowerCase()
  return zones.value.filter((z) => !term || z.full_name.toLowerCase().includes(term))
})
const selectedZone = computed(() => zones.value.find((z) => z.id === form.delivery_zone_id))
const zoneName = computed(() => selectedZone.value?.full_name || '—')
const shippingEstimate = computed(() => (quote.value?.is_shipping ? quote.value.shipping_fee_estimate || 0 : 0))
// Ce que le marchand touche (ou paie) pour cette course, frais d'expédition estimés compris
const merchantNet = computed(() => (form.items_amount || 0) - (form.fee_payer === 'merchant' ? (quote.value?.total || 0) : 0) - shippingEstimate.value)
const codAmount = computed(() => (form.items_amount || 0) + (form.fee_payer === 'recipient' && quote.value ? quote.value.total : 0))

const shareLink = computed(() => {
  if (!created.value) return '#'
  const url = `${window.location.origin}/suivi/${created.value.tracking_code}`
  const text = `Bonjour, votre commande ${auth.user?.merchant?.business_name || ''} est en préparation pour la livraison.\nSuivi : ${url}\nCode de livraison à donner au livreur : ${created.value.delivery_code}`
  const phone = (created.value.recipient.phone || '').replace(/\D/g, '')
  return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`
})

watch(when, (value) => {
  if (value === 'asap') form.delivery_scheduled_date = null
  if (value === 'tomorrow') {
    const d = new Date(Date.now() + 86400000)
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
    form.delivery_scheduled_date = d.toISOString().slice(0, 10)
  }
})

// Tarif recalculé à chaque changement utile
watch(() => [form.delivery_zone_id, form.is_express, form.is_fragile], fetchQuote)

async function fetchQuote() {
  quote.value = null
  quoteError.value = ''
  if (!form.delivery_zone_id) return
  try {
    const { data } = await http.post('/quotes', {
      delivery_zone_id: form.delivery_zone_id,
      pickup_zone_id: sourceZoneId.value || undefined,
      is_express: form.is_express,
      is_fragile: form.is_fragile,
    })
    quote.value = data.data
  } catch (e) {
    quoteError.value = apiErrorMessage(e)
  }
}

let searchTimer
function searchRecipients() {
  clearTimeout(searchTimer)
  const term = form.recipient_phone.replace(/\D/g, '')
  if (term.length < 4) {
    suggestions.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    suggestions.value = (await http.get('/recipients', { params: { search: term.slice(-8) } })).data.data
  }, 250)
}

function pickRecipient(r) {
  Object.assign(form, {
    recipient_phone: r.phone,
    recipient_name: r.name || '',
    recipient_phone2: r.phone2 || '',
    delivery_zone_id: r.zone_id,
    delivery_address: r.address || '',
    delivery_landmark: r.landmark || '',
  })
  suggestions.value = []
}

function next() {
  error.value = ''
  if (step.value === 0 && form.recipient_phone.replace(/\D/g, '').length < 8) {
    error.value = 'Indiquez le numéro de téléphone du client.'
    return
  }
  if (step.value === 1 && !form.delivery_zone_id) {
    error.value = 'Choisissez la commune ou le quartier de livraison.'
    return
  }
  if (step.value === 2 && source.value && !pickedItems.value.length) {
    error.value = 'Choisissez les articles à prendre à l\'entrepôt.'
    return
  }
  if (step.value === 1 && when.value === 'other' && !form.delivery_scheduled_date) {
    error.value = 'Choisissez la date de livraison.'
    return
  }
  suggestions.value = []
  step.value++
  window.scrollTo({ top: 0 })
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
    if (pickedItems.value.length) payload.items = pickedItems.value.map(({ product_id, quantity, unit_price }) => ({ product_id, quantity, unit_price }))
    if (source.value) payload.pickup_hub_id = source.value
    created.value = (await http.post('/orders', payload)).data.data
    loadStock()
  } catch (e) {
    error.value = apiErrorMessage(e)
    const field = Object.keys(e.response?.data?.errors || {})[0] || ''
    if (field.startsWith('recipient')) step.value = 0
    else if (field.startsWith('delivery')) step.value = 1
    else if (field.startsWith('items') || field === 'pickup_hub_id') step.value = 2
  } finally {
    saving.value = false
  }
}

function reset() {
  Object.assign(form, blank(), { fee_payer: defaultFeePayer.value })
  created.value = null
  quote.value = null
  source.value = null
  Object.keys(picked).forEach((id) => delete picked[id])
  when.value = 'asap'
  step.value = 0
}

const defaultFeePayer = ref('recipient')

async function loadStock() {
  const [p, h] = await Promise.all([
    http.get('/products', { params: { per_page: 200 } }).catch(() => null),
    http.get('/hubs').catch(() => null),
  ])
  products.value = p?.data.data || []
  hubs.value = h?.data.data || []
}

onMounted(async () => {
  const [z, m] = await Promise.all([
    http.get('/zones'),
    http.get(`/merchants/${auth.user.merchant_id}`).catch(() => null),
  ])
  zones.value = z.data.data
  // Réglage habituel du marchand pour le payeur des frais de livraison
  defaultFeePayer.value = m?.data.data.default_fee_payer || 'recipient'
  form.fee_payer = defaultFeePayer.value
  loadStock()
})
</script>
