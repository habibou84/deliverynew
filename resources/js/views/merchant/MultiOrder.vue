<template>
  <!-- Courses envoyées : le bilan -->
  <div v-if="done" class="space-y-4">
    <div class="text-center pt-4">
      <div class="mx-auto h-16 w-16 rounded-full bg-emerald-100 grid place-items-center text-emerald-700"><AppIcon name="check" class="h-8 w-8" :stroke="2.4" /></div>
      <p class="mt-3 text-2xl font-bold">{{ sentRows.length }} course{{ sentRows.length > 1 ? 's' : '' }} envoyée{{ sentRows.length > 1 ? 's' : '' }}</p>
      <p v-if="failedRows.length" class="text-sm font-semibold text-red-600">{{ failedRows.length }} n'ont pas pu partir : corrigez-les et renvoyez.</p>
    </div>
    <div class="rounded-2xl bg-white ring-1 ring-slate-200 divide-y divide-slate-100">
      <div v-for="r in rows" :key="r.key" class="flex items-center gap-3 px-4 py-3 text-sm">
        <span class="min-w-0 flex-1"><b class="block truncate">{{ r.recipient_name || r.recipient_phone }}</b><span class="text-slate-500">{{ zoneName(r.delivery_zone_id) }} · {{ money(r.items_amount || 0) }}</span></span>
        <RouterLink v-if="r.order" :to="`/marchand/courses/${r.order.id}`" class="shrink-0 font-mono text-xs font-bold text-[var(--app-color)]">{{ r.order.tracking_code }}</RouterLink>
        <span v-else class="shrink-0 text-xs font-semibold text-red-600">{{ r.error || 'Non envoyée' }}</span>
      </div>
    </div>
    <button v-if="failedRows.length" class="m-btn-secondary" @click="retry">Corriger celles qui restent</button>
    <div class="grid grid-cols-2 gap-2">
      <RouterLink to="/marchand/courses" class="m-btn-secondary">Voir mes courses</RouterLink>
      <button class="m-btn-primary" @click="restart">Nouvelle série</button>
    </div>
  </div>

  <div v-else class="space-y-4 pb-44">
    <div v-if="error" class="rounded-xl bg-red-50 text-red-700 px-4 py-3 text-sm" role="alert">{{ error }}</div>

    <!-- Réglages communs, choisis une fois -->
    <section class="rounded-2xl bg-white p-4 ring-1 ring-slate-200 space-y-3">
      <h2 class="font-bold">Pour toutes ces courses</h2>
      <div>
        <p class="m-label">Qui paie la livraison ?</p>
        <ChoiceChips
          v-model="common.fee_payer"
          :columns="2"
          label="Payeur des frais"
          :options="[
            { value: 'recipient', label: 'Le client', description: 'ajoutée au montant' },
            { value: 'merchant', label: 'Moi', description: 'déduite de mon paiement' },
          ]"
        />
      </div>
      <div>
        <p class="m-label">Quand livrer ?</p>
        <ChoiceChips v-model="when" :options="deliveryOptions" :columns="3" label="Jour de livraison" />
        <p v-if="closed" class="mt-2 text-xs text-slate-500">{{ closedReason }}.</p>
        <input v-if="when === 'other'" v-model="common.delivery_scheduled_date" type="date" :min="minDate" class="m-input mt-2" aria-label="Jour de livraison">
      </div>
    </section>

    <!-- Coller une liste : une ligne ou un paragraphe par client -->
    <section class="rounded-2xl bg-white p-4 ring-1 ring-slate-200 space-y-3">
      <button v-if="!pasteOpen" type="button" class="tap w-full flex items-center justify-center gap-2 text-sm font-bold text-[var(--app-color)]" @click="openPaste">
        <AppIcon name="clip" class="h-[18px] w-[18px]" />Coller une liste de commandes
      </button>
      <template v-else>
        <label class="m-label" for="list">Collez la liste (une ligne ou un paragraphe par client)</label>
        <textarea id="list" ref="listArea" v-model="listText" rows="6" class="m-input" placeholder="Aya 07 01 23 45 67 Abobo 15000&#10;Jean 05 44 55 66 77 Cocody Riviera 9000&#10;Fatou 01 22 33 44 55 Yopougon 22500" />
        <p v-if="parseProgress" class="text-sm text-slate-500">Analyse… {{ parseProgress }}</p>
        <div class="flex gap-2">
          <button type="button" class="m-btn-primary !py-3" :disabled="parsing || !listText.trim()" @click="parseList">{{ parsing ? 'Analyse…' : 'Analyser la liste' }}</button>
          <button type="button" class="m-btn-secondary !py-3 !w-auto px-4" @click="pasteOpen = false">Fermer</button>
        </div>
      </template>
      <p v-if="parsedInfo" class="text-sm font-semibold text-emerald-700">{{ parsedInfo }}</p>
    </section>

    <!-- Les courses de la série -->
    <div class="flex items-center justify-between">
      <h2 class="text-[13px] font-extrabold uppercase tracking-wider text-slate-500">{{ rows.length }} course{{ rows.length > 1 ? 's' : '' }}</h2>
      <span v-if="incomplete.length" class="text-xs font-bold text-orange-700">{{ incomplete.length }} à compléter</span>
    </div>
    <div v-if="rows.length" class="rounded-2xl bg-white ring-1 ring-slate-200 divide-y divide-slate-100">
      <div v-for="(r, i) in rows" :key="r.key" class="flex items-center gap-3 px-3 py-3">
        <span :class="['h-6 w-6 shrink-0 rounded-full grid place-items-center text-xs font-extrabold text-white', isComplete(r) ? 'bg-[var(--app-color)]' : 'bg-orange-500']">{{ i + 1 }}</span>
        <button type="button" class="min-w-0 flex-1 text-left text-sm" @click="edit(r)">
          <b class="block truncate">{{ r.recipient_name || r.recipient_phone || 'Client sans numéro' }}<span v-if="r.recipient_name" class="font-normal text-slate-500"> · {{ r.recipient_phone }}</span></b>
          <span class="block truncate text-slate-500">
            {{ r.delivery_zone_id ? zoneName(r.delivery_zone_id) : 'commune ?' }} · {{ money(r.items_amount || 0) }}<template v-if="r.fee_payer && r.fee_payer !== common.fee_payer"> · livraison payée par {{ r.fee_payer === 'merchant' ? 'vous' : 'le client' }}</template>
          </span>
          <span v-if="!isComplete(r)" class="block text-xs font-bold text-orange-700">À compléter : {{ missingOf(r).join(', ') }}</span>
          <span v-else-if="r.error" class="block text-xs font-bold text-red-600">{{ r.error }}</span>
        </button>
        <button type="button" class="tap h-9 w-9 shrink-0 grid place-items-center rounded-full text-slate-400 active:bg-slate-100" :aria-label="`Retirer la course ${i + 1}`" @click="remove(r)"><AppIcon name="x" class="h-4 w-4" /></button>
      </div>
    </div>
    <p v-else class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">Ajoutez vos clients un par un, ou collez une liste de commandes.</p>
    <button type="button" class="m-btn-secondary !border-dashed" @click="edit(null)"><AppIcon name="plus" class="h-5 w-5" />Ajouter un client</button>

    <!-- Totaux et envoi -->
    <div class="fixed bottom-0 inset-x-0 z-20 bg-white/95 backdrop-blur border-t border-slate-200 pb-safe">
      <div class="mx-auto max-w-lg px-4 py-3 space-y-2">
        <div v-if="ready.length" class="flex justify-between text-sm">
          <span class="text-slate-500">Livraisons {{ money(feesTotal) }}</span>
          <b :class="netTotal < 0 ? 'text-slate-700' : 'text-emerald-700'">{{ netTotal < 0 ? 'À votre charge' : 'Pour vous' }} {{ money(Math.abs(netTotal)) }}</b>
        </div>
        <p v-if="!common.fee_payer || !dateChosen" class="text-sm font-semibold text-amber-700">Il manque : {{ [!common.fee_payer && 'qui paie la livraison', !dateChosen && 'le jour de livraison'].filter(Boolean).join(', ') }}</p>
        <button type="button" class="m-btn-primary" :disabled="sending || !ready.length || !common.fee_payer || !dateChosen" @click="sendAll">
          {{ sending ? `Envoi… ${sentCount}/${ready.length}` : ready.length ? `Envoyer ${ready.length > 1 ? `les ${ready.length} courses` : 'la course'}` : 'Ajoutez au moins un client' }}
        </button>
      </div>
    </div>

    <!-- Fiche d'un client -->
    <BottomSheet :open="!!draft" :title="draftIsNew ? 'Nouveau client' : 'Modifier le client'" @close="draft = null">
      <form v-if="draft" class="space-y-3" @submit.prevent="saveDraft(false)">
        <div>
          <label class="m-label" for="d-phone">Téléphone</label>
          <input id="d-phone" ref="phoneInput" :value="draft.recipient_phone" class="m-input !text-lg !font-bold" inputmode="tel" placeholder="07 00 00 00 00" autocomplete="off" @input="onPhone">
        </div>
        <button v-for="s in suggestions" :key="s.id" type="button" class="tap w-full flex items-center gap-3 rounded-xl bg-emerald-50 px-3 py-2 text-left text-sm ring-1 ring-emerald-200" @click="useRecipient(s)">
          <span class="min-w-0 flex-1"><b class="block truncate">{{ s.name || s.phone }}</b><span class="text-slate-600">{{ zoneName(s.zone_id) }}</span></span>
          <span class="shrink-0 rounded-lg bg-[var(--app-color)] px-3 py-1.5 text-xs font-bold text-white">Utiliser</span>
        </button>
        <div>
          <label class="m-label" for="d-name">Nom <span class="font-normal text-slate-500">(facultatif)</span></label>
          <input id="d-name" v-model="draft.recipient_name" class="m-input" autocomplete="off" placeholder="Ex. : Jean Kouadio">
        </div>
        <div>
          <label class="m-label" for="d-zone">Commune ou quartier</label>
          <div v-if="quickZones.length" class="mb-2 flex flex-wrap gap-1.5">
            <button v-for="z in quickZones" :key="z.id" type="button" :class="['tap rounded-full px-3 py-1.5 text-sm font-semibold ring-1', draft.delivery_zone_id === z.id ? 'bg-[var(--app-color)] text-white ring-transparent' : 'bg-white ring-slate-300']" @click="draft.delivery_zone_id = z.id">{{ z.full_name }}</button>
          </div>
          <ZoneSelect id="d-zone" v-model="draft.delivery_zone_id" :zones="zones" mobile input-class="m-input" />
          <p v-if="draft.delivery_zone_id && quotes[draft.delivery_zone_id] !== undefined" class="mt-1.5 text-sm font-bold text-emerald-700">Livraison : {{ money(quotes[draft.delivery_zone_id]) }}</p>
        </div>
        <div>
          <label class="m-label" for="d-address">Adresse et repère</label>
          <input id="d-address" v-model="draft.delivery_address" class="m-input" autocomplete="off" placeholder="Ex. : face pharmacie">
        </div>
        <div>
          <label class="m-label" for="d-amount">Montant à encaisser</label>
          <input id="d-amount" v-model.number="draft.items_amount" type="number" min="0" step="50" inputmode="numeric" class="m-input !text-lg !font-bold" placeholder="0">
        </div>
        <div class="grid gap-2" :class="draftIsNew ? 'grid-cols-2' : 'grid-cols-1'">
          <button v-if="draftIsNew" type="button" class="m-btn-secondary !py-3" @click="saveDraft(true)">Ajouter et suivant</button>
          <button class="m-btn-primary !py-3">{{ draftIsNew ? 'Ajouter' : 'Enregistrer' }}</button>
        </div>
      </form>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import AppIcon from '../../components/mobile/AppIcon.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import ZoneSelect from '../../components/ZoneSelect.vue'
