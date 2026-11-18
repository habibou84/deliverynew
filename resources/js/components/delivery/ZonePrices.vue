<template>
  <!-- Zones et prix : une ligne par lieu, « on livre ici » et son prix de livraison -->
  <section class="space-y-3">
    <div class="flex flex-wrap items-center gap-2">
      <input v-model="query" type="search" class="input w-full sm:w-64" placeholder="Chercher une commune ou un quartier" aria-label="Chercher un lieu">
      <div class="flex flex-wrap gap-2" role="group" aria-label="Filtrer les lieux">
        <button
          v-for="f in FILTERS" :key="f.value" type="button" :aria-pressed="filter === f.value"
          :class="['min-h-10 rounded-full px-3.5 text-sm font-semibold ring-1', filter === f.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-900 ring-slate-300']"
          @click="filter = f.value"
        >
          {{ f.label }}<template v-if="f.value === 'missing' && missingCount"> · {{ missingCount }}</template>
        </button>
      </div>
      <span class="flex-1" />
      <button type="button" class="btn-secondary" @click="adding = !adding">+ Ajouter un lieu</button>
    </div>

    <p class="text-sm text-slate-600">
      Cochez les communes où vous livrez et donnez-leur un prix : c'est le prix d'une livraison vers ce lieu, d'où que parte le colis.
      Un quartier sans prix prend celui de sa commune ; donnez-lui le sien s'il est plus loin.
      « On ramasse ici » : décochez les lieux où vous ne passez pas récupérer les colis ; ils ne sont plus proposés comme adresse de ramassage.
      <template v-if="!isDefault"> Laissé vide, le prix est celui de la grille normale.</template>
    </p>
    <p v-if="isDefault && missingCount" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      {{ missingCount }} commune{{ missingCount > 1 ? 's' : '' }} livrée{{ missingCount > 1 ? 's' : '' }} sans prix : une course vers {{ missingCount > 1 ? 'elles' : 'elle' }} ne peut pas être chiffrée.
      <button type="button" class="font-semibold underline" @click="filter = 'missing'">Les voir</button>
    </p>

    <!-- Ajouter un lieu absent de la liste, ou une zone d'expédition -->
    <form v-if="adding" class="card space-y-3 p-4" @submit.prevent="create">
      <div class="flex flex-wrap items-end gap-2">
        <div class="min-w-40 flex-1">
          <label class="label" for="zp-name">Nom</label>
          <input id="zp-name" v-model="draft.name" class="input" required :placeholder="draft.is_shipping ? 'Ex. : Expédition Bouaké (gare UTB Adjamé)' : 'Ex. : Angré'">
        </div>
        <div v-if="!draft.is_shipping" class="min-w-40 flex-1">
          <label class="label" for="zp-parent">Dans la commune</label>
          <SearchSelect
            id="zp-parent" v-model="draft.parent_id" placeholder="— (c'est une commune)" :empty-value="null" search-placeholder="Commune…"
            :options="communes.map((c) => ({ value: c.id, label: c.name }))"
          />
        </div>
        <div class="w-36">
          <label class="label" for="zp-price">Prix (F)</label>
          <input id="zp-price" v-model.number="draft.price" type="number" min="0" class="input" placeholder="Ex. : 1500">
        </div>
        <button class="btn-primary" :disabled="draft.saving">Ajouter</button>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="draft.is_shipping" type="checkbox" class="mt-1" @change="draft.parent_id = null">
        <span>Zone d'expédition
          <span class="block text-slate-600">Le livreur dépose le colis à une gare ou chez un transporteur et paie l'envoi, facturé au marchand. Le prix est celui de la course jusqu'à la gare.</span>
        </span>
      </label>
      <p v-if="draft.error" class="field-error">{{ draft.error }}</p>
    </form>

    <p v-if="loading" class="card p-6 text-center text-slate-600">Chargement…</p>
    <p v-else-if="loadError" class="field-error">{{ loadError }}</p>

    <template v-else>
      <!-- Communes et quartiers -->
      <div class="card divide-y overflow-hidden">
        <div class="hidden grid-cols-[1fr_8rem_15rem] gap-3 bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700 sm:grid">
          <span>Lieu · on livre ici ?</span><span>On ramasse ici ?</span><span>Prix de livraison</span>
        </div>
        <template v-for="c in visibleCommunes" :key="c.id">
          <ZonePriceRow :zone="c" :saving="busy[c.id]" :is-default="isDefault" :children-count="childrenOf(c.id).length" :open="opened.has(c.id) || !!q"
            @toggle="toggleActive(c)" @pickup="togglePickup(c)" @price="(v) => setPrice(c, v)" @expand="expand(c.id)" @rename="rename(c)" @remove="remove(c)"
          />
          <template v-if="opened.has(c.id) || q">
            <ZonePriceRow v-for="n in visibleChildren(c)" :key="n.id" :zone="n" :saving="busy[n.id]" :is-default="isDefault" :parent-price="c.price" :parent-pickup="c.is_pickup"
              @toggle="toggleActive(n)" @pickup="togglePickup(n)" @price="(v) => setPrice(n, v)" @rename="rename(n)" @remove="remove(n)"
            />
          </template>
        </template>
        <p v-if="!visibleCommunes.length" class="p-6 text-center text-sm text-slate-600">Aucun lieu ne correspond.</p>
      </div>

      <!-- Zones d'expédition -->
      <section v-if="shipping.length" class="space-y-2">
        <h2 class="pt-2 font-semibold">Zones d'expédition</h2>
        <p class="text-sm text-slate-600">Le prix est celui de la course jusqu'à la gare ; les frais du transporteur, payés par le livreur, sont facturés au marchand.</p>
        <div class="card divide-y overflow-hidden">
          <div v-for="z in shipping" :key="z.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
            <button type="button" role="switch" :aria-checked="z.is_active" :aria-label="`On livre ici : ${z.name}`" :class="switchClass(z.is_active)" @click="toggleActive(z)"><span :class="knobClass(z.is_active)" /></button>
            <span :class="['min-w-40 flex-1 font-medium', z.is_active ? '' : 'text-slate-600 line-through']">{{ z.name }}</span>
            <label class="flex items-center gap-2 text-xs text-slate-600">
              Frais habituels du transporteur
              <input :value="z.shipping_fee_estimate" type="number" min="0" class="input w-24 py-1" :aria-label="`Frais habituels : ${z.name}`" @change="setEstimate(z, $event.target.value)">
            </label>
            <label class="flex items-center gap-2 text-xs text-slate-600">
              Prix de la course
              <input :value="z.price" type="number" min="0" class="input w-28 py-1" :placeholder="isDefault ? 'Pas de prix' : 'grille normale'" :aria-label="`Prix : ${z.name}`" @change="setPrice(z, $event.target.value)">
            </label>
            <button type="button" class="text-xs text-blue-700" @click="rename(z)">Renommer</button>
            <button type="button" class="text-xs text-red-700" @click="remove(z)">Supprimer</button>
          </div>
        </div>
      </section>
    </template>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { confirmDialog, promptDialog } from '../../composables/useDialog'
