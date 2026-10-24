<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Véhicules</h1>
        <p class="text-sm text-gray-500">Le parc, à qui chaque véhicule est confié, et les papiers à renouveler.</p>
      </div>
      <button v-if="canManage" class="btn-primary" @click="openForm()">+ Ajouter un véhicule</button>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="card p-4"><p class="text-sm text-gray-500">En service</p><p class="text-2xl font-bold">{{ counts.active }}</p></div>
      <div class="card p-4"><p class="text-sm text-gray-500">Pas confiés</p><p class="text-2xl font-bold">{{ counts.free }}</p></div>
      <div :class="['card p-4', counts.expired ? 'border-red-200 bg-red-50' : '']">
        <p :class="['text-sm', counts.expired ? 'text-red-800' : 'text-gray-500']">Papiers expirés</p>
        <p :class="['text-2xl font-bold', counts.expired ? 'text-red-700' : '']">{{ counts.expired }}</p>
      </div>
      <div :class="['card p-4', counts.soon ? 'border-amber-200 bg-amber-50' : '']">
        <p :class="['text-sm', counts.soon ? 'text-amber-800' : 'text-gray-500']">À renouveler sous {{ options.warn_days || 15 }} jours</p>
        <p :class="['text-2xl font-bold', counts.soon ? 'text-amber-700' : '']">{{ counts.soon }}</p>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="tablist">
        <button type="button" role="tab" :aria-selected="tab === 'vehicles'" :class="['rounded-md px-3 py-1.5 font-medium', tab === 'vehicles' ? 'bg-white shadow-sm' : 'text-gray-600']" @click="tab = 'vehicles'">Véhicules ({{ vehicles.length }})</button>
        <button type="button" role="tab" :aria-selected="tab === 'licenses'" :class="['rounded-md px-3 py-1.5 font-medium', tab === 'licenses' ? 'bg-white shadow-sm' : 'text-gray-600']" @click="tab = 'licenses'">Permis des livreurs</button>
      </div>
      <template v-if="tab === 'vehicles'">
        <input v-model.trim="filters.q" type="search" class="input w-auto flex-1 min-w-[10rem]" placeholder="Plaque, marque, livreur…" aria-label="Rechercher un véhicule">
        <select v-model="filters.status" class="input w-auto" aria-label="État">
          <option value="">Tous</option>
          <option v-for="(label, value) in options.statuses" :key="value" :value="value">{{ label }}</option>
        </select>
        <label class="flex items-center gap-2 text-sm"><input v-model="filters.todo" type="checkbox"> À régulariser</label>
      </template>
    </div>

    <!-- Véhicules -->
    <div v-if="tab === 'vehicles'" class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <button v-for="v in shown" :key="v.id" type="button" class="card p-4 text-left space-y-2 hover:border-blue-300" @click="openDetail(v)">
        <div class="flex items-start gap-3">
          <AuthImage :src="v.photo_url" :alt="v.label" class="h-14 w-14 shrink-0 rounded-lg object-cover bg-slate-100">
            <span class="h-14 w-14 shrink-0 rounded-lg bg-slate-100 grid place-items-center text-2xl" aria-hidden="true">{{ ICONS[v.type] }}</span>
          </AuthImage>
          <div class="min-w-0 flex-1">
            <p class="font-semibold truncate">{{ v.plate || 'Sans plaque' }}</p>
            <p class="text-sm text-gray-600 truncate">{{ [v.type_label, v.brand, v.model, v.color].filter(Boolean).join(' · ') }}</p>
            <p class="text-sm" :class="v.courier ? 'text-gray-800' : 'text-gray-400'">{{ v.courier ? `🛵 ${v.courier.name}` : 'Pas confié' }}</p>
          </div>
          <span :class="['text-xs rounded-full px-2 py-0.5 shrink-0', STATUS_CLASS[v.status]]">{{ v.status_label }}</span>
        </div>
        <div class="flex flex-wrap gap-1">
          <span v-if="v.insurance_state === 'missing'" class="text-xs rounded-full px-2 py-0.5 bg-amber-100 text-amber-800">Assurance à renseigner</span>
          <span v-for="d in v.documents" :key="d.id" :class="['text-xs rounded-full px-2 py-0.5', STATE_CLASS[d.state]]">{{ d.label }} {{ stateText(d) }}</span>
        </div>
      </button>
      <p v-if="!shown.length" class="text-gray-500">{{ vehicles.length ? 'Aucun véhicule ne correspond.' : 'Aucun véhicule. Ajoutez le premier.' }}</p>
    </div>

    <!-- Permis des livreurs -->
    <div v-else class="card divide-y">
      <div v-for="c in couriers" :key="c.id" class="flex flex-wrap items-center gap-3 p-3">
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ c.name }}</p>
          <p class="text-sm text-gray-500">{{ vehicleOf(c)?.label || 'Pas de véhicule confié' }}</p>
        </div>
        <span v-if="license(c)" :class="['text-xs rounded-full px-2 py-0.5', STATE_CLASS[license(c).state]]">Permis {{ stateText(license(c)) }}</span>
        <span v-else class="text-xs rounded-full px-2 py-0.5 bg-slate-100 text-gray-600">Permis non renseigné</span>
        <button v-if="license(c)?.file_url" class="text-sm text-blue-600" @click="openFile(license(c))">Voir</button>
        <button v-if="canManage" class="btn-secondary" @click="openDocument({ courier_id: c.id }, license(c))">{{ license(c) ? 'Renouveler' : 'Ajouter' }}</button>
      </div>
      <p v-if="!couriers.length" class="p-3 text-gray-500">Aucun livreur actif.</p>
    </div>

    <!-- Fiche du véhicule -->
    <Modal :open="!!detail" :title="detail ? detail.label : ''" @close="closeDetail">
      <div v-if="detail" class="space-y-4">
        <div class="flex items-start gap-3">
          <AuthImage :src="detail.photo_url" :alt="detail.label" class="h-24 w-24 rounded-lg object-cover bg-slate-100">
            <span class="h-24 w-24 rounded-lg bg-slate-100 grid place-items-center text-4xl" aria-hidden="true">{{ ICONS[detail.type] }}</span>
          </AuthImage>
          <div class="text-sm space-y-1">
            <p>{{ [detail.type_label, detail.brand, detail.model, detail.color].filter(Boolean).join(' · ') }}</p>
            <p>Propriétaire : {{ detail.owner_label }}<template v-if="detail.owner_name"> ({{ detail.owner_name }})</template></p>
            <p>État : <span :class="['rounded-full px-2 py-0.5 text-xs', STATUS_CLASS[detail.status]]">{{ detail.status_label }}</span></p>
            <p v-if="detail.notes" class="text-gray-600">{{ detail.notes }}</p>
            <div v-if="canManage" class="flex flex-wrap gap-3 pt-1">
              <button class="text-blue-600" @click="openForm(detail)">Modifier</button>
              <label class="text-blue-600 cursor-pointer">
                {{ detail.photo_url ? 'Changer la photo' : 'Ajouter une photo' }}
                <input type="file" accept="image/*" capture="environment" class="sr-only" @change="uploadPhoto">
              </label>
            </div>
          </div>
        </div>

        <section class="space-y-2">
          <h3 class="font-semibold">Confié à</h3>
          <div v-if="canManage && detail.status !== 'retired'" class="flex gap-2">
            <select v-model="assignTo" class="input" aria-label="Livreur">
              <option :value="null">Personne</option>
              <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}{{ c.vehicle_id && c.vehicle_id !== detail.id ? ' (a déjà un véhicule)' : '' }}</option>
            </select>
            <button class="btn-primary" :disabled="busy || assignTo === (detail.courier?.id ?? null)" @click="assign">OK</button>
          </div>
          <p v-else class="text-sm">{{ detail.courier?.name || 'Personne' }}</p>
          <p v-if="assignNote" class="text-xs text-gray-500">{{ assignNote }}</p>
        </section>

        <section class="space-y-2">
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">Papiers</h3>
            <button v-if="canManage" class="text-sm font-semibold text-blue-600" @click="openDocument({ vehicle_id: detail.id })">+ Ajouter un papier</button>
          </div>
          <p v-if="detail.insurance_state === 'missing'" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">Aucune assurance enregistrée : le dispatch le voit en orange.</p>
          <div v-for="d in (detail.all_documents || detail.documents)" :key="d.id" class="flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm">
            <div class="min-w-0 flex-1">
              <p class="font-medium">{{ d.label }}<span v-if="d.number" class="text-gray-500"> · n° {{ d.number }}</span></p>
              <p class="text-xs text-gray-500">{{ d.expires_at ? `Fin le ${date(d.expires_at)}` : 'Sans date de fin' }}<template v-if="d.notes"> · {{ d.notes }}</template></p>
            </div>
            <span v-if="d.state !== 'none'" :class="['text-xs rounded-full px-2 py-0.5', STATE_CLASS[d.state]]">{{ stateText(d) }}</span>
            <button v-if="d.file_url" class="text-blue-600" @click="openFile(d)">Voir</button>
            <button v-if="canManage" class="text-blue-600" @click="openDocument({ vehicle_id: detail.id }, d, true)">Modifier</button>
            <button v-if="canManage" class="text-red-600" @click="removeDocument(d)">Supprimer</button>
          </div>
          <p v-if="!(detail.all_documents || detail.documents)?.length" class="text-sm text-gray-500">Aucun papier enregistré.</p>
        </section>

        <section v-if="detail.history?.length" class="space-y-1">
          <h3 class="font-semibold">Historique</h3>
          <p v-for="(h, i) in detail.history" :key="i" class="text-sm text-gray-600">
            {{ h.courier || 'Livreur supprimé' }} : du {{ date(h.started_at) }} {{ h.ended_at ? `au ${date(h.ended_at)}` : 'à aujourd’hui' }}
          </p>
        </section>
      </div>
    </Modal>

    <!-- Ajout ou modification d'un véhicule -->
    <Modal :open="form.open" :title="form.id ? 'Modifier le véhicule' : 'Nouveau véhicule'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="label" for="v-type">Type</label>
            <select id="v-type" v-model="form.data.type" class="input">
              <option v-for="(label, value) in options.types" :key="value" :value="value">{{ label }}</option>
            </select>
          </div>
          <div><label class="label" for="v-plate">Immatriculation</label><input id="v-plate" v-model="form.data.plate" class="input uppercase" placeholder="1234 AB 01"></div>
          <div><label class="label" for="v-brand">Marque</label><input id="v-brand" v-model="form.data.brand" class="input" placeholder="Haojue, Yamaha…"></div>
          <div><label class="label" for="v-model">Modèle</label><input id="v-model" v-model="form.data.model" class="input"></div>
          <div><label class="label" for="v-color">Couleur</label><input id="v-color" v-model="form.data.color" class="input"></div>
          <div>
            <label class="label" for="v-owner">Propriétaire</label>
            <select id="v-owner" v-model="form.data.owner" class="input">
              <option :value="null">À préciser</option>
              <option v-for="(label, value) in options.owners" :key="value" :value="value">{{ label }}</option>
            </select>
          </div>
        </div>
        <div v-if="form.data.owner && form.data.owner !== 'company'">
          <label class="label" for="v-owner-name">{{ form.data.owner === 'lessor' ? 'Nom du loueur' : 'Nom du propriétaire (si besoin)' }}</label>
          <input id="v-owner-name" v-model="form.data.owner_name" class="input">
        </div>
        <div v-if="form.id">
          <label class="label" for="v-status">État</label>
          <select id="v-status" v-model="form.data.status" class="input">
            <option v-for="(label, value) in options.statuses" :key="value" :value="value">{{ label }}</option>
          </select>
          <p v-if="form.data.status === 'retired'" class="text-xs text-gray-500 mt-1">Retiré du parc : il ne sera plus confié à personne.</p>
        </div>
        <div v-else>
          <label class="label" for="v-courier">Confié à</label>
          <select id="v-courier" v-model="form.data.courier_id" class="input">
            <option :value="null">Personne pour l'instant</option>
            <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}{{ c.vehicle_id ? ' (a déjà un véhicule)' : '' }}</option>
          </select>
        </div>
        <div><label class="label" for="v-notes">Notes</label><textarea id="v-notes" v-model="form.data.notes" rows="2" class="input" /></div>
        <p v-if="form.error" class="field-error">{{ form.error }}</p>
        <button class="btn-primary w-full" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </form>
    </Modal>

    <!-- Papier : véhicule ou permis du livreur -->
    <Modal :open="doc.open" :title="doc.id ? 'Modifier le papier' : doc.data.courier_id ? 'Permis du livreur' : 'Nouveau papier'" @close="doc.open = false">
      <form class="space-y-3" @submit.prevent="saveDocument">
        <div v-if="!doc.data.courier_id">
          <label class="label" for="d-type">Papier</label>
          <select id="d-type" v-model="doc.data.type" class="input">
            <option v-for="(label, value) in options.vehicle_documents" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="label" for="d-number">Numéro</label><input id="d-number" v-model="doc.data.number" class="input"></div>
          <div><label class="label" for="d-end">Date de fin</label><input id="d-end" v-model="doc.data.expires_at" type="date" class="input"></div>
        </div>
        <div>
          <label class="label" for="d-file">Photo ou PDF du papier</label>
          <input id="d-file" type="file" accept="image/*,application/pdf" class="input" @change="doc.file = $event.target.files[0] || null">
          <p v-if="doc.id && doc.hasFile" class="text-xs text-gray-500 mt-1">Une photo est déjà enregistrée : choisissez-en une seulement pour la remplacer.</p>
        </div>
        <div><label class="label" for="d-notes">Notes</label><input id="d-notes" v-model="doc.data.notes" class="input" placeholder="Assureur, agence…"></div>
        <p class="text-xs text-gray-500">Les responsables sont prévenus {{ options.warn_days || 15 }} jours avant la date de fin, puis le jour même.</p>
        <p v-if="doc.error" class="field-error">{{ doc.error }}</p>
        <button class="btn-primary w-full" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import AuthImage from '../../components/AuthImage.vue'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { date } from '../../utils/format'

