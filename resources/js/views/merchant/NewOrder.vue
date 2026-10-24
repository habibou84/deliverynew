<template>
  <!-- Course enregistrée -->
  <div v-if="created" class="text-center space-y-5 pt-6">
    <div class="mx-auto h-20 w-20 rounded-full bg-emerald-100 grid place-items-center text-emerald-700"><AppIcon name="check" class="h-10 w-10" :stroke="2.4" /></div>
    <div>
      <p class="text-2xl font-bold">Course enregistrée</p>
      <p class="text-slate-500">{{ created.from_warehouse ? 'L\'agence prépare votre commande à l\'entrepôt.' : 'Nous allons envoyer un livreur récupérer le colis.' }}</p>
      <p v-if="created.delivery?.scheduled_date" class="mt-1 font-semibold text-slate-700">Livraison prévue le {{ longDay(created.delivery.scheduled_date) }}</p>
    </div>
    <div class="m-card p-4 space-y-1">
      <p class="text-sm text-slate-500">Code de suivi</p>
      <p class="font-mono text-xl font-bold tracking-wider">{{ created.tracking_code }}</p>
      <p class="text-sm text-slate-500 pt-2">Code de livraison à donner au client</p>
      <p class="font-mono text-2xl font-bold tracking-[0.3em]">{{ created.delivery_code }}</p>
    </div>
    <a :href="shareLink" target="_blank" class="m-btn-success"><AppIcon name="chat" class="h-5 w-5" />Envoyer le suivi au client sur WhatsApp</a>
    <div class="grid grid-cols-2 gap-2">
      <RouterLink :to="`/marchand/courses/${created.id}`" class="m-btn-secondary">Voir la course</RouterLink>
      <button class="m-btn-primary" @click="reset">+ Nouvelle</button>
    </div>
  </div>

  <!-- Toute la course sur une page : le client, où livrer, l'argent, le jour ; le prix suit en bas -->
  <form v-else class="space-y-4 pb-44" :style="lifted ? { paddingBottom: '100vh' } : {}" novalidate autocomplete="off" @submit.prevent="submit" @focusin="liftField" @focusout="lifted = false">
    <div v-if="error" class="rounded-xl bg-red-50 text-red-700 px-4 py-3 text-sm" role="alert">{{ error }}</div>
    <p v-if="fromMessage" class="rounded-xl bg-amber-50 text-amber-900 px-4 py-3 text-sm">Course remplie d'après le message : vérifiez avant d'envoyer.</p>

    <!-- Message collé : remplit toute la course -->
    <PasteMessage v-if="pasteOpen" mobile start-open @parsed="applyParsed" />
    <button v-else type="button" class="tap w-full flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-bold text-[var(--app-color)] ring-1 ring-slate-200 active:bg-slate-50" @click="pasteOpen = true">
      <AppIcon name="clip" class="h-[18px] w-[18px]" />Coller un message WhatsApp pour tout remplir
    </button>

    <!-- 1. Le client -->
    <section class="rounded-2xl bg-white p-4 ring-1 ring-slate-200 space-y-3" aria-labelledby="step-client">
      <h2 id="step-client" class="m-step"><span class="m-step-num">1</span>Le client</h2>
      <div>
        <label class="m-label" for="phone">Téléphone <span class="text-red-600">*</span></label>
        <input id="phone" ref="phoneInput" :value="form.recipient_phone" :class="['m-input !text-xl !font-bold', missing.phone ? 'ring-2 ring-red-500' : '']" inputmode="tel" autocomplete="jibiat-off" placeholder="07 00 00 00 00" @input="onPhone" @blur="checkDuplicates">
      </div>
      <!-- Clients déjà livrés : un toucher remplit le nom et l'adresse -->
      <button v-for="r in suggestions" :key="r.id" type="button" class="tap w-full flex items-center gap-3 rounded-xl bg-emerald-50 px-3 py-2.5 text-left ring-1 ring-emerald-200" @click="pickRecipient(r)">
        <span class="min-w-0 flex-1 text-sm">
          <strong class="block truncate">{{ r.name || localPhone(r.phone) }}</strong>
          <span class="block truncate text-slate-600">{{ [zoneOf(r), r.deliveries_count ? `livré ${r.deliveries_count} fois` : 'déjà enregistré'].filter(Boolean).join(' · ') }}</span>
          <span v-if="r.failed_count" class="block text-xs text-red-600">{{ r.failed_count }} livraison(s) échouée(s)</span>
        </span>
        <span class="shrink-0 rounded-lg px-3 py-2 text-xs font-bold text-white bg-[var(--app-color)]">Utiliser</span>
      </button>
      <div v-if="duplicates.length" class="rounded-xl bg-amber-50 text-amber-900 px-4 py-3 text-sm">
        Déjà {{ duplicates.length > 1 ? `${duplicates.length} courses` : 'une course' }} pour ce client ces dernières 24 h :
        <RouterLink v-for="d in duplicates" :key="d.id" :to="`/marchand/courses/${d.id}`" class="underline ml-1">{{ d.tracking_code }} ({{ d.status_label }})</RouterLink>
      </div>
      <div>
        <label class="m-label" for="name">Nom <span class="font-normal text-slate-500">(facultatif)</span></label>
        <input id="name" v-model="form.recipient_name" class="m-input" autocomplete="jibiat-off" placeholder="Ex. : Jean Kouadio">
      </div>
    </section>

    <!-- 2. Où livrer -->
    <section class="rounded-2xl bg-white p-4 ring-1 ring-slate-200 space-y-3" aria-labelledby="step-where">
      <h2 id="step-where" class="m-step"><span class="m-step-num">2</span>Où livrer</h2>
      <div id="zone-field">
        <label class="m-label" for="zone">Commune ou quartier <span class="text-red-600">*</span></label>
        <!-- Communes proposées en un toucher : celles du message, sinon celles des dernières courses -->
        <div v-if="quickZones.length" class="mb-2 flex flex-wrap gap-1.5">
          <button
            v-for="z in quickZones"
            :key="z.id"
            type="button"
            :class="['tap rounded-full px-3 py-1.5 text-sm font-semibold ring-1', form.delivery_zone_id === z.id ? 'text-white ring-transparent bg-[var(--app-color)]' : 'bg-white ring-slate-300']"
            @click="form.delivery_zone_id = z.id"
          >{{ z.full_name }}</button>
        </div>
        <ZoneSelect id="zone" v-model="form.delivery_zone_id" :zones="zones" mobile :input-class="['m-input', missing.zone ? 'ring-2 ring-red-500' : '']" />
        <p v-if="selectedZone?.is_shipping" class="mt-2 rounded-xl bg-indigo-50 text-indigo-900 text-sm p-3">
          <strong>Expédition</strong> : notre livreur dépose le colis à la gare et paie l'envoi
          <template v-if="selectedZone.shipping_fee_estimate">(environ {{ money(selectedZone.shipping_fee_estimate) }})</template>.
          Ces frais vous sont facturés au réel et déduits de votre point.
        </p>
      </div>
      <div>
        <label class="m-label" for="address">Adresse et repère</label>
        <input id="address" v-model="form.delivery_address" autocomplete="jibiat-off" class="m-input" placeholder="Ex. : résidence Les Palmiers, face pharmacie">
      </div>
    </section>

    <!-- 3. L'argent -->
    <section class="rounded-2xl bg-white p-4 ring-1 ring-slate-200 space-y-3" aria-labelledby="step-money">
      <h2 id="step-money" class="m-step"><span class="m-step-num">3</span>L'argent</h2>

      <button v-if="products.length && !showStock" type="button" class="tap w-full rounded-xl ring-1 ring-slate-200 px-4 py-3 flex items-center justify-between text-sm font-semibold active:bg-slate-50" @click="showStock = true">
        <span class="flex items-center gap-2"><AppIcon name="store" class="h-5 w-5" />Prendre des articles de mon stock</span><AppIcon name="right" class="h-4 w-4 text-slate-400" />
      </button>
      <div v-if="products.length && showStock" class="space-y-3">
        <div v-if="stockHubs.length">
          <p class="m-label">D'où part le colis ?</p>
          <ChoiceChips v-model="source" :options="sourceOptions" :columns="1" label="Provenance du colis" />
        </div>
        <div class="rounded-xl ring-1 ring-slate-200 divide-y">
          <div v-for="p in sourceProducts" :key="p.id" class="p-3 flex items-center gap-3">
            <div class="min-w-0 flex-1">
              <p class="font-medium truncate">{{ p.name }}</p>
              <p class="text-xs text-slate-500">{{ money(p.price) }} · {{ availableAt(p) }} dispo</p>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" class="tap h-9 w-9 rounded-full bg-slate-100 text-lg font-bold disabled:opacity-40" :disabled="!picked[p.id]" :aria-label="`Retirer un ${p.name}`" @click="pick(p, -1)">−</button>
              <span class="w-6 text-center font-semibold">{{ picked[p.id] || 0 }}</span>
              <button type="button" class="tap h-9 w-9 rounded-full text-white text-lg font-bold disabled:opacity-40 bg-[var(--app-color)]" :disabled="(picked[p.id] || 0) >= availableAt(p)" :aria-label="`Ajouter un ${p.name}`" @click="pick(p, 1)">+</button>
            </div>
          </div>
          <p v-if="!sourceProducts.length" class="p-3 text-sm text-slate-500">Aucun produit disponible {{ source ? 'à cet entrepôt' : 'chez vous' }}.</p>
        </div>
      </div>

      <div id="amount">
        <label class="m-label" for="amount-input">Montant à encaisser chez le client</label>
        <div class="relative">
          <input id="amount-input" v-model.number="form.items_amount" type="number" min="0" step="50" inputmode="numeric" autocomplete="jibiat-off" class="m-input !text-2xl !font-bold pr-12" placeholder="0">
          <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">F</span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Prix des articles, sans la livraison. Laissez vide si le client a déjà payé.</p>
      </div>

      <div id="fee-payer" :class="['rounded-2xl', missing.feePayer ? 'ring-2 ring-red-500 p-2 -mx-2' : '']">
        <p class="m-label">Qui paie la livraison ? <span class="text-red-600">*</span></p>
        <ChoiceChips
          v-model="form.fee_payer"
          :columns="2"
          label="Payeur des frais"
          :options="[
            { value: 'recipient', label: 'Le client', description: 'ajoutée au montant' },
            { value: 'merchant', label: 'Moi', description: 'déduite de mon paiement' },
          ]"
        />
        <p v-if="missing.feePayer" class="mt-2 text-sm font-medium text-red-600" role="alert">Choisissez qui paie la livraison.</p>
      </div>
    </section>

    <!-- 4. Quand livrer -->
    <section class="rounded-2xl bg-white p-4 ring-1 ring-slate-200 space-y-3" aria-labelledby="step-when">
      <h2 id="step-when" class="m-step"><span class="m-step-num">4</span><span>Quand livrer <span class="text-red-600">*</span></span></h2>
      <div id="when-field" :class="['rounded-2xl', missing.date ? 'ring-2 ring-red-500 p-2 -mx-2' : '']">
        <ChoiceChips v-model="when" :options="deliveryOptions" :columns="3" label="Jour de livraison" />
        <p v-if="closed" class="mt-2 text-xs text-slate-500">{{ closedReason }} : le ramassage peut quand même se faire aujourd'hui.</p>
        <input v-if="when === 'other'" id="date" v-model="form.delivery_scheduled_date" type="date" :min="minDate" class="m-input mt-2" aria-label="Jour de livraison">
        <p v-if="missing.date" class="mt-2 text-sm font-medium text-red-600" role="alert">Choisissez quand livrer la course.</p>
      </div>
    </section>

    <!-- Le reste, seulement si besoin -->
    <section class="rounded-2xl bg-white px-4 py-1 ring-1 ring-slate-200">
      <button type="button" class="tap w-full flex items-center justify-between py-3 text-[15px] font-semibold" :aria-expanded="showMore" @click="showMore = !showMore">
        <span>{{ showMore ? '−' : '+' }} Contenu du colis, express, message au livreur</span>
        <span v-if="!showMore && extrasSummary" class="truncate pl-2 text-xs font-normal text-slate-500">{{ extrasSummary }}</span>
      </button>
      <div v-show="showMore" class="space-y-4 pt-1 pb-4">
        <div>
          <label class="m-label" for="desc">Que contient le colis ?</label>
          <input id="desc" v-model="form.description" autocomplete="jibiat-off" class="m-input" placeholder="Ex. : 2 robes, 1 sac">
        </div>
        <div class="grid grid-cols-2 gap-2">
          <label :class="['tap flex items-center gap-2 rounded-xl px-4 py-3 ring-1', form.is_express ? 'ring-2 ring-[var(--app-color)]' : 'ring-slate-200 bg-white']">
            <input v-model="form.is_express" type="checkbox" class="h-5 w-5 accent-[var(--app-color)]"> Express
          </label>
          <label :class="['tap flex items-center gap-2 rounded-xl px-4 py-3 ring-1', form.is_fragile ? 'ring-2 ring-[var(--app-color)]' : 'ring-slate-200 bg-white']">
            <input v-model="form.is_fragile" type="checkbox" class="h-5 w-5 accent-[var(--app-color)]"> Fragile
          </label>
        </div>
        <div>
          <label class="m-label" for="note">Message pour le livreur</label>
          <textarea id="note" v-model="form.merchant_note" autocomplete="jibiat-off" rows="2" class="m-input" placeholder="Ex. : appeler avant d'arriver" />
        </div>
        <div>
          <label class="m-label" for="phone2">Autre numéro du client</label>
          <input id="phone2" v-model="form.recipient_phone2" autocomplete="jibiat-off" class="m-input" inputmode="tel" placeholder="Si le premier ne répond pas">
        </div>
      </div>
    </section>

    <!-- Prix en direct et envoi, toujours en bas (caché pendant la saisie au clavier) -->
    <div v-show="!keyboardOpen" class="fixed bottom-0 inset-x-0 z-20 bg-white/95 backdrop-blur border-t border-slate-200 pb-safe shadow-[0_-6px_18px_rgba(15,23,42,0.06)]">
      <div class="mx-auto max-w-lg px-4 py-3 space-y-2">
        <div v-if="quote" class="flex items-end justify-between gap-3 text-sm">
          <div class="min-w-0">
            <p class="text-slate-500">Livraison {{ money(quote.total) }}{{ quote.is_shipping ? ` + expédition ≈ ${money(shippingEstimate)}` : '' }}</p>
            <p class="text-slate-500">Le livreur encaisse <b class="text-slate-800">{{ money(codAmount) }}</b></p>
          </div>
          <p :class="['shrink-0 text-right font-bold', merchantNet < 0 ? 'text-slate-700' : 'text-emerald-700']">
            <span class="block text-xs font-medium text-slate-500">{{ merchantNet < 0 ? 'À votre charge' : 'Pour vous' }}</span>
            <span class="text-lg">{{ quote.is_shipping ? '≈ ' : '' }}{{ money(Math.abs(merchantNet)) }}</span>
          </p>
        </div>
        <p v-else-if="quoteError" class="text-sm text-red-600">{{ quoteError }}</p>
        <p v-else class="text-sm text-slate-500">{{ form.delivery_zone_id ? 'Calcul du prix…' : 'Choisissez la commune pour voir le prix.' }}</p>
        <button type="submit" class="m-btn-primary" :disabled="saving">{{ saving ? 'Envoi…' : 'Envoyer la course' }}</button>
      </div>
    </div>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import PasteMessage from '../../components/PasteMessage.vue'
