<template>
  <!-- Course enregistrée -->
  <div v-if="created" class="text-center space-y-5 pt-6">
    <div class="flex justify-center"><ColorIcon name="livre" :size="88" /></div>
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
    <!-- Enchaîner : même client avec un autre colis, ou un autre client (réglages gardés) -->
    <div class="grid grid-cols-2 gap-2">
      <button class="m-btn-secondary" @click="reset(true)">Même client, autre colis</button>
      <button class="m-btn-primary" @click="reset(false)">Autre client</button>
    </div>
    <RouterLink :to="`/etiquette/${created.id}`" class="m-btn-secondary !justify-start !ring-0 shadow-soft"><ColorIcon name="etiquette" :size="32" :tile="false" />Imprimer l'étiquette du colis</RouterLink>
    <RouterLink :to="`/marchand/courses/${created.id}`" class="block text-sm font-bold text-[var(--app-color)]">Voir la course</RouterLink>
  </div>

  <!-- Toute la course sur une page : le client, où livrer, l'argent, le jour ; le prix suit en bas -->
  <form v-else class="space-y-4 pb-44" :style="lifted ? { paddingBottom: '100vh' } : {}" novalidate autocomplete="off" @submit.prevent="submit" @focusin="liftField" @focusout="lifted = false">
    <div v-if="error" class="rounded-xl bg-red-50 text-red-700 px-4 py-3 text-sm" role="alert">{{ error }}</div>
    <p v-if="fromMessage" class="rounded-xl bg-amber-50 text-amber-900 px-4 py-3 text-sm">Course remplie d'après le message : vérifiez avant d'envoyer.</p>

    <!-- Message copié avec un numéro : la course peut se remplir toute seule -->
    <div v-if="clipText && !fromMessage" class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200 text-sm">
      <p class="font-bold">Un message copié contient un numéro</p>
      <p class="mt-0.5 line-clamp-2 text-slate-600">« {{ clipText }} »</p>
      <div class="mt-3 flex gap-2">
        <button type="button" class="rounded-lg bg-[var(--app-color)] px-3 py-2 text-[13px] font-bold text-white" :disabled="parsing" @click="parseText(clipText)">{{ parsing ? 'Analyse…' : 'Remplir la course avec' }}</button>
        <button type="button" class="rounded-lg px-3 py-2 text-[13px] font-bold ring-1 ring-slate-300" @click="clipText = ''">Non merci</button>
      </div>
    </div>

    <!-- Message collé : remplit toute la course -->
    <PasteMessage v-if="pasteOpen" mobile start-open @parsed="applyParsed" />
    <button v-else-if="!clipText" type="button" class="tap w-full flex items-center gap-3 rounded-[22px] border-2 border-dashed border-[#A8E6C9] bg-[#F1FBF6] p-3.5 text-left active:scale-[.99] transition" :disabled="parsing" @click="pasteFromClipboard">
      <ColorIcon name="coller" :size="46" />
      <span class="min-w-0"><span class="block text-[15px] font-bold text-slate-900">{{ parsing ? 'Analyse du message…' : 'Coller le message du client' }}</span><span class="text-[12.5px] text-slate-600">Jibiat remplit tout seul : numéro, commune, montant.</span></span>
    </button>

    <!-- 1. Le client -->
    <section class="rounded-[22px] bg-white p-4 shadow-soft space-y-3" aria-labelledby="step-client" @pointerdown="enter(1)">
      <h2 id="step-client" class="m-step"><ColorIcon name="profil" :size="34" />Le client</h2>
      <!-- Partie remplie : une ligne, rouverte d'un toucher -->
      <button v-if="folded.client" type="button" class="tap w-full flex items-center justify-between gap-3 text-left text-sm" @click="folded.client = false">
        <span class="min-w-0 truncate font-semibold">{{ [form.recipient_name, form.recipient_phone].filter(Boolean).join(' · ') }}</span>
        <span class="shrink-0 font-bold text-[var(--app-color)]">Modifier</span>
      </button>
      <template v-else>
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
      <div>
        <label class="m-label" for="name">Nom <span class="font-normal text-slate-500">(facultatif)</span></label>
        <input id="name" v-model="form.recipient_name" class="m-input" autocomplete="jibiat-off" placeholder="Ex. : Jean Kouadio">
      </div>
      </template>
      <div v-if="duplicates.length" class="rounded-xl bg-amber-50 text-amber-900 px-4 py-3 text-sm">
        Déjà {{ duplicates.length > 1 ? `${duplicates.length} courses` : 'une course' }} pour ce client ces dernières 24 h :
        <RouterLink v-for="d in duplicates" :key="d.id" :to="`/marchand/courses/${d.id}`" class="underline ml-1">{{ d.tracking_code }} ({{ d.status_label }})</RouterLink>
      </div>
    </section>

    <!-- 2. Où livrer -->
    <section class="rounded-[22px] bg-white p-4 shadow-soft space-y-3" aria-labelledby="step-where" @pointerdown="enter(2)">
      <h2 id="step-where" class="m-step"><ColorIcon name="lieu" :size="34" />Où livrer</h2>
      <button v-if="folded.where" type="button" class="tap w-full flex items-center justify-between gap-3 text-left text-sm" @click="folded.where = false">
        <span class="min-w-0 truncate font-semibold">{{ [zoneName, form.delivery_address].filter((v) => v && v !== '—').join(' · ') }}<span v-if="quote" class="font-normal text-slate-500"> · livraison {{ money(quote.total) }}</span></span>
        <span class="shrink-0 font-bold text-[var(--app-color)]">Modifier</span>
      </button>
      <template v-else>
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
        <!-- Le tarif dès que la commune est choisie -->
        <p v-if="quote" class="mt-2 inline-flex rounded-full bg-emerald-50 px-3 py-1 text-sm font-bold text-emerald-800">Livraison : {{ money(quote.total) }}{{ quote.is_shipping ? ` + expédition ≈ ${money(shippingEstimate)}` : '' }}</p>
        <p v-else-if="form.delivery_zone_id && !quoteError" class="mt-2 text-sm text-slate-500">Calcul du prix…</p>
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
      <!-- Position du client demandée sur WhatsApp, pour aider le livreur -->
      <a v-if="phoneValid" :href="positionLink" target="_blank" rel="noopener" class="tap flex items-center gap-2 rounded-xl bg-green-50 px-3 py-2.5 text-sm font-bold text-green-800 ring-1 ring-green-200">
        <AppIcon name="pin" class="h-[18px] w-[18px] shrink-0" />
        <span>Demander sa position au client sur WhatsApp<span class="block text-xs font-normal text-green-900/80">Collez ensuite le lien reçu dans « Adresse et repère ».</span></span>
      </a>
      </template>
    </section>

    <!-- 3. L'argent -->
    <section class="rounded-[22px] bg-white p-4 shadow-soft space-y-3" aria-labelledby="step-money" @pointerdown="enter(3)">
      <h2 id="step-money" class="m-step"><ColorIcon name="billets" :size="34" />L'argent</h2>
      <button v-if="folded.money" type="button" class="tap w-full flex items-center justify-between gap-3 text-left text-sm" @click="folded.money = false">
        <span class="min-w-0 truncate font-semibold">{{ money(form.items_amount || 0) }} · livraison payée par {{ form.fee_payer === 'merchant' ? 'vous' : 'le client' }}</span>
        <span class="shrink-0 font-bold text-[var(--app-color)]">Modifier</span>
      </button>
      <template v-else>

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
        <p v-if="habitsUsed.feePayer && !missing.feePayer" class="mt-1.5 text-xs text-slate-500">Comme la dernière fois.</p>
        <p v-if="missing.feePayer" class="mt-2 text-sm font-medium text-red-600" role="alert">Choisissez qui paie la livraison.</p>
      </div>
      </template>
    </section>

    <!-- 4. Quand livrer -->
    <section class="rounded-[22px] bg-white p-4 shadow-soft space-y-3" aria-labelledby="step-when" @pointerdown="enter(4)">
      <h2 id="step-when" class="m-step"><ColorIcon name="calendrier" :size="34" /><span>Quand livrer <span class="text-red-600">*</span></span></h2>
      <div id="when-field" :class="['rounded-2xl', missing.date ? 'ring-2 ring-red-500 p-2 -mx-2' : '']">
        <ChoiceChips v-model="when" :options="deliveryOptions" :columns="3" label="Jour de livraison" />
        <p v-if="closed" class="mt-2 text-xs text-slate-500">{{ closedReason }} : le ramassage peut quand même se faire aujourd'hui.</p>
        <input v-if="when === 'other'" id="date" v-model="form.delivery_scheduled_date" type="date" :min="minDate" class="m-input mt-2" aria-label="Jour de livraison">
        <p v-if="habitsUsed.when && !missing.date" class="mt-1.5 text-xs text-slate-500">Comme la dernière fois.</p>
        <p v-if="missing.date" class="mt-2 text-sm font-medium text-red-600" role="alert">Choisissez quand livrer la course.</p>
      </div>
    </section>

    <!-- Le reste, seulement si besoin -->
    <section class="rounded-[22px] bg-white px-4 py-1 shadow-soft" @pointerdown="enter(5)">
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
        <!-- Ce qui manque : seulement après un premier essai d'envoi -->
        <p v-if="tried && stillMissing.length" class="text-sm font-semibold text-amber-700">Il manque : {{ stillMissing.join(', ') }}</p>
        <button type="submit" :class="['m-btn-primary', tried && stillMissing.length ? 'opacity-60' : '']" :disabled="saving">{{ saving ? 'Envoi…' : 'Envoyer la course' }}</button>
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
import { isoDay, longDay, useDeliveryDate } from '../../composables/useDeliveryDate'
import { useAuthStore } from '../../stores/auth'
import { localPhone, money } from '../../utils/format'
import { keyboardOpen } from '../../composables/useKeyboard'
import AppIcon from '../../components/mobile/AppIcon.vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
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
const { day, when, options: deliveryOptions, closed, closedReason, minDate, isChosen: dateChosen, load: loadDeliveryDay, reset: resetDate } = useDeliveryDate(form)

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
  folded.client = true
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
  set('recipient_phone', (data.recipient_phone || '').replace(/^\+225/, '').replace(/\D/g, '').replace(/(\d{2})(?=\d)/g, '$1 '))
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

