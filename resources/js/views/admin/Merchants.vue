<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">E-commerçants</h1>
        <p class="text-sm text-slate-600">Cliquez sur un e-commerçant pour ouvrir sa fiche : informations, compte de connexion, paiement.</p>
      </div>
      <button v-if="auth.can('merchants.manage')" type="button" class="btn-primary" @click="openNew">+ Nouvel e-commerçant</button>
    </div>

    <SettingsShortcut :blocks="['merchantSignup', 'directory']" title="E-commerçants" intro="Comment les e-commerçants rejoignent votre entreprise." more="/admin/reglages/e-commercants" />

    <ConnectRequests v-if="auth.can('merchants.manage')" @accepted="load(meta?.current_page)" />

    <!-- Résumé : chaque case filtre la liste -->
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
      <button v-for="k in kpis" :key="k.key" type="button" :class="['card px-3 py-2 text-left transition hover:border-slate-400', k.active ? 'ring-2 ring-blue-500' : '', k.alert ? 'border-amber-200 bg-amber-50' : '']" @click="k.apply()">
        <span :class="['block text-xs', k.alert ? 'text-amber-900' : 'text-slate-600']">{{ k.label }}</span>
        <span class="block text-xl font-bold tabular-nums">{{ k.value }}</span>
      </button>
    </div>

    <!-- Filtres -->
    <div class="card flex flex-wrap items-end gap-2 p-3">
      <div class="min-w-[12rem] flex-1">
        <label class="label" for="m-search">Recherche</label>
        <input id="m-search" v-model="f.search" type="search" class="input" placeholder="Boutique, contact ou téléphone" @input="debounced">
      </div>
      <button type="button" class="btn-secondary md:hidden" :aria-expanded="moreFilters" @click="moreFilters = !moreFilters">Filtres{{ activeFilters ? ` (${activeFilters})` : '' }}</button>
      <div :class="[moreFilters ? 'flex' : 'hidden', 'w-full flex-wrap items-end gap-2 md:flex md:w-auto']">
        <div>
          <label class="label" for="m-status">Statut</label>
          <select id="m-status" v-model="f.status" class="input" @change="reload">
            <option value="">Tous</option><option value="active">Actifs</option><option value="suspended">Suspendus</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-source">Arrivée</label>
          <select id="m-source" v-model="f.source" class="input" @change="reload">
            <option value="">Toutes</option><option value="signup">Inscrits en ligne</option><option value="backoffice">Ajoutés par l'agence</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-zone">Zone de ramassage</label>
          <select id="m-zone" v-model="f.zone_id" class="input max-w-[14rem]" @change="reload">
            <option value="">Toutes</option>
            <option v-for="z in zones" :key="z.id" :value="String(z.id)">{{ z.full_name }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-located">Position</label>
          <select id="m-located" v-model="f.located" class="input" @change="reload">
            <option value="">Toutes</option><option value="1">Localisés</option><option value="0">Sans position</option>
          </select>
        </div>
      </div>
      <button v-if="filtered" type="button" class="btn-secondary" @click="reset">Effacer</button>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="card overflow-hidden">
      <!-- Ordinateur : tableau -->
      <div class="hidden overflow-x-auto md:block">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
            <tr>
              <th v-for="c in COLUMNS" :key="c.key" :class="['px-3 py-2.5 font-bold whitespace-nowrap', c.num ? 'text-right' : '']">
                <button v-if="c.sort" type="button" class="uppercase hover:text-slate-900" :aria-label="`Trier par ${c.label}`" @click="sortBy(c.sort)">
                  {{ c.label }}<span v-if="f.sort === c.sort" aria-hidden="true">{{ f.dir === 'desc' ? ' ▼' : ' ▲' }}</span>
                </button>
                <template v-else>{{ c.label }}</template>
              </th>
              <th class="px-3 py-2.5"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="m in rows" :key="m.id" :class="['cursor-pointer hover:bg-slate-50', m.status === 'active' ? '' : 'opacity-60']" @click="openMerchant(m)">
              <td class="px-3 py-2.5">
                <button type="button" class="block text-left font-semibold text-slate-900 hover:underline" @click.stop="openMerchant(m)">{{ m.business_name }}</button>
                <span v-if="m.source === 'signup'" class="mt-0.5 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800" :title="`Inscrit en ligne le ${day(m.created_at)}`">
                  {{ isNew(m) ? 'Nouveau · ' : '' }}inscrit en ligne
                </span>
              </td>
              <td class="px-3 py-2.5">{{ m.contact_name || '—' }}<span class="block text-xs text-slate-600">{{ m.phone }}</span></td>
              <td class="max-w-[16rem] px-3 py-2.5">
                {{ m.pickup_zone?.name || '—' }}
                <span v-if="m.pickup_address" class="block truncate text-xs text-slate-600">{{ m.pickup_address }}</span>
                <span v-if="m.pickup_lat == null" class="block text-xs font-semibold text-amber-800">⚠ sans position</span>
              </td>
              <td class="px-3 py-2.5 text-xs text-slate-600 whitespace-nowrap">{{ m.default_fee_payer === 'recipient' ? 'Destinataire' : 'E-commerçant' }}</td>
              <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ m.orders_count || '—' }}</td>
              <td class="px-3 py-2.5">
                <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', m.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800']">{{ m.status === 'active' ? 'Actif' : 'Suspendu' }}</span>
              </td>
              <td class="px-3 py-2.5 text-right whitespace-nowrap" @click.stop>
                <RouterLink :to="{ path: '/admin/courses', query: { merchant_id: m.id } }" class="btn-secondary !px-2 !py-1 text-xs">Courses</RouterLink>
                <button type="button" class="btn-secondary ml-1 !px-2 !py-1 text-xs" @click="openMerchant(m)">Ouvrir</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Téléphone : lignes -->
      <ul class="divide-y divide-slate-100 md:hidden">
        <li v-for="m in rows" :key="m.id">
          <button type="button" :class="['flex w-full items-center gap-3 px-4 py-3 text-left', m.status === 'active' ? '' : 'opacity-60']" @click="openMerchant(m)">
            <span class="min-w-0 flex-1">
              <span class="block truncate font-semibold">{{ m.business_name }}</span>
              <span class="block truncate text-xs text-slate-600">{{ m.phone }}<template v-if="m.pickup_zone"> · {{ m.pickup_zone.name }}</template> · {{ m.orders_count }} course{{ m.orders_count > 1 ? 's' : '' }}</span>
              <span v-if="m.status !== 'active' || m.pickup_lat == null || m.source === 'signup'" class="block text-xs font-semibold">
                <span v-if="m.status !== 'active'" class="text-red-700">Suspendu </span>
                <span v-if="m.pickup_lat == null" class="text-amber-800">⚠ sans position </span>
                <span v-if="m.source === 'signup'" class="text-emerald-800">{{ isNew(m) ? 'Nouveau · ' : '' }}inscrit en ligne</span>
              </span>
            </span>
            <span class="text-slate-600" aria-hidden="true">›</span>
          </button>
        </li>
      </ul>

      <p v-if="loaded && !rows.length" class="p-6 text-center text-slate-600">Aucun e-commerçant ne correspond.</p>
      <p v-if="!loaded" class="p-6 text-center text-slate-600">Chargement…</p>
      <div class="flex flex-wrap items-center justify-between gap-2 border-t px-3">
        <Pagination :meta="meta" class="flex-1" @change="(p) => load(p)" />
        <label v-if="meta && meta.total > 10" class="flex items-center gap-2 py-3 text-xs text-slate-600">
          Par page
          <select v-model="f.per_page" class="input w-20 py-1" @change="reload"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
        </label>
      </div>
    </div>

    <!-- Fiche de l'e-commerçant : informations, compte de connexion, paiement -->
    <Modal :open="form.open" wide :title="form.id ? form.name : 'Nouvel e-commerçant'" @close="close">
      <div class="space-y-4">
        <div v-if="form.id" class="flex flex-wrap items-center justify-between gap-2">
          <Onglets v-model="tab" :items="tabs" label="Fiche de l'e-commerçant" class="flex-1" />
          <RouterLink :to="{ path: '/admin/courses', query: { merchant_id: form.id } }" class="btn-secondary !py-1 text-sm">Voir ses courses</RouterLink>
        </div>
        <form v-if="tab === 'fiche' && auth.can('merchants.manage')" class="space-y-3" @submit.prevent="save">
          <div v-if="form.error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ form.error }}</div>
          <div><label class="label">Nom commercial *</label><input v-model="form.data.business_name" class="input" required></div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Contact</label><input v-model="form.data.contact_name" class="input"></div>
            <div><label class="label">Téléphone *</label><input v-model="form.data.phone" class="input" required></div>
            <div><label class="label">WhatsApp</label><input v-model="form.data.whatsapp_phone" class="input"></div>
            <div><label class="label">E-mail</label><input v-model="form.data.email" type="email" class="input"></div>
          </div>
          <div>
            <label class="label">Zone de ramassage</label>
            <SearchSelect
              v-model="form.data.pickup_zone_id" placeholder="—" :empty-value="null" aria-label="Zone de ramassage" search-placeholder="Commune, quartier…"
              :options="zones.filter((z) => z.is_pickup !== false || z.id === form.data.pickup_zone_id).map((z) => ({ value: z.id, label: `${z.is_shipping ? '🚌 ' : ''}${z.full_name}`, search: z.name }))"
            />
          </div>
          <div><label class="label">Adresse de ramassage</label><input v-model="form.data.pickup_address" class="input"></div>
          <div><label class="label">Repère</label><input v-model="form.data.pickup_landmark" class="input"></div>
          <fieldset class="border rounded p-3 space-y-2">
            <legend class="text-sm font-medium px-1">Position de ramassage (carte des livreurs)</legend>
            <p v-if="form.source && !locationChanged" class="text-xs text-slate-600">
              {{ form.source === 'courier' ? 'Estimée d\'après les ramassages des livreurs' : form.source === 'merchant' ? 'Indiquée par le marchand depuis sa boutique' : 'Saisie par l\'agence' }}
            </p>
            <p class="text-xs text-slate-600">Collez le lien Google Maps envoyé par le marchand (« Partager » → « Copier le lien »), ou placez le repère sur la carte.</p>
            <LocationPicker v-model="form.location" allow-link height="h-56" gps-label="📍 Ma position (sur place)" />
            <button v-if="form.location" type="button" class="text-xs text-red-600" @click="form.location = null">Retirer la position</button>
          </fieldset>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Grille tarifaire</label>
              <SearchSelect
                v-model="form.data.pricing_grid_id" placeholder="Grille par défaut" :empty-value="null" aria-label="Grille tarifaire" search-placeholder="Grille…"
                :options="grids.map((g) => ({ value: g.id, label: g.name }))"
              />
            </div>
            <div>
              <label class="label">Frais payés par</label>
              <select v-model="form.data.default_fee_payer" class="input">
                <option value="merchant">Marchand</option>
                <option value="recipient">Destinataire</option>
              </select>
            </div>
          </div>
          <p v-if="form.id && auth.can('users.manage')" class="rounded-lg bg-slate-50 p-2 text-xs text-slate-600">
            Le téléphone ci-dessus est celui de la boutique. Pour changer le numéro ou le mot de passe de connexion :
            <button type="button" class="font-semibold text-blue-600 underline" @click="tab = 'compte'">onglet Compte</button>.
          </p>
          <div v-if="form.id">
            <label class="label">Statut</label>
            <select v-model="form.data.status" class="input">
              <option value="active">Actif</option>
              <option value="suspended">Suspendu</option>
            </select>
          </div>
          <fieldset v-else class="border rounded p-3 space-y-2">
            <legend class="text-sm font-medium px-1">Compte de connexion (facultatif)</legend>
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label">Nom</label><input v-model="form.owner.name" class="input"></div>
              <div><label class="label">Téléphone</label><input v-model="form.owner.phone" class="input"></div>
              <div class="col-span-2"><label class="label">Mot de passe (8 caractères min.)</label><input v-model="form.owner.password" type="text" class="input" autocomplete="new-password"></div>
            </div>
          </fieldset>
          <div><label class="label">Notes internes</label><textarea v-model="form.data.notes" rows="2" class="input" /></div>
          <button class="btn-primary w-full" :disabled="form.saving">Enregistrer</button>
        </form>
        <!-- Sans droit de modifier : lecture seule -->
        <dl v-if="tab === 'fiche' && form.id && !auth.can('merchants.manage')" class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
          <div><dt class="text-slate-600">Contact</dt><dd>{{ form.data.contact_name || '—' }} · {{ form.data.phone }}</dd></div>
          <div><dt class="text-slate-600">Adresse de ramassage</dt><dd>{{ form.data.pickup_address || '—' }}</dd></div>
          <div><dt class="text-slate-600">Frais payés par</dt><dd>{{ form.data.default_fee_payer === 'recipient' ? 'Destinataire' : 'E-commerçant' }}</dd></div>
          <div><dt class="text-slate-600">Statut</dt><dd>{{ form.data.status === 'active' ? 'Actif' : 'Suspendu' }}</dd></div>
        </dl>
        <MerchantAccounts v-if="tab === 'compte' && form.id" :merchant="{ id: form.id, business_name: form.name }" @changed="load(meta?.current_page)" />
        <PaymentMethodsManager v-if="tab === 'paiement' && form.id" :merchant-id="form.id" :can-edit="auth.can('finance.manage')" />
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import ConnectRequests from '../../components/ConnectRequests.vue'
import LocationPicker from '../../components/LocationPicker.vue'
import Modal from '../../components/Modal.vue'
import Pagination from '../../components/Pagination.vue'
import SearchSelect from '../../components/SearchSelect.vue'
import MerchantAccounts from '../../components/merchant/MerchantAccounts.vue'
import PaymentMethodsManager from '../../components/payment/PaymentMethodsManager.vue'
import SettingsShortcut from '../../components/settings/SettingsShortcut.vue'
import Onglets from '../../components/ui/Onglets.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