import ZoneSelect from '../../components/ZoneSelect.vue'
import { longDay, useDeliveryDate } from '../../composables/useDeliveryDate'
import { useAuthStore } from '../../stores/auth'
import { localPhone, money } from '../../utils/format'
import { keyboardOpen } from '../../composables/useKeyboard'
import AppIcon from '../../components/mobile/AppIcon.vue'
import { siteOrigin } from '../../native'

const route = useRoute()

const auth = useAuthStore()

const blank = () => ({
  recipient_phone: '',
  recipient_name: '',
  recipient_phone2: '',
  delivery_zone_id: null,
  delivery_address: '',
  delivery_scheduled_date: null,
  items_amount: null,
  // Aucun choix par défaut : le marchand doit indiquer qui paie la livraison
  fee_payer: null,
  description: '',
  is_express: false,
  is_fragile: false,
  merchant_note: '',
})

// Un seul écran : l'essentiel visible, le reste derrière « Date, contenu du colis… »
const form = reactive(blank())
const showMore = ref(false)
const showStock = ref(false)
// Champs obligatoires oubliés, encadrés en rouge jusqu'à leur saisie
const missing = reactive({ phone: false, zone: false, feePayer: false, date: false })
const zones = ref([])
const suggestions = ref([])
const quote = ref(null)
const quoteError = ref('')
const error = ref('')
const saving = ref(false)
const created = ref(null)
const products = ref([])
const hubs = ref([])
const source = ref(null) // null = chez moi, sinon identifiant de l'entrepôt
const picked = reactive({})

