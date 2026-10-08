<template>
  <form class="space-y-5" @submit.prevent="submit">
    <div v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</div>

    <section v-if="!isMerchant" class="card p-4">
      <label class="label" for="merchant">E-commerçant *</label>
      <select id="merchant" v-model="form.merchant_id" class="input" required>
        <option :value="null" disabled>Choisir…</option>
        <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.business_name }}</option>
      </select>
      <p v-if="errors.merchant_id" class="field-error">{{ errors.merchant_id[0] }}</p>
    </section>

    <section class="card p-4 space-y-3">
      <h3 class="font-semibold">Destinataire</h3>
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="relative">
          <label class="label" for="phone">Téléphone *</label>
          <input id="phone" v-model="form.recipient_phone" class="input" required inputmode="tel" placeholder="07 00 00 00 00" autocomplete="off" @input="searchRecipients">
          <ul v-if="suggestions.length" class="absolute z-10 mt-1 w-full bg-white border rounded shadow max-h-48 overflow-y-auto">
            <li v-for="r in suggestions" :key="r.id" class="px-3 py-2 text-sm hover:bg-slate-50 cursor-pointer" @click="pickRecipient(r)">
              <span class="font-medium">{{ r.name || 'Sans nom' }}</span> · {{ r.phone }}
              <span v-if="r.failed_count" class="text-xs text-red-600"> · {{ r.failed_count }} échec(s)</span>
            </li>
          </ul>
          <p v-if="errors.recipient_phone" class="field-error">{{ errors.recipient_phone[0] }}</p>
        </div>
        <div>
          <label class="label" for="name">Nom</label>
          <input id="name" v-model="form.recipient_name" class="input">
        </div>
        <div>
          <label class="label" for="phone2">Second téléphone</label>
          <input id="phone2" v-model="form.recipient_phone2" class="input" inputmode="tel">
          <p v-if="errors.recipient_phone2" class="field-error">{{ errors.recipient_phone2[0] }}</p>
        </div>
        <div>
          <label class="label" for="zone">Commune / quartier de livraison *</label>
          <select id="zone" v-model="form.delivery_zone_id" class="input" required>
            <option :value="null" disabled>Choisir…</option>
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
          <p v-if="errors.delivery_zone_id" class="field-error">{{ errors.delivery_zone_id[0] }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="address">Adresse</label>
          <input id="address" v-model="form.delivery_address" class="input" placeholder="Rue, résidence, immeuble…">
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="landmark">Repère</label>
          <input id="landmark" v-model="form.delivery_landmark" class="input" placeholder="Ex. : face pharmacie, derrière l'église…">
        </div>
        <div>
          <label class="label" for="date">Date de livraison souhaitée</label>
          <input id="date" v-model="form.delivery_scheduled_date" type="date" :min="today()" class="input">
          <p v-if="errors.delivery_scheduled_date" class="field-error">{{ errors.delivery_scheduled_date[0] }}</p>
        </div>
        <div>
          <label class="label" for="slot">Créneau</label>
          <select id="slot" v-model="form.delivery_time_slot" class="input">
            <option :value="null">Indifférent</option>
            <option value="08-12">Matin (8h-12h)</option>
            <option value="12-16">Après-midi (12h-16h)</option>
            <option value="16-20">Soir (16h-20h)</option>
          </select>
        </div>
      </div>
    </section>

    <section v-if="products.length || hubs.length" class="card p-4 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-semibold">Articles du stock</h3>
        <select v-model="form.pickup_hub_id" class="input w-auto" aria-label="Provenance des articles">
          <option :value="null">Chez le marchand (ramassage)</option>
          <option v-for="h in hubs" :key="h.id" :value="h.id">🏬 {{ h.name }} (préparée à l'entrepôt)</option>
        </select>
      </div>
      <p v-if="form.pickup_hub_id" class="text-sm rounded bg-sky-50 text-sky-900 p-2">
        La commande sera préparée à l'entrepôt puis livrée : pas de ramassage chez le marchand.
      </p>
      <div v-for="(item, i) in items" :key="i" class="grid grid-cols-[1fr_6rem_7rem_auto] gap-2 items-center">
        <select v-if="item.product_id !== undefined" v-model="item.product_id" class="input" :aria-label="`Produit ${i + 1}`" @change="pickProduct(item)">
          <option :value="null" disabled>Choisir un produit…</option>
          <option v-for="p in products" :key="p.id" :value="p.id" :disabled="availableAt(p) <= 0">
            {{ p.name }}{{ p.sku ? ` (${p.sku})` : '' }} · {{ availableAt(p) }} dispo
          </option>
        </select>
        <input v-else v-model="item.label" class="input" placeholder="Article libre (hors stock)" :aria-label="`Article ${i + 1}`">
        <input v-model.number="item.quantity" type="number" min="1" class="input" :aria-label="`Quantité ${i + 1}`">
        <input v-model.number="item.unit_price" type="number" min="0" step="50" class="input" placeholder="Prix" :aria-label="`Prix unitaire ${i + 1}`">
        <button type="button" class="btn-secondary px-2" :aria-label="`Retirer l'article ${i + 1}`" @click="items.splice(i, 1)">✕</button>
      </div>
      <p v-if="errors.items || errors.pickup_hub_id" class="field-error">{{ (errors.items || errors.pickup_hub_id)[0] }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="!products.length" @click="items.push({ product_id: null, quantity: 1, unit_price: 0 })">+ Produit du stock</button>
        <button v-if="!form.pickup_hub_id" type="button" class="btn-secondary" @click="items.push({ label: '', quantity: 1, unit_price: 0 })">+ Article libre</button>
      </div>
      <p v-if="items.length" class="text-sm text-gray-600">Total des articles : <strong>{{ money(itemsTotal) }}</strong> (reporté dans le montant à encaisser, modifiable)</p>
    </section>

    <section class="card p-4 space-y-3">
      <h3 class="font-semibold">Colis et paiement</h3>
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label class="label" for="desc">Description du colis</label>
          <input id="desc" v-model="form.description" class="input" placeholder="Ex. : 2 robes, 1 sac">
        </div>
        <div>
          <label class="label" for="items">Montant à encaisser pour les articles (F)</label>
          <input id="items" v-model.number="form.items_amount" type="number" min="0" step="50" class="input">
        </div>
        <div>
          <label class="label" for="payer">Frais de livraison payés par</label>
          <select id="payer" v-model="form.fee_payer" class="input">
            <option value="merchant">Moi (déduits du reversement)</option>
            <option value="recipient">Le destinataire (ajoutés à l'encaissement)</option>
          </select>
        </div>
        <div>
          <label class="label" for="ref">Référence commande</label>
          <input id="ref" v-model="form.merchant_reference" class="input">
        </div>
        <div>
          <label class="label" for="weight">Poids (kg)</label>
          <input id="weight" v-model.number="form.weight_kg" type="number" min="0" step="0.1" class="input">
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_express" type="checkbox"> Express</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_fragile" type="checkbox"> Fragile</label>
        <div class="sm:col-span-2">
          <label class="label" for="note">Instruction pour le livreur</label>
          <textarea id="note" v-model="form.merchant_note" rows="2" class="input" />
        </div>
      </div>
    </section>

    <details v-if="!form.pickup_hub_id" class="card p-4">
      <summary class="font-semibold cursor-pointer">Lieu de ramassage (par défaut : adresse du marchand)</summary>
      <div class="grid sm:grid-cols-2 gap-3 mt-3">
        <div>
          <label class="label" for="pzone">Zone de ramassage</label>
          <select id="pzone" v-model="form.pickup_zone_id" class="input">
            <option :value="null">Adresse habituelle</option>
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="paddress">Adresse de ramassage</label>
          <input id="paddress" v-model="form.pickup_address" class="input">
        </div>
      </div>
    </details>

    <div class="card p-4 flex flex-wrap items-center justify-between gap-3 sticky bottom-0">
      <div class="text-sm">
        <p v-if="quoteError" class="text-red-600">{{ quoteError }}</p>
        <template v-else-if="quote">
          <p>Frais de livraison : <strong>{{ money(quote.total) }}</strong></p>
          <p class="text-gray-600">À encaisser auprès du client : <strong>{{ money(codAmount) }}</strong></p>
        </template>
        <p v-else class="text-gray-500">Choisissez la zone pour voir le tarif.</p>
      </div>
      <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : submitLabel }}</button>
    </div>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { money, today } from '../utils/format'

const props = defineProps({
  submitLabel: { type: String, default: 'Enregistrer la course' },
})
const emit = defineEmits(['saved'])

const auth = useAuthStore()
const isMerchant = computed(() => !!auth.user?.merchant_id)

const form = reactive({
  merchant_id: null,
  recipient_name: '',
  recipient_phone: '',
  recipient_phone2: '',
  delivery_zone_id: null,
  delivery_address: '',
  delivery_landmark: '',
  delivery_scheduled_date: null,
  delivery_time_slot: null,
  description: '',
  items_amount: 0,
  fee_payer: 'merchant',
  merchant_reference: '',
  weight_kg: null,
  is_express: false,
  is_fragile: false,
  merchant_note: '',
  pickup_zone_id: null,
  pickup_address: '',
  pickup_hub_id: null,
})
const items = ref([])
const hubs = ref([])
const products = ref([])

const zones = ref([])
const merchants = ref([])
const suggestions = ref([])
const quote = ref(null)
const quoteError = ref('')
const error = ref('')
const errors = ref({})
const saving = ref(false)

const itemsTotal = computed(() => items.value.reduce((sum, i) => sum + (i.quantity || 0) * (i.unit_price || 0), 0))
const merchantId = computed(() => (isMerchant.value ? auth.user.merchant_id : form.merchant_id))
const hubZoneId = computed(() => hubs.value.find((h) => h.id === form.pickup_hub_id)?.zone_id)

// Disponible du produit à l'emplacement choisi (chez le marchand ou à l'entrepôt)
function availableAt(product) {
  return product.levels?.find((l) => (l.hub_id ?? null) === (form.pickup_hub_id ?? null))?.available ?? 0
}

function pickProduct(item) {
  const product = products.value.find((p) => p.id === item.product_id)
  if (product) item.unit_price = product.price
}

async function loadProducts() {
  products.value = []
  if (!merchantId.value) return
  products.value = (await http.get('/products', { params: { merchant_id: merchantId.value, per_page: 200 } })).data.data
}

watch(itemsTotal, (total) => {
  if (items.value.length) form.items_amount = total
})
watch(() => form.pickup_hub_id, (hub) => {
  if (hub) items.value = items.value.filter((i) => i.product_id !== undefined)
})

const codAmount = computed(() => (form.items_amount || 0) + (form.fee_payer === 'recipient' && quote.value ? quote.value.total : 0))

onMounted(async () => {
  const requests = [http.get('/zones'), http.get('/hubs').catch(() => ({ data: { data: [] } }))]
  if (!isMerchant.value) requests.push(http.get('/merchants', { params: { per_page: 200, status: 'active' } }))
  const [z, h, m] = await Promise.all(requests)
  zones.value = z.data.data
  hubs.value = h.data.data
  if (m) merchants.value = m.data.data
  loadProducts()
})

watch(() => form.merchant_id, (id) => {
  const merchant = merchants.value.find((m) => m.id === id)
  if (merchant) form.fee_payer = merchant.default_fee_payer
  items.value = []
  loadProducts()
})

// Devis en direct
let quoteTimer
watch(
  () => [form.merchant_id, form.delivery_zone_id, form.pickup_zone_id, form.pickup_hub_id, form.is_express, form.is_fragile, form.weight_kg],
  () => {
    clearTimeout(quoteTimer)
    quoteTimer = setTimeout(fetchQuote, 250)
  },
)

async function fetchQuote() {
  quote.value = null
  quoteError.value = ''
  if (!form.delivery_zone_id || (!isMerchant.value && !form.merchant_id)) return
  try {
    const { data } = await http.post('/quotes', {
      merchant_id: isMerchant.value ? undefined : form.merchant_id,
      delivery_zone_id: form.delivery_zone_id,
      pickup_zone_id: hubZoneId.value || form.pickup_zone_id || undefined,
      is_express: form.is_express,
      is_fragile: form.is_fragile,
      weight_kg: form.weight_kg || undefined,
    })
    quote.value = data.data
  } catch (e) {
    quoteError.value = apiErrorMessage(e)
  }
}

let searchTimer
function searchRecipients() {
  clearTimeout(searchTimer)
  const term = form.recipient_phone.replace(/\s/g, '')
  if (term.length < 4 || (!isMerchant.value && !form.merchant_id)) {
    suggestions.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    const { data } = await http.get('/recipients', {
      params: { search: term.slice(-8), merchant_id: isMerchant.value ? undefined : form.merchant_id },
    })
    suggestions.value = data.data
  }, 250)
}

function pickRecipient(r) {
  Object.assign(form, {
    recipient_name: r.name || '',
    recipient_phone: r.phone,
    recipient_phone2: r.phone2 || '',
    delivery_zone_id: r.zone_id,
    delivery_address: r.address || '',
    delivery_landmark: r.landmark || '',
  })
  suggestions.value = []
}

async function submit() {
  saving.value = true
  error.value = ''
  errors.value = {}
  try {
    const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
    if (isMerchant.value) delete payload.merchant_id
    if (form.pickup_hub_id) {
      delete payload.pickup_zone_id
      delete payload.pickup_address
    }
    const lines = items.value.filter((i) => i.product_id || i.label?.trim())
    if (lines.length) {
      payload.items = lines.map((i) => (i.product_id
        ? { product_id: i.product_id, quantity: i.quantity || 1, unit_price: i.unit_price || 0 }
        : { label: i.label.trim(), quantity: i.quantity || 1, unit_price: i.unit_price || 0 }))
    }
    const { data } = await http.post('/orders', payload)
    emit('saved', data.data)
  } catch (e) {
    errors.value = e.response?.data?.errors || {}
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