/**
 * E-commerçants : liste paginée (recherche, filtres, tri), résumé cliquable, et une fiche unique à onglets
 * (informations, compte de connexion, paiement) à la place des boutons de chaque ligne.
 */
const COLUMNS = [
  { key: 'name', label: 'E-commerçant', sort: 'name' },
  { key: 'contact', label: 'Contact' },
  { key: 'pickup', label: 'Ramassage' },
  { key: 'fees', label: 'Frais payés par' },
  { key: 'orders', label: 'Courses', sort: 'orders', num: true },
  { key: 'status', label: 'Statut' },
]
const DEFAULTS = { search: '', status: '', source: '', zone_id: '', located: '', sort: 'name', dir: 'asc', per_page: '25' }
const FIELDS = ['business_name', 'contact_name', 'phone', 'whatsapp_phone', 'email', 'pickup_zone_id', 'pickup_address',
  'pickup_landmark', 'pricing_grid_id', 'default_fee_payer', 'status', 'notes']
const SOURCES = { merchant: 'par le marchand', staff: 'par l\'agence', courier: 'estimée (livreurs)' }

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const f = reactive({ ...DEFAULTS, ...Object.fromEntries(Object.entries(route.query).filter(([k]) => k in DEFAULTS).map(([k, v]) => [k, String(v)])) })
// Lien de la notification « Nouvel e-commerçant » : ?nouveaux=1
if (route.query.nouveaux) Object.assign(f, { source: 'signup', sort: 'created_at', dir: 'desc' })
// Depuis la page Utilisateurs : la boutique cherchée par son nom
if (typeof route.query.q === 'string') f.search = route.query.q