const ICONS = { moto: '🛵', velo: '🚲', voiture: '🚗', tricycle: '🛺' }
const STATUS_CLASS = { active: 'bg-emerald-100 text-emerald-700', repair: 'bg-amber-100 text-amber-800', retired: 'bg-slate-100 text-gray-600' }
const STATE_CLASS = { valid: 'bg-emerald-100 text-emerald-700', soon: 'bg-amber-100 text-amber-800', expired: 'bg-red-100 text-red-700', none: 'bg-slate-100 text-gray-600' }

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const canManage = computed(() => auth.can('users.manage'))

const vehicles = ref([])
const couriers = ref([])
const options = ref({})
const error = ref('')
const busy = ref(false)
const tab = ref(route.query.livreurs ? 'licenses' : 'vehicles')
const filters = reactive({ q: '', status: '', todo: false })
const detail = ref(null)
const assignTo = ref(null)
const assignNote = ref('')
const form = reactive({ open: false, id: null, data: {}, error: '' })
const doc = reactive({ open: false, id: null, data: {}, file: null, hasFile: false, error: '' })

const todo = (v) => v.insurance_state === 'missing' || ['expired', 'soon'].includes(v.documents_state) || v.status === 'repair'
const shown = computed(() => {
  const q = filters.q.toLowerCase()
  return vehicles.value.filter((v) => (!filters.status || v.status === filters.status)
    && (!filters.todo || todo(v))
    && (!q || [v.plate, v.brand, v.model, v.courier?.name].some((s) => s?.toLowerCase().includes(q))))
})
const counts = computed(() => {
  const active = vehicles.value.filter((v) => v.status !== 'retired')
  const docs = [...active.flatMap((v) => v.documents || []), ...couriers.value.flatMap((c) => c.documents || [])]
  return {
    active: active.filter((v) => v.status === 'active').length,
    free: active.filter((v) => !v.courier).length,
    expired: docs.filter((d) => d.state === 'expired').length,
    soon: docs.filter((d) => d.state === 'soon').length,
  }
})