// Jour de livraison : choix obligatoire, « Aujourd'hui » impossible quand l'agence a fermé la journée
const { when, options: deliveryOptions, closed, closedReason, minDate, isChosen: dateChosen, load: loadDeliveryDay, reset: resetDate } = useDeliveryDate(form)

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
  fillDescription()
}

// « Que contient le colis ? » reprend les articles choisis, sauf si le marchand y a écrit autre chose
let autoDescription = ''
function fillDescription() {
  if (form.description && form.description !== autoDescription) return
  autoDescription = pickedItems.value.map((i) => `${i.quantity} × ${i.name}`).join(', ')
  form.description = autoDescription
}

watch(source, () => {
  Object.keys(picked).forEach((id) => delete picked[id])
  fillDescription()
  fetchQuote()
})

const selectedZone = computed(() => zones.value.find((z) => z.id === form.delivery_zone_id))
const zoneName = computed(() => selectedZone.value?.full_name || '—')
const zoneOf = (recipient) => zones.value.find((z) => z.id === recipient.zone_id)?.full_name
// Options remplies, rappelées quand la section est repliée
const extrasSummary = computed(() => [
  form.description,
  form.is_express ? 'Express' : null,
  form.is_fragile ? 'Fragile' : null,
  form.merchant_note ? 'message au livreur' : null,
].filter(Boolean).join(' · '))
const shippingEstimate = computed(() => (quote.value?.is_shipping ? quote.value.shipping_fee_estimate || 0 : 0))
// Ce que le marchand touche (ou paie) pour cette course, frais d'expédition estimés compris
const merchantNet = computed(() => (form.items_amount || 0) - (form.fee_payer === 'merchant' ? (quote.value?.total || 0) : 0) - shippingEstimate.value)
const codAmount = computed(() => (form.items_amount || 0) + (form.fee_payer === 'recipient' && quote.value ? quote.value.total : 0))