const rows = ref([])
const meta = ref(null)
const summary = ref({})
const zones = ref([])
const grids = ref([])
const loaded = ref(false)
const error = ref('')
const moreFilters = ref(false)
const tab = ref('fiche')

const filtered = computed(() => ['search', 'status', 'source', 'zone_id', 'located'].some((k) => f[k] !== ''))
const activeFilters = computed(() => ['status', 'source', 'zone_id', 'located'].filter((k) => f[k] !== '').length)
const kpis = computed(() => {
  const s = summary.value
  return [
    { key: 'all', label: 'E-commerçants', value: s.merchants ?? '—', active: !filtered.value, apply: reset },
    { key: 'active', label: 'Actifs', value: s.active ?? '—', active: f.status === 'active' && !f.located, apply: () => toggle({ status: 'active' }) },
    { key: 'suspended', label: 'Suspendus', value: s.suspended ?? 0, active: f.status === 'suspended', apply: () => toggle({ status: 'suspended' }) },
    { key: 'new', label: 'Inscrits cette semaine', value: s.signups_new ?? 0, active: f.source === 'signup' && f.sort === 'created_at', alert: s.signups_new > 0, apply: () => toggle({ source: 'signup' }, { sort: 'created_at', dir: 'desc' }) },
    { key: 'located', label: 'Sans position', value: s.without_location ?? 0, active: f.located === '0', alert: s.without_location > 0, apply: () => toggle({ located: '0', status: 'active' }) },
  ]
})
const tabs = computed(() => [
  { value: 'fiche', label: 'Informations' },
  ...(auth.can('users.manage') ? [{ value: 'compte', label: 'Compte de connexion' }] : []),
  ...(auth.can('finance.view') ? [{ value: 'paiement', label: 'Paiement' }] : []),
])