import { useDeliveryDate } from '../../composables/useDeliveryDate'
import { money } from '../../utils/format'

/**
 * Plusieurs courses d'un coup : réglages communs (qui paie, quand), puis les clients ajoutés à la chaîne
 * ou tirés d'une liste collée. Chaque course est créée par l'API habituelle, une par une.
 */
const common = reactive({ fee_payer: null, delivery_scheduled_date: null })
const { when, options: deliveryOptions, closed, closedReason, minDate, isChosen: dateChosen, load: loadDeliveryDay } = useDeliveryDate(common)

const zones = ref([])
const rows = ref([])
const quotes = reactive({})
const error = ref('')
let nextKey = 1

const blankRow = () => ({ key: nextKey++, recipient_phone: '', recipient_name: '', delivery_zone_id: null, delivery_address: '', items_amount: null, fee_payer: null, description: '', merchant_note: '', recipient_phone2: '', order: null, error: '' })
const pairs = (phone) => (phone || '').replace(/^\+225/, '').replace(/\D/g, '').replace(/(\d{2})(?=\d)/g, '$1 ')
const zoneName = (id) => zones.value.find((z) => z.id === id)?.full_name || ''

const missingOf = (r) => [r.recipient_phone.replace(/\D/g, '').length < 8 && 'le numéro', !r.delivery_zone_id && 'la commune'].filter(Boolean)
const isComplete = (r) => !missingOf(r).length
const incomplete = computed(() => rows.value.filter((r) => !isComplete(r)))
const ready = computed(() => rows.value.filter((r) => isComplete(r) && !r.order))
const feePayerOf = (r) => r.fee_payer || common.fee_payer
const feesTotal = computed(() => ready.value.reduce((sum, r) => sum + (quotes[r.delivery_zone_id] || 0), 0))
const netTotal = computed(() => ready.value.reduce((sum, r) => sum + (r.items_amount || 0) - (feePayerOf(r) === 'merchant' ? (quotes[r.delivery_zone_id] || 0) : 0), 0))