// Ce qui manque encore, rappelé en bas de la page
const SHORT = { phone: 'le numéro du client', zone: 'la commune', feePayer: 'qui paie la livraison', date: 'le jour de livraison' }
const phoneValid = computed(() => form.recipient_phone.replace(/\D/g, '').length >= 8)
const stillMissing = computed(() => [1, 2, 3]
  .flatMap((n) => problemsOf(n))
  .filter(([bad]) => bad)
  .map(([, key]) => SHORT[key] || 'les articles de l\'entrepôt'))

// Parties remplies repliées en une ligne quand on passe à la suite
const folded = reactive({ client: false, where: false, money: false })
const PARTS = [['client', 1], ['where', 2], ['money', 3]]
function enter(n) {
  PARTS.forEach(([key, i]) => {
    if (i < n && !problemsOf(i).some(([bad]) => bad)) folded[key] = true
  })
}

// Habitudes : qui paie et quand livrer, repris de la dernière course (modifiables)
const HABITS = 'jibiat.marchand.habitudes'
const habitsUsed = reactive({ feePayer: false, when: false })
function readHabits() {
  try {
    return JSON.parse(localStorage.getItem(HABITS) || '{}')
  } catch {
    return {}
  }
}
function saveHabits() {
  try {
    localStorage.setItem(HABITS, JSON.stringify({ fee_payer: form.fee_payer, when: ['today', 'tomorrow'].includes(when.value) ? when.value : null }))
  } catch {
    // Navigation privée : rien n'est gardé
  }
}
function applyHabits() {
  const h = readHabits()
  if (!form.fee_payer && ['recipient', 'merchant'].includes(h.fee_payer)) {
    form.fee_payer = h.fee_payer
    habitsUsed.feePayer = true
  }
  const option = deliveryOptions.value.find((o) => o.value === h.when)
  if (!when.value && option && !option.disabled) {
    when.value = h.when
    // Date posée tout de suite (le choix peut revenir à la même valeur juste après une remise à zéro)
    const today = day.value?.today || isoDay(0)
    form.delivery_scheduled_date = h.when === 'today' ? today : isoDay(1, today)
    habitsUsed.when = true
  }
}
watch(() => form.fee_payer, (v, old) => { if (old) habitsUsed.feePayer = false })
watch(when, (v, old) => { if (old) habitsUsed.when = false })