const form = reactive({ open: false, id: null, name: '', data: {}, owner: {}, error: '', saving: false, location: null, initialLocation: null, source: null })
const locationChanged = computed(() => form.location?.lat !== form.initialLocation?.lat || form.location?.lng !== form.initialLocation?.lng)

const day = (iso) => new Date(iso).toLocaleDateString('fr-FR')
// Inscrit depuis moins de 7 jours
const isNew = (m) => Date.now() - new Date(m.created_at).getTime() < 7 * 86400000

async function load(page = 1) {
  error.value = ''
  const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))
  // Adresse de la page : garde les filtres
  router.replace({ query: { ...Object.fromEntries(Object.entries(f).filter(([k, v]) => v !== DEFAULTS[k])), ...(form.open && form.id ? { id: form.id, onglet: tab.value === 'fiche' ? undefined : tab.value } : {}) } })
  try {
    const { data } = await http.get('/merchants', { params: { ...params, page, summary: 1 } })
    rows.value = data.data
    meta.value = data.meta
    summary.value = data.summary || {}
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loaded.value = true
  }
}

const reload = () => load(1)
let timer = null
function debounced() {
  clearTimeout(timer)
  timer = setTimeout(reload, 300)
}
// Case du résumé : la recliquer enlève son filtre
function toggle(filters, extra = {}) {
  const on = Object.entries(filters).every(([k, v]) => f[k] === v)
  Object.assign(f, { ...DEFAULTS, search: f.search, per_page: f.per_page }, on ? {} : { ...filters, ...extra })
  reload()
}
function reset() {
  Object.assign(f, { ...DEFAULTS, per_page: f.per_page })
  reload()
}
function sortBy(key) {
  f.dir = f.sort === key ? (f.dir === 'desc' ? 'asc' : 'desc') : key === 'name' ? 'asc' : 'desc'
  f.sort = key
  reload()
}