import { money } from '../../utils/format'
import SearchSelect from '../SearchSelect.vue'
import ZonePriceRow from './ZonePriceRow.vue'

/**
 * Zones et prix : chaque lieu sur une ligne, avec son interrupteur « on livre ici » et son prix de livraison
 * (enregistré dès la saisie). Le prix range le lieu dans le groupe « depuis partout » de ce prix.
 */
const props = defineProps({ gridId: { type: Number, required: true }, isDefault: { type: Boolean, default: true } })

const FILTERS = [
  { value: 'all', label: 'Tous' },
  { value: 'active', label: 'Livrés' },
  { value: 'hidden', label: 'Masqués' },
  { value: 'missing', label: 'Sans prix' },
  { value: 'nopickup', label: 'Sans ramassage' },
]

const toasts = useToastStore()
const zones = ref([])
const loading = ref(true)
const loadError = ref('')
const query = ref('')
const filter = ref('all')
const opened = ref(new Set())
const busy = reactive({})
const adding = ref(false)
const draft = reactive({ name: '', parent_id: null, is_shipping: false, price: null, error: '', saving: false })

const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const q = computed(() => norm(query.value.trim()))
const communes = computed(() => zones.value.filter((z) => !z.parent_id && !z.is_shipping))
const shipping = computed(() => zones.value.filter((z) => z.is_shipping))
const childrenOf = (id) => zones.value.filter((z) => z.parent_id === id)
const missing = (z) => z.is_active && z.price === null && (z.parent_id === null || zones.value.find((p) => p.id === z.parent_id)?.price == null)
const missingCount = computed(() => communes.value.filter((c) => c.is_active && c.price === null).length)

