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

  <!-- Une question par écran : le client, où, l'argent et le jour, puis le récapitulatif -->
  <form v-else class="pb-28" novalidate autocomplete="off" @submit.prevent="next">
    <div class="flex items-center gap-1 -mt-1 mb-4" :aria-label="`Étape ${step} sur 4`">
      <i v-for="n in 4" :key="n" :class="['block h-1 flex-1 rounded-full', n <= step ? 'bg-[var(--app-color)]' : 'bg-slate-200']" />
    </div>

    <div v-if="error" class="mb-3 rounded-xl bg-red-50 text-red-700 px-4 py-3 text-sm" role="alert">{{ error }}</div>
    <p v-if="fromMessage" class="mb-3 rounded-xl bg-amber-50 text-amber-900 px-4 py-3 text-sm">Course remplie d'après le message : vérifiez avant d'envoyer.</p>

    <!-- Message collé : remplit toute la course -->
    <PasteMessage v-if="pasteOpen" mobile start-open class="mb-4" @parsed="applyParsed" />

    <!-- 1. Le client -->
    <section v-show="step === 1" class="space-y-4">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="text-[24px] font-extrabold tracking-tight">À qui livrer ?</h2>
          <p class="text-sm text-slate-500">Le numéro du client</p>
        </div>
        <button v-if="!pasteOpen" type="button" class="tap shrink-0 flex items-center gap-1.5 rounded-full bg-white px-3 py-2 text-[13px] font-bold text-[var(--app-color)] ring-1 ring-slate-200" @click="pasteOpen = true">
          <AppIcon name="clip" class="h-4 w-4" />Coller un message
        </button>
      </div>
      <input id="phone" ref="phoneInput" :value="form.recipient_phone" :class="['m-input !text-2xl !font-bold !py-4', missing.phone ? 'ring-2 ring-red-500' : '']" inputmode="tel" autocomplete="jibiat-off" placeholder="07 00 00 00 00" aria-label="Téléphone du client" @input="onPhone" @blur="checkDuplicates">
      <!-- Clients déjà livrés : un toucher remplit le nom et l'adresse -->
      <button v-for="r in suggestions" :key="r.id" type="button" class="tap w-full flex items-center gap-3 rounded-2xl bg-white px-4 py-3 text-left ring-1 ring-slate-200 active:bg-emerald-50" @click="pickRecipient(r)">
        <span class="min-w-0 flex-1">
          <strong class="block truncate">{{ r.name || localPhone(r.phone) }}</strong>
          <span class="block truncate text-sm text-slate-500">{{ [zoneOf(r), r.deliveries_count ? `livré ${r.deliveries_count} fois` : 'déjà enregistré'].filter(Boolean).join(' · ') }}</span>
          <span v-if="r.failed_count" class="block text-xs text-red-600">{{ r.failed_count }} livraison(s) échouée(s)</span>
        </span>
        <span class="shrink-0 rounded-lg px-3 py-2 text-xs font-bold text-white bg-[var(--app-color)]">Choisir</span>
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
    <section v-show="step === 2" class="space-y-4">
      <div>
        <h2 class="text-[24px] font-extrabold tracking-tight">Où livrer ?</h2>
        <p class="text-sm text-slate-500">{{ form.delivery_zone_id ? 'Déjà rempli, modifiable' : 'La commune ou le quartier' }}</p>
      </div>
      <div id="zone-field" class="space-y-2">
        <!-- Communes proposées : celles du message, sinon celles des dernières courses -->
        <button
          v-for="z in quickZones"
          :key="z.id"
          type="button"
          :class="['tap w-full flex items-center justify-between rounded-2xl px-4 py-3 text-left font-semibold ring-1', form.delivery_zone_id === z.id ? 'ring-2 ring-[var(--app-color)] bg-emerald-50' : 'ring-slate-200 bg-white']"
          @click="form.delivery_zone_id = z.id"
        >{{ z.full_name }}<AppIcon v-if="form.delivery_zone_id === z.id" name="check" class="h-5 w-5 text-[var(--app-color)]" /></button>
        <ZoneSelect id="zone" v-model="form.delivery_zone_id" :zones="zones" mobile :input-class="['m-input', missing.zone ? 'ring-2 ring-red-500' : '']" />
        <p v-if="selectedZone?.is_shipping" class="rounded-xl bg-indigo-50 text-indigo-900 text-sm p-3">
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

    <!-- 3. L'argent et le jour -->
    <section v-show="step === 3" class="space-y-4">
      <div>
        <h2 class="text-[24px] font-extrabold tracking-tight">Combien encaisser ?</h2>
        <p class="text-sm text-slate-500">Prix des articles, sans la livraison. Laissez vide si le client a déjà payé.</p>
      </div>

      <button v-if="products.length && !showStock" type="button" class="tap w-full rounded-xl bg-white ring-1 ring-slate-200 px-4 py-3 flex items-center justify-between text-sm font-semibold active:bg-slate-50" @click="showStock = true">
        <span class="flex items-center gap-2"><AppIcon name="store" class="h-5 w-5" />Prendre des articles de mon stock</span><AppIcon name="right" class="h-4 w-4 text-slate-400" />
      </button>
      <div v-if="products.length && showStock" class="space-y-3">
        <div v-if="stockHubs.length">
          <p class="m-label">D'où part le colis ?</p>
          <ChoiceChips v-model="source" :options="sourceOptions" :columns="1" label="Provenance du colis" />
        </div>
        <div class="m-card divide-y">
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

      <div id="amount" class="relative">
        <input v-model.number="form.items_amount" type="number" min="0" step="50" inputmode="numeric" autocomplete="jibiat-off" class="m-input !text-2xl !font-bold !py-4 pr-12" placeholder="0" aria-label="Montant à encaisser chez le client">
        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">F</span>
      </div>

      <div id="fee-payer" :class="['rounded-2xl', missing.feePayer ? 'ring-2 ring-red-500 p-2 -mx-2' : '']">
        <p class="m-label">Qui paie la livraison ?</p>
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

      <div id="when-field" :class="['rounded-2xl', missing.date ? 'ring-2 ring-red-500 p-2 -mx-2' : '']">
        <p class="m-label">Quand livrer ?</p>
        <ChoiceChips v-model="when" :options="deliveryOptions" :columns="3" label="Jour de livraison" />
        <p v-if="closed" class="mt-2 text-xs text-slate-500">{{ closedReason }} : le ramassage peut quand même se faire aujourd'hui.</p>
        <input v-if="when === 'other'" id="date" v-model="form.delivery_scheduled_date" type="date" :min="minDate" class="m-input mt-2" aria-label="Jour de livraison">
        <p v-if="missing.date" class="mt-2 text-sm font-medium text-red-600" role="alert">Choisissez quand livrer la course.</p>
      </div>
    </section>

    <!-- 4. Le récapitulatif -->
    <section v-show="step === 4" class="space-y-4">
      <h2 class="text-[24px] font-extrabold tracking-tight">Tout est bon ?</h2>
      <div class="m-card divide-y divide-slate-100 px-4">
        <button v-for="row in recap" :key="row.label" type="button" class="tap w-full flex items-center justify-between gap-3 py-3 text-left text-sm" @click="goTo(row.step)">
          <span class="text-slate-500">{{ row.label }}</span>
          <span class="flex items-center gap-1.5 text-right font-bold">{{ row.value }}<AppIcon name="edit" class="h-3.5 w-3.5 text-slate-400" /></span>
        </button>
        <div v-if="quote" class="py-3 space-y-1.5 text-sm">
          <div class="flex justify-between"><span class="text-slate-500">Livraison {{ form.fee_payer === 'merchant' ? '(à ma charge)' : '(payée par le client)' }}</span><b>{{ money(quote.total) }}</b></div>
          <div v-if="quote.is_shipping" class="flex justify-between"><span class="text-slate-500">Expédition (au réel)</span><b>≈ {{ money(shippingEstimate) }}</b></div>
          <div class="flex justify-between"><span class="text-slate-500">Le livreur encaisse</span><b>{{ money(codAmount) }}</b></div>
          <div class="flex justify-between items-baseline pt-1">
            <span class="font-bold">{{ merchantNet < 0 ? 'À votre charge' : 'Pour vous' }}</span>
            <span :class="['text-lg font-extrabold', merchantNet < 0 ? 'text-slate-700' : 'text-emerald-700']">{{ quote.is_shipping ? '≈ ' : '' }}{{ money(Math.abs(merchantNet)) }}</span>
          </div>
        </div>
        <p v-else-if="quoteError" class="py-3 text-sm text-red-600">{{ quoteError }}</p>
      </div>

      <!-- Le reste, seulement si besoin -->
      <button type="button" class="tap text-sm font-bold text-[var(--app-color)]" :aria-expanded="showMore" @click="showMore = !showMore">
        {{ showMore ? '− Masquer les options' : '+ Contenu, express, message au livreur' }}
        <span v-if="!showMore && extrasSummary" class="block font-normal text-slate-500">{{ extrasSummary }}</span>
      </button>
      <div v-show="showMore" class="space-y-4">
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

    <!-- Retour et suite, toujours en bas -->
    <div class="fixed bottom-0 inset-x-0 z-20 bg-white/95 backdrop-blur border-t border-slate-200 pb-safe">
      <div class="mx-auto max-w-lg px-4 py-3 flex gap-2">
        <button v-if="step > 1" type="button" class="m-btn-secondary !w-auto px-5" @click="step--">Retour</button>
        <button type="submit" class="m-btn-primary flex-1" :disabled="saving">
          {{ step < 4 ? (step === 3 ? 'Voir le récapitulatif' : 'Continuer') : saving ? 'Envoi…' : 'Envoyer la course' }}
        </button>
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
  goTo(2)
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
  // Tout ce qui manque est demandé étape par étape ; sinon, directement le récapitulatif
  goTo([1, 2, 3].find((n) => problemsOf(n).some(([bad]) => bad)) || 4)
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