// Tarif de chaque commune, demandé une fois
async function quote(zoneId) {
  if (!zoneId || quotes[zoneId] !== undefined) return
  quotes[zoneId] = null
  try {
    quotes[zoneId] = (await http.post('/quotes', { delivery_zone_id: zoneId })).data.data.total
  } catch {
    delete quotes[zoneId]
  }
}
watch(() => rows.value.map((r) => r.delivery_zone_id), (ids) => ids.forEach(quote), { deep: true })

/* ---------- Fiche d'un client ---------- */
const draft = ref(null)
const draftIsNew = ref(false)
const phoneInput = ref(null)
const suggestions = ref([])
const recentZones = ref([])
const quickZones = computed(() => recentZones.value.map((id) => zones.value.find((z) => z.id === id)).filter(Boolean).slice(0, 4))
watch(() => draft.value?.delivery_zone_id, quote)

async function edit(row) {
  draftIsNew.value = !row
  draft.value = row ? { ...row } : blankRow()
  suggestions.value = []
  await nextTick()
  setTimeout(() => phoneInput.value?.focus(), 250)
}

function saveDraft(another) {
  const d = { ...draft.value, error: '' }
  const i = rows.value.findIndex((r) => r.key === d.key)
  if (i >= 0) rows.value[i] = d
  else if (d.recipient_phone || d.delivery_zone_id) rows.value.push(d)
  if (another) edit(null)
  else draft.value = null
}