function matches(z) {
  if (filter.value === 'active' && !z.is_active) return false
  if (filter.value === 'hidden' && z.is_active) return false
  if (filter.value === 'missing' && !missing(z)) return false
  if (filter.value === 'nopickup' && (!z.is_active || (z.is_pickup && zones.value.find((p) => p.id === z.parent_id)?.is_pickup !== false))) return false
  return !q.value || norm(z.name).includes(q.value)
}
function visibleChildren(c) {
  return childrenOf(c.id).filter((n) => (!q.value && filter.value === 'all') || matches(n))
}
const visibleCommunes = computed(() => communes.value.filter((c) => matches(c) || ((q.value || filter.value !== 'all') && childrenOf(c.id).some(matches))))

function expand(id) {
  const next = new Set(opened.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  opened.value = next
}

const switchClass = (on) => ['relative h-7 w-12 shrink-0 rounded-full', on ? 'bg-emerald-700' : 'bg-slate-400']
const knobClass = (on) => ['absolute top-[3px] h-[22px] w-[22px] rounded-full bg-white transition-all', on ? 'left-[23px]' : 'left-[3px]']

async function load() {
  try {
    zones.value = (await http.get(`/pricing-grids/${props.gridId}/zone-prices`)).data.data.zones
    loadError.value = ''
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function setPrice(zone, raw) {
  const price = raw === '' || raw === null ? null : Number(raw)
  if (price !== null && (Number.isNaN(price) || price < 0)) return
  if (price === zone.price) return
  busy[zone.id] = true
  try {
    const { data } = await http.put(`/pricing-grids/${props.gridId}/zone-prices/${zone.id}`, { price })
    zone.price = data.data.price
    // Les quartiers de cette commune affichent le nouveau prix repris
    childrenOf(zone.id).forEach((n) => { n.inherited_price = zone.price })
    toasts.success(price === null ? `${zone.name} : prix retiré.` : `${zone.name} : ${money(price)}.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy[zone.id] = false
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
    toasts.success(zone.is_pickup ? `Ramassage à ${zone.name}.` : `Plus de ramassage à ${zone.name}${childrenOf(zone.id).length ? ' ni dans ses quartiers' : ''}.`)
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
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e), { timeout: 10000 })
  }
}

async function create() {
  draft.error = ''
  draft.saving = true
  try {
    const { data } = await http.post('/zones', { name: draft.name, parent_id: draft.is_shipping ? null : draft.parent_id, is_shipping: draft.is_shipping })
    if (draft.price !== null && draft.price !== '') {
      await http.put(`/pricing-grids/${props.gridId}/zone-prices/${data.data.id}`, { price: Number(draft.price) })
    }
    toasts.success('Lieu ajouté.')
    if (draft.parent_id) opened.value = new Set([...opened.value, draft.parent_id])
    Object.assign(draft, { name: '', parent_id: null, price: null })
    await load()
  } catch (e) {
    draft.error = apiErrorMessage(e)
  } finally {
    draft.saving = false
  }
}

onMounted(load)
</script>