function fill(merchant) {
  form.id = merchant?.id ?? null
  form.name = merchant?.business_name ?? ''
  form.data = Object.fromEntries(FIELDS.map((k) => [k, merchant?.[k] ?? null]))
  form.data.default_fee_payer ??= 'merchant'
  form.data.status ??= 'active'
  form.owner = { name: '', phone: '', password: '' }
  form.location = merchant?.pickup_lat != null ? { lat: merchant.pickup_lat, lng: merchant.pickup_lng, accuracy: null } : null
  form.initialLocation = form.location ? { ...form.location } : null
  form.source = merchant?.pickup_location_source ?? null
  form.error = ''
}

function openNew() {
  fill(null)
  tab.value = 'fiche'
  form.open = true
}

// Fiche : rechargée (à jour, et trouvée même hors de la page de la liste)
async function openMerchant(m, onglet = 'fiche') {
  try {
    const merchant = (await http.get(`/merchants/${m.id}`)).data.data
    fill(merchant)
    tab.value = tabs.value.some((t) => t.value === onglet) ? onglet : 'fiche'
    form.open = true
    router.replace({ query: { ...route.query, id: merchant.id, onglet: tab.value === 'fiche' ? undefined : tab.value } })
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function close() {
  form.open = false
  const { id, onglet, ...query } = route.query
  router.replace({ query })
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const payload = Object.fromEntries(Object.entries(form.data).filter(([k, v]) => v !== '' && (v !== null || form.id) && !(k === 'status' && !form.id)))
    if (!form.id && form.owner.phone) payload.owner = { ...form.owner, name: form.owner.name || form.data.contact_name || form.data.business_name }
    const { data } = form.id ? await http.patch(`/merchants/${form.id}`, payload) : await http.post('/merchants', payload)
    // Position : seulement si elle a changé (garde la source « marchand » ou « livreurs » sinon)
    if (locationChanged.value) {
      await http.put(`/merchants/${data.data.id}/pickup-location`, { lat: form.location?.lat ?? null, lng: form.location?.lng ?? null })
    }
    toasts.success('E-commerçant enregistré.')
    close()
    load(meta.value?.current_page)
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

onMounted(async () => {
  load()
  // Liens de la caisse, des alertes ou de la page Utilisateurs : ?id=…&onglet=paiement|compte
  if (route.query.id) openMerchant({ id: Number(route.query.id) }, route.query.onglet || 'fiche')
  zones.value = (await http.get('/zones')).data.data
  if (auth.can('settings.manage')) grids.value = (await http.get('/pricing-grids')).data.data
})
</script>