function remove(row) {
  rows.value = rows.value.filter((r) => r.key !== row.key)
}

let searchTimer
function onPhone(event) {
  const formatted = event.target.value.trim().startsWith('+') ? event.target.value : pairs(event.target.value)
  draft.value.recipient_phone = formatted
  event.target.value = formatted
  clearTimeout(searchTimer)
  const term = formatted.replace(/\D/g, '')
  if (term.length < 4) {
    suggestions.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    try {
      suggestions.value = (await http.get('/recipients', { params: { search: term.slice(-8) } })).data.data.slice(0, 3)
    } catch {
      suggestions.value = []
    }
  }, 250)
}

function useRecipient(r) {
  Object.assign(draft.value, {
    recipient_phone: pairs(r.phone),
    recipient_name: r.name || draft.value.recipient_name,
    delivery_zone_id: r.zone_id || draft.value.delivery_zone_id,
    delivery_address: [r.address, r.landmark].filter(Boolean).join(', ') || draft.value.delivery_address,
  })
  suggestions.value = []
}

/* ---------- Coller une liste ---------- */
const pasteOpen = ref(false)
const listText = ref('')
const listArea = ref(null)
const parsing = ref(false)
const parseProgress = ref('')
const parsedInfo = ref('')

async function openPaste() {
  pasteOpen.value = true
  await nextTick()
  listArea.value?.focus()
}

// Un paragraphe par client s'il y a des lignes vides, sinon une ligne par client ; seulement les morceaux avec un numéro
function splitList(text) {
  const parts = /\n\s*\n/.test(text) ? text.split(/\n\s*\n/) : text.split('\n')
  return parts.map((p) => p.trim()).filter((p) => /\d[\d\s.-]{6,}\d/.test(p)).slice(0, 50)
}