// Numéro pour WhatsApp : « 07 00 00 00 01 » → « 2250700000001 » (le 0 reste en Côte d'Ivoire)
const whatsappNumber = (phone) => {
  const digits = (phone || '').replace(/\D/g, '')
  return digits.length === 10 ? `225${digits}` : digits
}

// Position du client demandée sur WhatsApp
const positionLink = computed(() => {
  const shop = auth.user?.merchant?.business_name || ''
  const hello = form.recipient_name ? `Bonjour ${form.recipient_name.split(' ')[0]}` : 'Bonjour'
  const text = `${hello}, c'est ${shop}. Pour bien vous livrer, pouvez-vous m'envoyer votre position WhatsApp (trombone, puis « Position ») ? Merci !`
  return `https://wa.me/${whatsappNumber(form.recipient_phone)}?text=${encodeURIComponent(text)}`
})

// Message copié : lu dans le presse-papiers puis analysé, sinon zone de collage
const clipText = ref('')
const parsing = ref(false)
const PHONE_RE = /(\+?\d[\d\s.-]{7,}\d)/

async function parseText(text) {
  parsing.value = true
  error.value = ''
  try {
    const { data } = await http.post('/orders/parse-message', { text })
    const found = Object.keys(data.data).filter((k) => k !== 'zones' || data.data.zones.length)
    if (!found.length) {
      error.value = 'Rien de reconnu dans ce message : remplissez la course à la main.'
      return
    }
    applyParsed(data.data)
    clipText.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    parsing.value = false
  }
}