// Une question par écran
const step = ref(1)
const pasteOpen = ref(route.query.coller === '1')
const phoneInput = ref(null)

function goTo(n) {
  error.value = ''
  step.value = n
  window.scrollTo({ top: 0 })
}

function next() {
  if (step.value < 4) {
    if (!validate(step.value)) return
    suggestions.value = []
    if (step.value === 1) checkDuplicates()
    goTo(step.value + 1)
    return
  }
  submit()
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

// Récapitulatif : chaque ligne ramène à son étape
const recap = computed(() => [
  { label: 'Client', value: form.recipient_name || form.recipient_phone, step: 1 },
  { label: 'Où', value: [zoneName.value, form.delivery_address].filter((v) => v && v !== '—').join(' · '), step: 2 },
  { label: 'Quand', value: form.delivery_scheduled_date ? longDay(form.delivery_scheduled_date) : '—', step: 3 },
  { label: 'Prix des articles', value: money(form.items_amount || 0), step: 3 },
])

async function submit() {
  error.value = ''
  suggestions.value = []
  // Toutes les étapes revérifiées : la première incomplète est rouverte
  const bad = [1, 2, 3].find((n) => !validate(n))
  if (bad) {
    step.value = bad
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
    // Retour à l'étape du champ refusé
    step.value = field.startsWith('recipient') ? 1 : field.startsWith('delivery_zone') || field === 'delivery_address' ? 2 : ['fee_payer', 'delivery_scheduled_date', 'items_amount', 'items'].some((f) => field.startsWith(f)) ? 3 : 4
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
  step.value = 1
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