async function parseList() {
  error.value = ''
  parsedInfo.value = ''
  const blocks = splitList(listText.value)
  if (!blocks.length) {
    error.value = 'Aucun numéro de téléphone trouvé dans la liste.'
    return
  }
  parsing.value = true
  let added = 0
  for (const [i, block] of blocks.entries()) {
    parseProgress.value = `${i + 1} / ${blocks.length}`
    const row = blankRow()
    try {
      const d = (await http.post('/orders/parse-message', { text: block })).data.data
      Object.assign(row, {
        recipient_phone: pairs(d.recipient_phone || ''),
        recipient_name: d.recipient_name || '',
        recipient_phone2: pairs(d.recipient_phone2 || ''),
        delivery_zone_id: d.zones?.[0]?.id || null,
        delivery_address: [d.delivery_address, d.delivery_landmark].filter(Boolean).join(', '),
        items_amount: d.items_amount ?? null,
        fee_payer: d.fee_payer || null,
        description: d.description || '',
        merchant_note: d.merchant_note || '',
      })
    } catch {
      row.merchant_note = block
    }
    rows.value.push(row)
    added++
  }
  parsing.value = false
  parseProgress.value = ''
  listText.value = ''
  pasteOpen.value = false
  const todo = rows.value.slice(-added).filter((r) => !isComplete(r)).length
  parsedInfo.value = `${added} course${added > 1 ? 's' : ''} reconnue${added > 1 ? 's' : ''}${todo ? `, dont ${todo} à compléter` : ''} : vérifiez puis envoyez.`
}

/* ---------- Envoi ---------- */
const sending = ref(false)
const sentCount = ref(0)
const done = ref(false)
const sentRows = computed(() => rows.value.filter((r) => r.order))
const failedRows = computed(() => rows.value.filter((r) => !r.order))

async function sendAll() {
  error.value = ''
  sending.value = true
  sentCount.value = 0
  for (const r of ready.value) {
    const payload = {
      recipient_phone: r.recipient_phone.replace(/[^\d+]/g, ''),
      recipient_name: r.recipient_name || undefined,
      recipient_phone2: r.recipient_phone2 ? r.recipient_phone2.replace(/[^\d+]/g, '') : undefined,
      delivery_zone_id: r.delivery_zone_id,
      delivery_address: r.delivery_address || undefined,
      items_amount: r.items_amount || 0,
      fee_payer: feePayerOf(r),
      delivery_scheduled_date: common.delivery_scheduled_date,
      description: r.description || undefined,
      merchant_note: r.merchant_note || undefined,
    }
    try {
      r.order = (await http.post('/orders', payload)).data.data
      r.error = ''
    } catch (e) {
      r.error = apiErrorMessage(e)
    }
    sentCount.value++
  }
  sending.value = false
  saveHabits()
  done.value = true
}

function retry() {
  rows.value = failedRows.value
  done.value = false
}

function restart() {
  rows.value = []
  done.value = false
  parsedInfo.value = ''
}

/* ---------- Habitudes (mêmes que la nouvelle course) ---------- */
const HABITS = 'jibiat.marchand.habitudes'
function saveHabits() {
  try {
    localStorage.setItem(HABITS, JSON.stringify({ fee_payer: common.fee_payer, when: ['today', 'tomorrow'].includes(when.value) ? when.value : null }))
  } catch {
    // Navigation privée : rien n'est gardé
  }
}
function applyHabits() {
  let h = {}
  try {
    h = JSON.parse(localStorage.getItem(HABITS) || '{}')
  } catch {
    h = {}
  }
  if (['recipient', 'merchant'].includes(h.fee_payer)) common.fee_payer = h.fee_payer
  const option = deliveryOptions.value.find((o) => o.value === h.when)
  if (option && !option.disabled) when.value = h.when
}

onMounted(async () => {
  loadDeliveryDay().then(applyHabits)
  zones.value = (await http.get('/zones')).data.data
  try {
    const { data } = await http.get('/orders', { params: { per_page: 40 } })
    const counts = {}
    data.data.forEach((o) => { counts[o.delivery.zone_id] = (counts[o.delivery.zone_id] || 0) + 1 })
    recentZones.value = Object.keys(counts).sort((a, b) => counts[b] - counts[a]).map(Number)
  } catch {
    // Facultatif
  }
})
</script>