async function readClipboard() {
  try {
    return ((await navigator.clipboard.readText()) || '').trim()
  } catch {
    return ''
  }
}

// Bouton « Coller » : le message copié est analysé directement, sinon la zone de collage s'ouvre
async function pasteFromClipboard() {
  const text = await readClipboard()
  if (text) await parseText(text)
  else pasteOpen.value = true
}

// À l'ouverture : message copié avec un numéro proposé, seulement si le téléphone l'autorise déjà sans demander
async function offerClipboard() {
  try {
    const status = await navigator.permissions?.query({ name: 'clipboard-read' })
    if (status?.state !== 'granted') return
    const text = await readClipboard()
    if (PHONE_RE.test(text)) clipText.value = text.slice(0, 500)
  } catch {
    // Navigateur sans cette autorisation : le bouton « Coller » reste disponible
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
// Curseur placé tout seul à l'ouverture : la page reste en haut (bouton « Coller », message copié)
let quietFocus = false
function liftField(event) {
  const field = event.target
  if (quietFocus) {
    quietFocus = false
    return
  }
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

// Premier essai d'envoi fait : ce qui manque peut s'afficher
const tried = ref(false)

async function submit() {
  tried.value = true
  error.value = ''
  suggestions.value = []
  // Champs obligatoires : le premier oublié est montré à l'écran
  const bad = [1, 2, 3].find((n) => !validate(n))
  if (bad) {
    folded[PARTS.find(([, i]) => i === bad)?.[0]] = false
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
    saveHabits()
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

// Après l'envoi : même client avec un autre colis, ou un autre client ; les habitudes reviennent
function reset(sameClient = false) {
  const keep = sameClient
    ? { recipient_phone: form.recipient_phone, recipient_name: form.recipient_name, recipient_phone2: form.recipient_phone2, delivery_zone_id: form.delivery_zone_id, delivery_address: form.delivery_address }
    : {}
  Object.assign(form, blank(), keep)
  Object.keys(missing).forEach((key) => { missing[key] = false })
  tried.value = false
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
  clipText.value = ''
  Object.assign(folded, { client: sameClient, where: sameClient, money: false })
  Object.assign(habitsUsed, { feePayer: false, when: false })
  applyHabits()
  if (sameClient) fetchQuote()
  window.scrollTo({ top: 0 })
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
  loadDeliveryDay().then(applyHabits)
  loadRecentZones()
  // « Coller un message » du menu : le message copié est analysé tout de suite s'il y en a un
  if (pasteOpen.value) {
    const text = await readClipboard()
    if (text) {
      pasteOpen.value = false
      parseText(text)
    }
  } else {
    quietFocus = true
    phoneInput.value?.focus()
    offerClipboard()
  }
  zones.value = (await http.get('/zones')).data.data
  loadStock()
})
</script>