const license = (c) => (c.documents || []).find((d) => d.type === 'license')
const vehicleOf = (c) => vehicles.value.find((v) => v.id === c.vehicle_id)

function stateText(d) {
  // Permis, autre papier : masculin ; carte grise, assurance, visite, vignette : féminin
  if (d.state === 'expired') return `${['license', 'other'].includes(d.type) ? 'expiré' : 'expirée'}${d.days_left !== null ? ` depuis ${-d.days_left} j` : ''}`
  if (d.state === 'soon') return d.days_left === 0 ? 'fin aujourd’hui' : `fin dans ${d.days_left} j`
  if (d.state === 'valid') return `jusqu’au ${date(d.expires_at)}`
  return ''
}

async function load() {
  try {
    const { data } = await http.get('/vehicles')
    vehicles.value = data.data
    couriers.value = data.couriers
    options.value = data.options
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function openDetail(v) {
  detail.value = v
  assignTo.value = v.courier?.id ?? null
  assignNote.value = ''
  router.replace({ query: { ...route.query, id: v.id } })
  try {
    detail.value = (await http.get(`/vehicles/${v.id}`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function closeDetail() {
  detail.value = null
  const { id, ...query } = route.query
  router.replace({ query })
}

async function refreshDetail() {
  await load()
  if (detail.value) detail.value = (await http.get(`/vehicles/${detail.value.id}`)).data.data
}

async function assign() {
  busy.value = true
  try {
    const before = couriers.value.find((c) => c.id === assignTo.value)
    await http.post(`/vehicles/${detail.value.id}/assign`, { courier_id: assignTo.value })
    assignNote.value = before?.vehicle_id && before.vehicle_id !== detail.value.id ? `Son ancien véhicule lui a été repris.` : ''
    await refreshDetail()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function uploadPhoto(event) {
  const file = event.target.files[0]
  if (!file) return
  const body = new FormData()
  body.append('file', file)
  busy.value = true
  try {
    await http.post(`/vehicles/${detail.value.id}/photo`, body)
    await refreshDetail()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
    event.target.value = ''
  }
}

function openForm(v = null) {
  form.id = v?.id ?? null
  form.data = v
    ? { type: v.type, plate: v.plate, brand: v.brand, model: v.model, color: v.color, owner: v.owner, owner_name: v.owner_name, status: v.status, notes: v.notes }
    : { type: 'moto', plate: '', brand: '', model: '', color: '', owner: 'company', owner_name: '', courier_id: null, notes: '' }
  form.error = ''
  form.open = true
}

async function save() {
  busy.value = true
  try {
    if (form.id) await http.patch(`/vehicles/${form.id}`, form.data)
    else await http.post('/vehicles', form.data)
    form.open = false
    await refreshDetail()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

// owner : { vehicle_id } ou { courier_id } ; renouveler (sans edit) : nouveau papier du même type
function openDocument(owner, current = null, edit = false) {
  doc.id = edit ? current.id : null
  doc.data = {
    ...owner,
    type: current?.type || (owner.courier_id ? 'license' : 'insurance'),
    number: edit ? current.number : '',
    expires_at: edit ? current.expires_at : '',
    notes: edit ? current.notes : '',
  }
  doc.file = null
  doc.hasFile = edit && !!current?.file_url
  doc.error = ''
  doc.open = true
}

async function saveDocument() {
  const body = new FormData()
  Object.entries(doc.data).forEach(([key, value]) => value !== null && value !== undefined && body.append(key, value))
  if (doc.file) body.append('file', doc.file)
  busy.value = true
  try {
    await http.post(doc.id ? `/vehicle-documents/${doc.id}` : '/vehicle-documents', body)
    doc.open = false
    await refreshDetail()
  } catch (e) {
    doc.error = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function removeDocument(d) {
  if (!window.confirm(`Supprimer ce papier (${d.label}) ?`)) return
  try {
    await http.delete(`/vehicle-documents/${d.id}`)
    await refreshDetail()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function openFile(d) {
  try {
    const { data } = await http.get(d.file_url, { responseType: 'blob' })
    window.open(URL.createObjectURL(data), '_blank')
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

onMounted(async () => {
  await load()
  const id = Number(route.query.id)
  const v = id && vehicles.value.find((x) => x.id === id)
  if (v) openDetail(v)
})
</script>
