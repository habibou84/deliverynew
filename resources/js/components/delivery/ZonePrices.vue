<template>
  <!-- Zones et prix : un seul tableau. Par zone : on ramasse ici, on livre ici, son prix « depuis partout », puis le prix
       de chaque trajet vers les zones où l'on livre -->
  <section class="space-y-3">
    <div class="flex flex-wrap items-center gap-2">
      <input v-model="query" type="search" class="input w-full sm:w-64" placeholder="Chercher une commune ou un quartier" aria-label="Chercher un lieu">
      <label class="flex items-center gap-2 text-sm"><input v-model="showNeighborhoods" type="checkbox"> Afficher les quartiers</label>
      <span class="flex-1" />
      <button type="button" class="btn-secondary" @click="open('report')">Quartier manquant ?</button>
      <button type="button" class="btn-secondary" @click="open('shipping')">+ Zone d'expédition</button>
    </div>

    <div class="space-y-1 text-sm text-slate-600">
      <p>
        Cochez où vous <b>ramassez</b> et où vous <b>livrez</b>. La colonne <b>« Partout »</b> est le prix d'une livraison vers la zone de la ligne,
        d'où que parte le colis. Les colonnes suivantes : le prix d'un trajet précis, depuis la zone de la ligne (départ) vers celle de la
        colonne (arrivée). Il passe avant « Partout ». Case vide = le prix « Partout » de l'arrivée.
      </p>
      <p>
        Une case grisée reprend le prix du sens inverse. Un quartier sans prix prend celui de sa commune.
        <template v-if="!isDefault"> Laissé vide, le prix est celui de la grille normale.</template>
      </p>
    </div>
    <p v-if="isDefault && missingCount" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      {{ missingCount }} commune{{ missingCount > 1 ? 's' : '' }} livrée{{ missingCount > 1 ? 's' : '' }} sans prix « Partout » ({{ missingNames }}) :
      une course vers {{ missingCount > 1 ? 'elles' : 'elle' }} ne peut pas être chiffrée, sauf trajet précis.
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
      <div class="card max-h-[70vh] overflow-auto">
        <table class="border-collapse text-sm">
          <thead class="sticky top-0 z-20 bg-slate-100 text-xs font-semibold text-slate-700">
            <tr>
              <th class="sticky left-0 z-30 min-w-44 bg-slate-100 p-2 text-left">Zone (départ ↓ · arrivée →)</th>
              <th class="p-2 text-center">On ramasse ici</th>
              <th class="p-2 text-center">On livre ici</th>
              <th class="border-l-2 border-slate-300 bg-emerald-50 p-2 text-center text-emerald-900">Partout</th>
              <th v-for="col in columns" :key="col.id" class="whitespace-nowrap p-2 font-medium">{{ col.name }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="z in rows" :key="z.id" :class="z.is_active ? '' : 'bg-slate-50'">
              <th :class="['sticky left-0 z-10 whitespace-nowrap border-t bg-white p-2 text-left font-medium', z.parent_id ? 'pl-6 font-normal' : '']">
                <span :class="z.is_active || z.is_pickup ? '' : 'text-slate-600'">{{ z.parent_id ? '↳ ' : '' }}{{ z.name }}</span>
                <span v-if="!z.place" class="ml-1 rounded-full bg-amber-50 px-1.5 text-xs font-normal text-amber-800 ring-1 ring-amber-200">ajouté</span>
                <template v-if="!z.place">
                  <button type="button" class="ml-2 text-xs font-normal text-blue-700" @click="rename(z)">Renommer</button>
                  <button type="button" class="ml-2 text-xs font-normal text-red-700" @click="remove(z)">Supprimer</button>
                </template>
              </th>
              <td class="border-t p-2 text-center">
                <input type="checkbox" class="h-5 w-5" :checked="pickupOn(z)" :disabled="busy[z.id] || parentPickupOff(z)" :title="parentPickupOff(z) ? 'Pas de ramassage dans la commune' : ''" :aria-label="`On ramasse ici : ${z.name}`" @change="togglePickup(z)">
              </td>
              <td class="border-t p-2 text-center">
                <input type="checkbox" class="h-5 w-5" :checked="z.is_active" :disabled="busy[z.id]" :aria-label="`On livre ici : ${z.name}`" @change="toggleActive(z)">
              </td>
              <td class="border-l-2 border-t border-slate-300 bg-emerald-50/50 p-1">
                <input
                  :value="ownPrice(z) ?? ''" :placeholder="partoutPlaceholder(z)" inputmode="numeric" :disabled="!z.is_active"
                  :class="['w-20 rounded border px-1 py-1 text-right tabular-nums', missing(z) ? 'border-amber-500' : 'border-slate-300']"
                  :aria-label="`Prix partout vers ${z.name}`" @change="setOwnPrice(z, $event.target.value)"
                >
              </td>
              <td v-for="col in columns" :key="col.id" class="border-t p-0.5">
                <input
                  :value="cell(z.id, col.id) ?? ''" :placeholder="mirror(z.id, col.id) ?? ''" inputmode="numeric"
                  :class="['w-16 rounded px-1 py-1 text-right tabular-nums', mirror(z.id, col.id) !== null && cell(z.id, col.id) === null ? 'bg-slate-100' : '']"
                  :aria-label="`${z.name} vers ${col.name}`" @change="setCell(z.id, col.id, $event.target.value)"
                >
              </td>
            </tr>
            <tr v-if="!rows.length"><td :colspan="4 + columns.length" class="p-6 text-center text-slate-600">Aucun lieu ne correspond.</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Enregistrer : les prix saisis dans le tableau (les cases à cocher s'enregistrent tout de suite) -->
      <div class="sticky bottom-0 z-30 flex flex-wrap items-center gap-3 rounded-xl border bg-white p-3 shadow-lg">
        <span class="flex-1 text-sm" :class="changes ? 'font-semibold text-amber-800' : 'text-slate-600'">
          {{ changes ? `${changes} prix modifié${changes > 1 ? 's' : ''}, pas encore enregistré${changes > 1 ? 's' : ''}` : 'Les cases à cocher s\'enregistrent tout de suite ; les prix avec ce bouton.' }}
        </span>
        <button v-if="changes" type="button" class="btn-secondary" :disabled="saving" @click="load">Annuler</button>
        <button type="button" class="btn-primary" :disabled="!changes || saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer les prix' }}</button>
      </div>

      <!-- Zones d'expédition -->
      <section v-if="shipping.length" class="space-y-2">
        <h2 class="pt-2 font-semibold">Zones d'expédition</h2>
        <p class="text-sm text-slate-600">
          « Expédition » sert pour toutes les villes de l'intérieur : la ville de destination se choisit sur la course. Le prix est celui de la
          course jusqu'à la gare ; les frais du transporteur, notés par le livreur, sont déduits du point de l'e-commerçant.
        </p>
        <div class="card divide-y overflow-hidden">
          <div v-for="z in shipping" :key="z.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="h-5 w-5" :checked="z.is_active" :aria-label="`On livre ici : ${z.name}`" @change="toggleActive(z)"> On livre ici</label>
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

/**
 * Zones et prix : un seul tableau. Lignes : les zones (départ), avec « on ramasse ici » et « on livre ici » (enregistrés
 * tout de suite) et le prix « Partout » (vers cette zone, d'où que parte le colis). Colonnes : les zones où l'on livre
 * (arrivée) ; chaque case est le prix d'un trajet précis, qui passe avant « Partout ». Les prix s'enregistrent ensemble.
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
const showNeighborhoods = ref(false)
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
const used = computed(() => new Set(Object.keys(prices.value).flatMap((k) => k.split('-').map(Number))))
// Lignes : les communes (et les quartiers à la demande, ou ceux qui ont un prix ou un trajet)
const rows = computed(() => {
  const q = norm(query.value.trim())
  return zones.value.filter((z) => !z.is_shipping
    && (!z.parent_id || showNeighborhoods.value || q || own.value[z.id] != null || used.value.has(z.id))
    && (!q || norm(z.name).includes(q) || norm(byId.value[z.parent_id]?.name).includes(q)))
})
// Colonnes : les lieux où l'on livre (et ceux qui ont déjà un trajet)
const columns = computed(() => zones.value.filter((z) => (z.is_active || used.value.has(z.id))
  && (!z.parent_id || showNeighborhoods.value || used.value.has(z.id))))

const ownPrice = (z) => own.value[z.id] ?? null
const cell = (a, b) => prices.value[key(a, b)] ?? null
const mirror = (a, b) => (a === b ? null : prices.value[key(b, a)] ?? null)
const parentPickupOff = (z) => z.parent_id !== null && byId.value[z.parent_id]?.is_pickup === false
const pickupOn = (z) => z.is_pickup && !parentPickupOff(z)
const missing = (z) => props.isDefault && z.is_active && !z.parent_id && ownPrice(z) === null
const missingList = computed(() => communes.value.filter(missing))
const missingCount = computed(() => missingList.value.length)
const missingNames = computed(() => missingList.value.slice(0, 4).map((z) => z.name).join(', ') + (missingCount.value > 4 ? '…' : ''))
function partoutPlaceholder(z) {
  const parent = z.parent_id ? ownPrice(byId.value[z.parent_id] || {}) : null
  if (parent !== null) return String(parent)
  return props.isDefault ? '—' : 'normale'
}

// Changements de prix pas encore enregistrés
const ownChanges = computed(() => zones.value.filter((z) => (own.value[z.id] ?? null) !== (z.price ?? null)))
const ruleChanges = computed(() => {
  const keys = new Set([...Object.keys(rules.value), ...Object.keys(prices.value)])
  return [...keys].filter((k) => (rules.value[k] ?? null) !== (prices.value[k] ?? null)).length
})
const changes = computed(() => ownChanges.value.length + ruleChanges.value)

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