const shareLink = computed(() => {
  if (!created.value) return '#'
  const url = `${siteOrigin()}/suivi/${created.value.tracking_code}`
  const text = `Bonjour, votre commande ${auth.user?.merchant?.business_name || ''} est en préparation pour la livraison.\nSuivi : ${url}\nCode de livraison à donner au livreur : ${created.value.delivery_code}`
  const phone = (created.value.recipient.phone || '').replace(/\D/g, '')
  return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`
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
    // Trois clients au plus : ceux dont le numéro correspond le mieux
    suggestions.value = (await http.get('/recipients', { params: { search: term.slice(-8) } })).data.data.slice(0, 3)
  }, 250)
}

function pickRecipient(r) {
  Object.assign(form, {
    // Numéros affichés comme on les tape (sans l'indicatif)
    recipient_phone: (r.phone || '').replace(/^\+225/, '').replace(/(\d{2})(?=\d)/g, '$1 '),
    recipient_name: r.name || '',
    recipient_phone2: (r.phone2 || '').replace(/^\+225/, ''),
    delivery_zone_id: r.zone_id,
    delivery_address: joinAddress(r.address, r.landmark),
  })
  if (r.phone2) showMore.value = true
  suggestions.value = []
  checkDuplicates()
}

// Adresse et repère réunis en un seul champ
const joinAddress = (...parts) => parts.map((p) => (p || '').trim()).filter(Boolean).join(', ')

// Message collé (WhatsApp, SMS) : champs remplis, à vérifier étape par étape
const fromMessage = ref(false)
const zoneChoices = ref([])
const duplicates = ref([])

function applyParsed(data) {
  const set = (key, value) => { if (value !== undefined && value !== null && value !== '') form[key] = value }
  set('recipient_name', data.recipient_name)
  set('recipient_phone', (data.recipient_phone || '').replace(/^\+225/, ''))
  set('recipient_phone2', (data.recipient_phone2 || '').replace(/^\+225/, ''))
  set('delivery_address', joinAddress(data.delivery_address, data.delivery_landmark))
  set('items_amount', data.items_amount)
  set('fee_payer', data.fee_payer)
  set('description', data.description)
  set('merchant_note', data.merchant_note)
  zoneChoices.value = data.zones || []
  // Commune la plus probable choisie, les autres proposées au-dessus du champ
  if (zoneChoices.value.length) form.delivery_zone_id = zoneChoices.value[0].id
  if (data.description || data.merchant_note || data.recipient_phone2) showMore.value = true
  fromMessage.value = true
  pasteOpen.value = false
  checkDuplicates()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// Même client ces dernières 24 h : risque de doublon
async function checkDuplicates() {
  duplicates.value = []
  if (form.recipient_phone.replace(/\D/g, '').length < 8) return
  try {
    duplicates.value = (await http.get('/orders/duplicates', { params: { recipient_phone: form.recipient_phone } })).data.data
  } catch {
    // Simple alerte : sans réponse, on n'affiche rien
  }
}

// Champs obligatoires de chaque étape
function problemsOf(n) {
  if (n === 1) return [[form.recipient_phone.replace(/\D/g, '').length < 8, 'phone', 'Indiquez le numéro de téléphone du client.']]
  if (n === 2) return [[!form.delivery_zone_id, 'zone', 'Choisissez la commune ou le quartier de livraison.']]
  if (n === 3) {
    return [
      [source.value && !pickedItems.value.length, null, 'Choisissez les articles à prendre à l\'entrepôt.'],
      [!form.fee_payer, 'feePayer', 'Choisissez qui paie la livraison : le client ou vous.'],
      [!dateChosen.value, 'date', 'Choisissez quand livrer la course.'],
    ]
  }
  return []
}

function validate(n) {
  const problems = problemsOf(n)
  problems.forEach(([bad, key]) => { if (key) missing[key] = !!bad })
  const first = problems.find(([bad]) => bad)
  error.value = first ? first[2] : ''
  return !first
}

const pasteOpen = ref(route.query.coller === '1')
const phoneInput = ref(null)

// Champ touché remonté sous l'en-tête : le clavier et la liste des communes ont la place en dessous.
// La marge du bas s'agrandit le temps de la saisie pour que même les derniers champs puissent remonter.
const lifted = ref(false)
function liftField(event) {
  const field = event.target
  if (!field.matches('input:not([type=checkbox]):not([type=radio]), textarea, select')) return
  lifted.value = true
  setTimeout(() => {
    const header = document.querySelector('header')?.offsetHeight || 56
    const label = field.id && document.querySelector(`label[for="${field.id}"]`)
    const top = (label || field).getBoundingClientRect().top
    window.scrollTo({ top: window.scrollY + top - header - 12, behavior: 'smooth' })
  }, 250)
}

// Numéro affiché par paires pendant la frappe : « 07 00 00 00 01 »
function onPhone(event) {
  const raw = event.target.value
  const digits = raw.replace(/\D/g, '')
  const formatted = raw.trim().startsWith('+') ? raw : digits.replace(/(\d{2})(?=\d)/g, '$1 ')
  form.recipient_phone = formatted
  event.target.value = formatted
  searchRecipients()
}

// Communes proposées en un toucher : celles du message, sinon celles des dernières courses
const recentZones = ref([])
const quickZones = computed(() => {
  const ids = zoneChoices.value.length ? zoneChoices.value.map((z) => z.id) : recentZones.value
  return ids.map((id) => zones.value.find((z) => z.id === id)).filter(Boolean).slice(0, 4)
})

async function loadRecentZones() {
  try {
    const { data } = await http.get('/orders', { params: { per_page: 40 } })
    const counts = {}
    data.data.forEach((o) => { counts[o.delivery.zone_id] = (counts[o.delivery.zone_id] || 0) + 1 })
    recentZones.value = Object.keys(counts).sort((a, b) => counts[b] - counts[a]).map(Number)
  } catch {
    // Facultatif : la recherche de commune reste disponible
  }
}

async function submit() {
  error.value = ''
  suggestions.value = []
  // Champs obligatoires : le premier oublié est montré à l'écran
  const bad = [1, 2, 3].find((n) => !validate(n))
  if (bad) {
    const target = { 1: 'phone', 2: 'zone-field', 3: missing.feePayer ? 'fee-payer' : missing.date ? 'when-field' : 'amount' }[bad]
    setTimeout(() => document.getElementById(target)?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
    return
  }
  saving.value = true
  try {
    const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
    payload.recipient_phone = form.recipient_phone.replace(/[^\d+]/g, '')
    if (payload.recipient_phone2) payload.recipient_phone2 = payload.recipient_phone2.replace(/[^\d+]/g, '')
    payload.items_amount = form.items_amount || 0
    if (pickedItems.value.length) payload.items = pickedItems.value.map(({ product_id, quantity, unit_price }) => ({ product_id, quantity, unit_price }))
    if (source.value) payload.pickup_hub_id = source.value
    created.value = (await http.post('/orders', payload)).data.data
    loadStock()
  } catch (e) {
    error.value = apiErrorMessage(e)
    const field = Object.keys(e.response?.data?.errors || {})[0] || ''
    if (field === 'fee_payer') missing.feePayer = true
    if (field === 'recipient_phone') missing.phone = true
    if (field === 'delivery_zone_id') missing.zone = true
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } finally {
    saving.value = false
  }
}

function reset() {
  Object.assign(form, blank())
  Object.keys(missing).forEach((key) => { missing[key] = false })
  showMore.value = false
  showStock.value = false
  created.value = null
  quote.value = null
  source.value = null
  Object.keys(picked).forEach((id) => delete picked[id])
  resetDate()
  fromMessage.value = false
  zoneChoices.value = []
  duplicates.value = []
  pasteOpen.value = false
}

// Champ oublié corrigé : plus de cadre rouge, et le message disparaît quand tout est rempli
watch(() => form.fee_payer, (value) => { if (value) missing.feePayer = false })
watch(() => form.delivery_zone_id, (value) => { if (value) missing.zone = false })
watch(() => form.recipient_phone, (value) => { if (value.replace(/\D/g, '').length >= 8) missing.phone = false })
watch(() => form.delivery_scheduled_date, (value) => { if (value) missing.date = false })
watch(missing, (value) => { if (!Object.values(value).some(Boolean)) error.value = '' })

async function loadStock() {
  const [p, h] = await Promise.all([
    http.get('/products', { params: { per_page: 200 } }).catch(() => null),
    http.get('/hubs').catch(() => null),
  ])
  products.value = p?.data.data || []
  hubs.value = h?.data.data || []
}

onMounted(async () => {
  loadDeliveryDay()
  loadRecentZones()
  if (!pasteOpen.value) phoneInput.value?.focus()
  zones.value = (await http.get('/zones')).data.data
  loadStock()
})
</script>
