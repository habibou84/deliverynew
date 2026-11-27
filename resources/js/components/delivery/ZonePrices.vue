<template>
  <!-- Zones et prix : prix de base, puis chaque commune (on ramasse, on livre) et le bouton de ses prix au départ, qui
       ouvre sa fenêtre (paliers, puis un palier par destination) ; vue d'ensemble départ × arrivée -->
  <section class="space-y-3">
    <div class="flex flex-wrap items-center gap-2">
      <input v-model="query" type="search" class="input w-full sm:w-80" placeholder="Chercher une commune ou un quartier" aria-label="Chercher un lieu">
      <span class="flex-1" />
      <button type="button" class="btn-secondary" @click="open('report')">Quartier manquant ?</button>
      <button type="button" class="btn-secondary" @click="open('shipping')">+ Zone d'expédition</button>
    </div>

    <p class="text-sm text-slate-600">
      Touchez « Prix au départ » d'une commune : vous réglez ses prix vers toutes les destinations, par paliers. Un prix vaut dans les deux sens.
      <template v-if="!isDefault"> Sans prix ici, c'est celui de la grille normale.</template>
    </p>
    <p v-if="isDefault && baseDraft === null && missingCount" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      {{ missingCount }} trajet{{ missingCount > 1 ? 's' : '' }} entre communes sans prix ({{ missingNames }}) :
      réglez-les, ou donnez un prix de base.
    </p>

    <!-- Les communes et quartiers viennent de la liste commune : un quartier manquant se signale, il sera ajouté pour
         toutes les entreprises. Seule une zone d'expédition (gare, transporteur) se crée ici. -->
    <form v-if="adding === 'report'" class="card space-y-3 p-4" @submit.prevent="report">
      <p class="text-sm text-slate-600">Les quartiers sont les mêmes pour toutes les entreprises et Jibiat Marchand. Signalez celui qui manque : il sera ajouté à la liste.</p>
      <div class="flex flex-wrap items-end gap-2">
        <div class="min-w-40 flex-1">
          <label class="label" for="zp-report-name">Quartier</label>
          <input id="zp-report-name" v-model="draft.name" class="input" required maxlength="120" placeholder="Ex. : Angré Nouveau CHU">
        </div>
        <div class="min-w-40 flex-1">
          <label class="label" for="zp-report-parent">Dans la commune ou la ville</label>
          <SearchSelect
            id="zp-report-parent" v-model="draft.parent_code" placeholder="— Choisir —" :empty-value="null" search-placeholder="Commune…"
            :options="communes.filter((c) => c.place).map((c) => ({ value: c.place, label: c.name }))"
          />
        </div>
        <button class="btn-primary" :disabled="draft.saving || !draft.parent_code">Signaler</button>
      </div>
      <p v-if="draft.error" class="field-error">{{ draft.error }}</p>
    </form>
    <form v-if="adding === 'shipping'" class="card space-y-3 p-4" @submit.prevent="create">
      <div class="flex flex-wrap items-end gap-2">
        <div class="min-w-40 flex-1">
          <label class="label" for="zp-name">Nom</label>
          <input id="zp-name" v-model="draft.name" class="input" required placeholder="Ex. : Expédition Bouaké (gare UTB Adjamé)">
        </div>
        <div class="w-36">
          <label class="label" for="zp-price">Prix (F)</label>
          <input id="zp-price" v-model.number="draft.price" type="number" min="0" class="input" placeholder="Ex. : 1500">
        </div>
        <button class="btn-primary" :disabled="draft.saving">Ajouter</button>
      </div>
      <p class="text-sm text-slate-600">Le livreur dépose le colis à une gare ou chez un transporteur et paie l'envoi, facturé au marchand. Le prix est celui de la course jusqu'à la gare.</p>
      <p v-if="draft.error" class="field-error">{{ draft.error }}</p>
    </form>

    <p v-if="loading" class="card p-6 text-center text-slate-600">Chargement…</p>
    <p v-else-if="loadError" class="field-error">{{ loadError }}</p>

    <template v-else>
      <!-- Prix de base : toute destination sans prix (ni le sien, ni celui de sa commune) -->
      <div v-if="isDefault" class="card flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
        <div class="min-w-56 flex-1">
          <p class="font-semibold">Prix de base</p>
          <p class="text-sm text-slate-600">Pour tout trajet sans prix. Plus aucune course bloquée faute de tarif.</p>
        </div>
        <label class="flex items-center gap-2">
          <input :value="baseDraft ?? ''" inputmode="numeric" placeholder="—" class="input w-36 py-2 text-right text-base tabular-nums" aria-label="Prix de base" @change="setBase($event.target.value)">
          <span class="text-sm text-slate-600">F</span>
        </label>
      </div>

      <!-- Destinations : la commune d'abord (son prix vaut pour tous ses quartiers), les quartiers à la demande -->
      <div class="card divide-y overflow-hidden">
        <div class="hidden grid-cols-[minmax(12rem,1fr)_7rem_7rem_17rem] items-center gap-4 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600 md:grid">
          <span>Commune</span><span class="text-center">On ramasse</span><span class="text-center">On livre</span><span class="text-right">Prix au départ</span>
        </div>
        <template v-for="c in [...shownCommunes, ...(inlandOpen || query.trim() ? inlandCities : [])]" :key="c.id">
          <div :class="['grid grid-cols-2 items-center gap-x-4 gap-y-2 px-4 py-3 md:grid-cols-[minmax(12rem,1fr)_7rem_7rem_17rem]', c.is_active ? '' : 'bg-slate-50']">
            <div class="col-span-2 min-w-0 md:col-span-1">
              <p :class="['text-base font-semibold', c.is_active ? '' : 'text-slate-500']">{{ c.name }}
                <span v-if="!c.place" class="ml-1 rounded-full bg-amber-50 px-1.5 text-xs font-normal text-amber-800 ring-1 ring-amber-200">ajouté</span>
              </p>
              <button v-if="childrenOf(c).length" type="button" class="mt-0.5 text-sm font-medium text-emerald-700 hover:underline" @click="toggleOpen(c)">
                {{ isOpen(c) ? '▾' : '▸' }} {{ childrenOf(c).length }} quartier{{ childrenOf(c).length > 1 ? 's' : '' }}<template v-if="ownCount(c)"> · {{ ownCount(c) }} à part</template>
              </button>
              <template v-if="!c.place">
                <button type="button" class="ml-3 text-xs text-blue-700" @click="rename(c)">Renommer</button>
                <button type="button" class="ml-2 text-xs text-red-700" @click="remove(c)">Supprimer</button>
              </template>
            </div>
            <div class="flex items-center gap-2 text-sm md:justify-center">
              <SwitchButton :on="!!(pickupOn(c))" :disabled="!!(busy[c.id])" :label="`On ramasse ici : ${c.name}`" @toggle="togglePickup(c)" /><span class="md:hidden">On ramasse</span>
            </div>
            <div class="flex items-center gap-2 text-sm md:justify-center">
              <SwitchButton :on="!!(c.is_active)" :disabled="!!(busy[c.id])" :label="`On livre ici : ${c.name}`" @toggle="toggleActive(c)" /><span class="md:hidden">On livre</span>
            </div>
            <div class="col-span-2 flex items-center justify-end gap-3 md:col-span-1">
              <span v-if="c.is_active" class="text-xs tabular-nums text-slate-600">{{ progress(c).done }}/{{ progress(c).total }} réglés</span>
              <button type="button" class="btn-secondary py-1.5" :disabled="!c.is_active" :aria-label="`Prix au départ de ${c.name}`" @click="departure = c">Prix au départ</button>
            </div>
          </div>
          <!-- Quartiers : sans prix, celui de la commune ; un prix à part seulement s'il diffère -->
          <template v-if="isOpen(c)">
            <div v-for="q in childrenOf(c)" :key="q.id" :class="['grid grid-cols-2 items-center gap-x-4 gap-y-2 bg-slate-50/60 py-2.5 pl-8 pr-4 md:grid-cols-[minmax(12rem,1fr)_7rem_7rem_17rem]', q.is_active ? '' : 'opacity-60']">
              <p class="col-span-2 min-w-0 md:col-span-1">↳ {{ q.name }}
                <span v-if="hasOwnDeparture(q)" class="ml-1 rounded-full bg-emerald-50 px-1.5 text-xs text-emerald-800 ring-1 ring-emerald-200">prix à part</span>
              </p>
              <div class="flex items-center gap-2 text-sm md:justify-center">
                <SwitchButton :on="!!(pickupOn(q))" :disabled="!!(busy[q.id] || parentPickupOff(q))" :label="`On ramasse ici : ${q.name}`" @toggle="togglePickup(q)" /><span class="md:hidden">On ramasse</span>
              </div>
              <div class="flex items-center gap-2 text-sm md:justify-center">
                <SwitchButton :on="!!(q.is_active)" :disabled="!!(busy[q.id])" :label="`On livre ici : ${q.name}`" @toggle="toggleActive(q)" /><span class="md:hidden">On livre</span>
              </div>
              <p class="col-span-2 text-right text-xs text-slate-500 md:col-span-1">{{ hasOwnDeparture(q) ? 'prix à part au départ' : `suit ${c.name}` }}</p>
            </div>
          </template>
        </template>
        <button v-if="inlandCities.length && !query.trim()" type="button" class="flex w-full items-center justify-between px-4 py-3 text-left text-sm text-slate-600 hover:bg-slate-50" @click="inlandOpen = !inlandOpen">
          <span>{{ inlandOpen ? '▾' : '▸' }} Autres villes, par l'Expédition ({{ inlandCities.length }}) : cochez « On livre » pour en livrer une directement</span>
        </button>
        <p v-if="!shownCommunes.length && !inlandCities.length" class="p-6 text-center text-slate-600">Aucun lieu ne correspond.</p>
      </div>

      <!-- Vue d'ensemble : départ en ligne, arrivée en colonne ; en gris, le prix qui s'applique faute de trajet réglé -->
      <details class="card p-4">
        <summary class="cursor-pointer font-semibold">Vue d'ensemble des prix entre communes</summary>
        <p class="mt-2 text-sm text-slate-600">Départ en ligne, arrivée en colonne. En gris : pas de trajet réglé, c'est le prix habituel ou le prix de base. Touchez une commune pour l'ouvrir.</p>
        <div class="mt-3 overflow-x-auto">
          <table class="border-collapse text-xs tabular-nums">
            <tr>
              <th class="border bg-slate-50 px-2 py-1.5" />
              <th v-for="b in overview" :key="b.id" class="border bg-slate-50 px-2 py-1.5 font-semibold" :title="b.name">{{ b.name.slice(0, 5) }}{{ b.name.length > 5 ? '.' : '' }}</th>
            </tr>
            <tr v-for="a in overview" :key="a.id">
              <th class="border bg-slate-50 px-2 py-1.5 text-left"><button type="button" class="font-semibold text-emerald-700 hover:underline" @click="departure = a">{{ a.name }}</button></th>
              <td v-for="b in overview" :key="b.id" :class="['border px-2 py-1.5 text-center', cell(a, b).kind === 'route' ? '' : 'text-slate-400']">{{ cell(a, b).price === null ? '—' : cell(a, b).price.toLocaleString('fr-FR') }}</td>
            </tr>
          </table>
        </div>
      </details>

      <!-- Prix habituels par destination (réglage d'avant les prix au départ) : encore appliqués faute de trajet -->
      <details v-if="usual.length" class="card p-4">
        <summary class="cursor-pointer font-semibold">Prix habituels par destination ({{ usual.length }})</summary>
        <p class="mt-2 text-sm text-slate-600">Prix vers une destination d'où que parte le colis, quand aucun trajet n'est réglé. Videz-en un pour qu'il suive le prix de base.</p>
        <ul class="mt-3 divide-y rounded-lg border">
          <li v-for="z in usual" :key="z.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
            <span class="min-w-40 flex-1">{{ label(z.id) }}</span>
            <input :value="ownPrice(z) ?? ''" inputmode="numeric" class="input w-28 py-1.5 text-right tabular-nums" :aria-label="`Prix habituel vers ${z.name}`" @change="setOwnPrice(z, $event.target.value)">
            <span class="text-sm text-slate-600">F</span>
          </li>
        </ul>
      </details>

      <!-- Enregistrer : les prix saisis dans le tableau (les interrupteurs s'enregistrent tout de suite) -->
      <div class="sticky bottom-0 z-30 flex flex-wrap items-center gap-3 rounded-xl border bg-white p-3 shadow-lg">
        <span class="flex-1 text-sm" :class="changes ? 'font-semibold text-amber-800' : 'text-slate-600'">
          {{ changes ? `${changes} prix modifié${changes > 1 ? 's' : ''}, pas encore enregistré${changes > 1 ? 's' : ''}` : 'Les interrupteurs s\'enregistrent tout de suite ; les prix avec ce bouton.' }}
        </span>
        <button v-if="changes" type="button" class="btn-secondary" :disabled="saving" @click="load">Annuler</button>
        <button type="button" class="btn-primary" :disabled="!changes || saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer les prix' }}</button>
      </div>

      <DeparturePrices
        v-if="departure" :commune="departure" :zones="zones" :prices="prices" :own="own" :base="baseDraft" :is-default="isDefault"
        :tiers="tiersOf(departure)" :changes="changes" :saving="saving"
        @close="departure = null" @set="setCell" @tiers="(list) => setTiers(departure, list)" @save="save" @cancel="load"
      />

      <!-- Zones d'expédition -->
      <section v-if="shipping.length" class="space-y-2">
        <h2 class="pt-2 font-semibold">Zones d'expédition</h2>
        <p class="text-sm text-slate-600">
          « Expédition » sert pour toutes les villes de l'intérieur : la ville de destination se choisit sur la course. Le prix est celui de la
          course jusqu'à la gare ; les frais du transporteur, notés par le livreur, sont déduits du point de l'e-commerçant.
        </p>
        <div class="card divide-y overflow-hidden">
          <div v-for="z in shipping" :key="z.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
            <div class="flex items-center gap-2 text-sm"><SwitchButton :on="!!z.is_active" :label="`On livre ici : ${z.name}`" @toggle="toggleActive(z)" /> On livre ici</div>
            <span :class="['min-w-40 flex-1 font-medium', z.is_active ? '' : 'text-slate-600 line-through']">{{ z.name }}</span>
            <label class="flex items-center gap-2 text-xs text-slate-600">
              Frais habituels du transporteur
              <input :value="z.shipping_fee_estimate" type="number" min="0" class="input w-24 py-1" :aria-label="`Frais habituels : ${z.name}`" @change="setEstimate(z, $event.target.value)">
            </label>
            <label class="flex items-center gap-2 text-xs text-slate-600">
              {{ z.default_shipping ? 'Prix de la course jusqu\'à la gare' : 'Prix de la course' }}
              <input :value="ownPrice(z) ?? ''" type="number" min="0" class="input w-28 py-1" :placeholder="isDefault ? '—' : 'normale'" :aria-label="`Prix : ${z.name}`" @change="setOwnPrice(z, $event.target.value)">
            </label>
            <template v-if="!z.default_shipping">
              <button type="button" class="text-xs text-blue-700" @click="rename(z)">Renommer</button>
              <button type="button" class="text-xs text-red-700" @click="remove(z)">Supprimer</button>
            </template>
            <span v-else class="text-xs text-slate-600">La ville se choisit sur chaque course</span>
          </div>
        </div>
      </section>
    </template>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { confirmDialog, promptDialog } from '../../composables/useDialog'
import SearchSelect from '../SearchSelect.vue'
import SwitchButton from '../ui/SwitchButton.vue'
import DeparturePrices from './DeparturePrices.vue'
import { resolveRoute } from '../../utils/routePrices'

/**
 * Zones et prix. Prix de base (tout trajet sans prix). Puis chaque commune : « on ramasse », « on livre »
 * (enregistrés tout de suite) et « Prix au départ », qui ouvre sa fenêtre : paliers de la commune, puis un palier par
 * destination, depuis la commune ou un de ses quartiers (DeparturePrices). Vue d'ensemble départ × arrivée. Les prix
 * habituels par destination (réglage d'avant) restent appliqués faute de trajet. Les prix s'enregistrent ensemble.
 */
const props = defineProps({ gridId: { type: Number, required: true }, isDefault: { type: Boolean, default: true } })

const toasts = useToastStore()
const zones = ref([])
const rules = ref({}) // "départ-arrivée" -> prix enregistré
const prices = ref({}) // "départ-arrivée" -> prix affiché (modifiable)
const own = ref({}) // zone -> prix « Partout » affiché (modifiable) ; null : pas de prix propre
const loading = ref(true)
const loadError = ref('')
const query = ref('')
const busy = reactive({})
const saving = ref(false)
const adding = ref(false)
const draft = reactive({ name: '', parent_code: null, price: null, error: '', saving: false })
function open(mode) {
  adding.value = adding.value === mode ? false : mode
  Object.assign(draft, { name: '', parent_code: null, price: null, error: '' })
}
async function report() {
  draft.error = ''
  draft.saving = true
  try {
    await http.post('/place-reports', { name: draft.name, parent_code: draft.parent_code })
    toasts.success('Merci : le quartier sera ajouté à la liste après vérification.')
    adding.value = false
  } catch (e) {
    draft.error = apiErrorMessage(e)
  } finally {
    draft.saving = false
  }
}
const leaveWarning = 'Des prix modifiés ne sont pas enregistrés. Quitter quand même ?'

const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const key = (a, b) => `${a}-${b}`
const byId = computed(() => Object.fromEntries(zones.value.map((z) => [z.id, z])))
const communes = computed(() => zones.value.filter((z) => !z.parent_id && !z.is_shipping))
// Zones d'expédition, la zone « Expédition » d'office en premier
const shipping = computed(() => zones.value.filter((z) => z.is_shipping).sort((a, b) => Number(!!b.default_shipping) - Number(!!a.default_shipping)))

const ownPrice = (z) => own.value[z.id] ?? null
// Destinations : les communes, filtrées par la recherche (sur la commune ou un de ses quartiers)
const childrenOf = (c) => zones.value.filter((z) => z.parent_id === c.id && !z.is_shipping)
const opened = ref(new Set())
const isOpen = (c) => opened.value.has(c.id) || (!!query.value.trim() && childrenOf(c).some((q) => norm(q.name).includes(norm(query.value.trim()))))
function toggleOpen(c) {
  const next = new Set(opened.value)
  next.has(c.id) ? next.delete(c.id) : next.add(c.id)
  opened.value = next
}
const ownCount = (c) => childrenOf(c).filter((q) => hasOwnDeparture(q)).length
// Villes de l'intérieur masquées (elles passent par l'Expédition) : rangées à part, repliées
const inland = (c) => !c.is_active && !!c.place && !c.place.startsWith('abidjan')
const matches = (c) => { const q = norm(query.value.trim()); return !q || norm(c.name).includes(q) || childrenOf(c).some((z) => norm(z.name).includes(q)) }
const shownCommunes = computed(() => communes.value.filter((c) => !inland(c) && matches(c)))
const inlandCities = computed(() => communes.value.filter((c) => inland(c) && matches(c)))
const inlandOpen = ref(false)
// Prix de base de la grille (enregistré avec les autres prix)
const baseSaved = ref(null)
const baseDraft = ref(null)
function setBase(raw) {
  const v = parse(raw)
  if (v !== undefined) baseDraft.value = v
}
// Trajets particuliers : un par paire (le sens inverse sans prix propre est le même trajet)
const label = (id) => { const z = byId.value[id]; return z ? (z.parent_id ? `${z.name} (${byId.value[z.parent_id]?.name ?? ''})` : z.name) : '?' }
const parentPickupOff = (z) => z.parent_id !== null && byId.value[z.parent_id]?.is_pickup === false
const pickupOn = (z) => z.is_pickup && !parentPickupOff(z)
// Prix au départ : trajet départ → arrivée, sinon prix habituel de la destination, sinon prix de base (comme le calcul)
const departure = ref(null)
const cell = (a, b) => resolveRoute(a, b, { byId: byId.value, prices: prices.value, own: own.value, base: baseDraft.value })
const delivered = computed(() => communes.value.filter((c) => c.is_active))
const overview = computed(() => delivered.value.filter((c) => !inland(c)))
function progress(c) {
  const list = delivered.value
  return { done: list.filter((d) => cell(c, d).kind === 'route').length, total: list.length }
}
const hasOwnDeparture = (z) => Object.keys(prices.value).some((k) => k.startsWith(`${z.id}-`))
// Trajets entre communes livrées sans aucun prix (ni trajet, ni prix habituel, ni prix de base)
const missingList = computed(() => overview.value.flatMap((a) => overview.value.filter((b) => b.id >= a.id && cell(a, b).price === null).map((b) => `${a.name} → ${b.name}`)))
const missingCount = computed(() => missingList.value.length)
const missingNames = computed(() => missingList.value.slice(0, 3).join(', ') + (missingCount.value > 3 ? '…' : ''))
// Prix habituels par destination encore enregistrés (hors zones d'expédition)
const usual = computed(() => zones.value.filter((z) => !z.is_shipping && (own.value[z.id] ?? null) !== null))

// Paliers de chaque commune de départ : enregistrés avec la grille, sinon tirés de ses prix
const tiersSaved = ref({})
const tiers = ref({})
const DEFAULT_TIERS = [1000, 1500, 2000, 3000]
function tiersOf(c) {
  if (tiers.value[c.id]) return tiers.value[c.id]
  const from = new Set([c.id, ...childrenOf(c).map((q) => q.id)])
  const used = [...new Set(Object.entries(prices.value).filter(([k]) => k.split('-').map(Number).some((id) => from.has(id))).map(([, v]) => v))]
  const list = [...used.sort((x, y) => x - y), ...DEFAULT_TIERS].filter((v, i, a) => a.indexOf(v) === i).slice(0, 4).sort((x, y) => x - y)
  return list
}
function setTiers(c, list) {
  tiers.value = { ...tiers.value, [c.id]: list }
}
const tierChanges = computed(() => JSON.stringify(tiers.value) !== JSON.stringify(tiersSaved.value))

// Changements de prix pas encore enregistrés
const ownChanges = computed(() => zones.value.filter((z) => (own.value[z.id] ?? null) !== (z.price ?? null)))
const ruleChanges = computed(() => {
  const keys = new Set([...Object.keys(rules.value), ...Object.keys(prices.value)])
  return [...keys].filter((k) => (rules.value[k] ?? null) !== (prices.value[k] ?? null)).length
})
const changes = computed(() => ownChanges.value.length + ruleChanges.value + (baseDraft.value !== baseSaved.value ? 1 : 0) + (tierChanges.value ? 1 : 0))

function parse(raw) {
  if (raw === '' || raw === null) return null
  const n = Number(String(raw).replace(/\s/g, ''))
  return Number.isNaN(n) || n < 0 ? undefined : Math.round(n)
}
function setOwnPrice(z, raw) {
  const v = parse(raw)
  if (v !== undefined) own.value = { ...own.value, [z.id]: v }
}
function setCell(a, b, raw) {
  const v = parse(raw)
  if (v === undefined) return
  const next = { ...prices.value }
  if (v === null) delete next[key(a, b)]
  else next[key(a, b)] = v
  prices.value = next
}

async function load() {
  loading.value = zones.value.length === 0
  try {
    const [zp, grid] = await Promise.all([
      http.get(`/pricing-grids/${props.gridId}/zone-prices`),
      http.get(`/pricing-grids/${props.gridId}`),
    ])
    zones.value = zp.data.data.zones
    own.value = Object.fromEntries(zones.value.map((z) => [z.id, z.price ?? null]))
    rules.value = Object.fromEntries(grid.data.data.rules.map((r) => [key(r.origin_zone_id, r.destination_zone_id), r.price]))
    prices.value = { ...rules.value }
    tiersSaved.value = Object.fromEntries(Object.entries(grid.data.data.tiers ?? {}).map(([k, v]) => [Number(k), v]))
    tiers.value = { ...tiersSaved.value }
    baseSaved.value = grid.data.data.base_price ?? null
    baseDraft.value = baseSaved.value
    loadError.value = ''
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    if (baseDraft.value !== baseSaved.value || tierChanges.value) {
      await http.put(`/pricing-grids/${props.gridId}`, { base_price: baseDraft.value, tiers: tiers.value })
    }
    for (const z of ownChanges.value) {
      await http.put(`/pricing-grids/${props.gridId}/zone-prices/${z.id}`, { price: own.value[z.id] ?? null })
    }
    if (ruleChanges.value) {
      // Un trajet est symétrique si le sens inverse n'a pas de prix propre
      const list = Object.entries(prices.value).map(([k, price]) => {
        const [o, d] = k.split('-').map(Number)
        return { origin_zone_id: o, destination_zone_id: d, price, is_symmetric: prices.value[key(d, o)] === undefined }
      })
      await http.put(`/pricing-grids/${props.gridId}/rules`, { rules: list })
    }
    toasts.success('Prix enregistrés.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function toggleActive(zone) {
  busy[zone.id] = true
  try {
    await http.patch(`/zones/${zone.id}`, { is_active: !zone.is_active })
    zone.is_active = !zone.is_active
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy[zone.id] = false
  }
}

// Ramassage coupé ou rétabli : couper une commune coupe aussi ses quartiers
async function togglePickup(zone) {
  busy[zone.id] = true
  try {
    await http.patch(`/zones/${zone.id}`, { is_pickup: !zone.is_pickup })
    zone.is_pickup = !zone.is_pickup
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy[zone.id] = false
  }
}

async function setEstimate(zone, value) {
  try {
    await http.patch(`/zones/${zone.id}`, { shipping_fee_estimate: value === '' ? null : Number(value) })
    zone.shipping_fee_estimate = value === '' ? null : Number(value)
    toasts.success('Frais habituels enregistrés.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function rename(zone) {
  const name = (await promptDialog('Nouveau nom du lieu :', zone.name))?.trim()
  if (!name || name === zone.name) return
  try {
    await http.patch(`/zones/${zone.id}`, { name })
    zone.name = name
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function remove(zone) {
  if (!await confirmDialog(`Supprimer le lieu « ${zone.name} » et ses prix ?`)) return
  try {
    await http.delete(`/zones/${zone.id}`)
    toasts.success('Lieu supprimé.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e), { timeout: 10000 })
  }
}

async function create() {
  draft.error = ''
  draft.saving = true
  try {
    const { data } = await http.post('/zones', { name: draft.name, parent_id: null, is_shipping: true })
    if (draft.price !== null && draft.price !== '') {
      await http.put(`/pricing-grids/${props.gridId}/zone-prices/${data.data.id}`, { price: Number(draft.price) })
    }
    toasts.success('Lieu ajouté.')
    Object.assign(draft, { name: '', parent_code: null, price: null })
    adding.value = false
    await load()
  } catch (e) {
    draft.error = apiErrorMessage(e)
  } finally {
    draft.saving = false
  }
}

onBeforeRouteLeave(async () => !changes.value || await confirmDialog(leaveWarning))
function warnBeforeUnload(event) {
  if (changes.value) event.preventDefault()
}
window.addEventListener('beforeunload', warnBeforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))

onMounted(load)
</script>
