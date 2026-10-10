<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Paie des livreurs</h1>
      <div class="flex gap-2">
        <select v-model="planId" class="input w-auto" aria-label="Plan" @change="select(planId)">
          <optgroup label="Plans">
            <option v-for="p in sharedPlans" :key="p.id" :value="p.id">{{ p.name }}{{ p.is_default ? ' (par défaut)' : '' }}</option>
          </optgroup>
          <optgroup v-if="personalPlans.length" label="Plans personnels">
            <option v-for="p in personalPlans" :key="p.id" :value="p.id">{{ p.name }}</option>
          </optgroup>
        </select>
        <button class="btn-secondary" @click="creator.open = true">+ Nouveau plan</button>
      </div>
    </div>

    <p class="text-sm text-gray-600">
      À chaque étape d'une course (ramassage, livraison, tentative ratée, retour, expédition), les règles du plan du livreur
      qui s'appliquent s'additionnent. Le plan par défaut s'applique aux livreurs sans plan attribué ; un plan personnel est une
      exception propre à un livreur. Modifier un plan ne change jamais les gains déjà acquis.
    </p>

    <p v-if="!plan" class="text-gray-500">Chargement…</p>

    <template v-else>
      <div class="card p-4 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
          <div class="flex-1 min-w-48">
            <label class="label" for="plan-name">Nom du plan</label>
            <input id="plan-name" v-model="plan.name" class="input" maxlength="100">
          </div>
          <span class="text-sm text-gray-600 pb-2">
            <template v-if="plan.is_default">Plan par défaut : livreurs sans plan attribué</template>
            <template v-else-if="plan.personal">Plan personnel de {{ plan.courier?.name }}</template>
            <template v-else>{{ plan.couriers_count ?? 0 }} livreur(s)</template>
          </span>
        </div>
        <div class="flex flex-wrap gap-3 text-sm">
          <button v-if="!plan.is_default && !plan.personal" class="text-blue-600" @click="makeDefault">Définir comme plan par défaut</button>
          <button class="text-blue-600" @click="duplicate">Dupliquer</button>
          <button v-if="!plan.is_default" class="text-red-600" @click="remove">Supprimer le plan</button>
        </div>
      </div>

      <!-- Règles par étape -->
      <div v-for="ev in meta.events" :key="ev.value" class="card p-4 space-y-3">
        <div class="flex items-center justify-between gap-2">
          <h2 class="font-semibold">{{ ev.label }}</h2>
          <button class="text-sm text-blue-600" @click="addRule(ev.value)">+ Ajouter une règle</button>
        </div>
        <p v-if="ev.value === 'shipping' && !rulesOf('shipping').length" class="text-sm text-gray-500">Aucune règle : les règles de la livraison réussie s'appliquent.</p>
        <p v-else-if="!rulesOf(ev.value).length" class="text-sm text-gray-500">Rien n'est payé à cette étape.</p>
        <p v-if="ev.value === 'failed_attempt'" class="text-xs text-gray-500">Payée une seule fois par course et par jour.</p>

        <div v-for="rule in rulesOf(ev.value)" :key="rule.key" class="rounded-lg border p-3 space-y-3">
          <div class="flex flex-wrap items-end gap-2">
            <div>
              <label class="label">Calcul</label>
              <select v-model="rule.calc" class="input w-auto">
                <option v-for="c in meta.calcs" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
            </div>
            <div v-if="rule.calc === 'fixed'">
              <label class="label">Montant (F)</label>
              <input v-model.number="rule.amount" type="number" min="0" class="input w-32">
            </div>
            <div v-if="rule.calc === 'percent_fee' || rule.calc === 'percent_collected'">
              <label class="label">Pourcentage</label>
              <input v-model.number="rule.percent" type="number" min="0" max="100" step="0.5" class="input w-24">
            </div>
            <div class="flex-1 min-w-40">
              <label class="label">Libellé (facultatif)</label>
              <input v-model="rule.label" class="input" maxlength="100" :placeholder="ev.label">
            </div>
            <button class="text-sm text-red-600 pb-2" @click="removeRule(rule)">Retirer</button>
          </div>

          <!-- Grille par zone -->
          <div v-if="rule.calc === 'zone_grid'" class="space-y-2">
            <p class="text-xs text-gray-500">
              Montant selon la zone {{ ev.value === 'pickup' ? 'de ramassage' : 'du destinataire' }} ; une commune vaut pour ses quartiers.
              Case vide : montant « Autres zones ».
            </p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-1 max-h-64 overflow-y-auto border rounded p-2">
              <label class="flex items-center justify-between gap-2 text-sm font-medium">
                Autres zones <input v-model.number="rule.amount" type="number" min="0" class="input w-24 !py-1">
              </label>
              <label v-for="z in zones" :key="z.id" class="flex items-center justify-between gap-2 text-sm">
                <span class="truncate">{{ z.full_name }}</span>
                <input
                  :value="rule.zone_amounts[z.id] ?? ''" type="number" min="0" class="input w-24 !py-1"
                  @change="setZoneAmount(rule, z.id, $event.target.value)"
                >
              </label>
            </div>
          </div>

          <!-- Conditions -->
          <details class="text-sm" :open="hasConditions(rule)">
            <summary class="cursor-pointer text-gray-700">Conditions{{ hasConditions(rule) ? ` : ${conditionsText(rule)}` : ' : toujours' }}</summary>
            <div class="mt-2 space-y-2">
              <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2"><input v-model="rule.conditions.express" type="checkbox"> Express seulement</label>
                <label class="flex items-center gap-2"><input v-model="rule.conditions.fragile" type="checkbox"> Fragile seulement</label>
              </div>
              <div>
                <p class="label">Véhicules (aucun coché : tous)</p>
                <div class="flex flex-wrap gap-3">
                  <label v-for="(label, value) in VEHICLES" :key="value" class="flex items-center gap-1">
                    <input v-model="rule.conditions.vehicle_types" type="checkbox" :value="value"> {{ label }}
                  </label>
                </div>
              </div>
              <div v-if="ev.value === 'failed_attempt'">
                <p class="label">Motifs d'échec (aucun coché : tous)</p>
                <div class="grid sm:grid-cols-2 gap-1 max-h-40 overflow-y-auto border rounded p-2">
                  <label v-for="r in reasons" :key="r.id" class="flex items-center gap-2">
                    <input v-model="rule.conditions.incident_reason_ids" type="checkbox" :value="r.id"> {{ r.label }}
                  </label>
                </div>
              </div>
              <div v-if="rule.calc !== 'zone_grid'">
                <p class="label">Zones {{ ev.value === 'pickup' ? 'de ramassage' : 'du destinataire' }} (aucune cochée : toutes)</p>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-1 max-h-40 overflow-y-auto border rounded p-2">
                  <label v-for="z in zones" :key="z.id" class="flex items-center gap-2">
                    <input v-model="rule.conditions.zone_ids" type="checkbox" :value="z.id"> {{ z.full_name }}
                  </label>
                </div>
              </div>
            </div>
          </details>
        </div>
      </div>

      <div class="card p-4 space-y-3">
        <h2 class="font-semibold">Ramassage de plusieurs colis chez le même marchand</h2>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="plan.pickup_mode" type="radio" value="per_parcel" class="mt-1">
          <span><strong>Par colis</strong> : chaque colis ramassé est payé selon les règles du ramassage.</span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="plan.pickup_mode" type="radio" value="per_visit" class="mt-1">
          <span><strong>Par passage</strong> : le premier colis est payé selon les règles ; les suivants du même marchand,
            ramassés dans les 3 heures, ne rapportent que le montant ci-dessous.</span>
        </label>
        <div v-if="plan.pickup_mode === 'per_visit'">
          <label class="label" for="extra">Par colis supplémentaire (F)</label>
          <input id="extra" v-model.number="plan.pickup_extra_parcel_amount" type="number" min="0" class="input w-32">
        </div>
      </div>

      <div class="card p-4 space-y-3">
        <h2 class="font-semibold">Bornes par étape</h2>
        <p class="text-sm text-gray-600">Appliquées au total des règles d'une étape (ex. une livraison). Vide : pas de borne.</p>
        <div class="flex flex-wrap gap-3">
          <div>
            <label class="label" for="min">Minimum (F)</label>
            <input id="min" v-model.number="plan.min_amount" type="number" min="0" class="input w-32">
          </div>
          <div>
            <label class="label" for="max">Plafond (F)</label>
            <input id="max" v-model.number="plan.max_amount" type="number" min="0" class="input w-32">
          </div>
        </div>
      </div>

      <div class="sticky bottom-0 bg-white/90 backdrop-blur py-3 flex items-center gap-3">
        <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer le plan' }}</button>
        <p v-if="error" class="field-error">{{ error }}</p>
      </div>
    </template>

    <Modal :open="creator.open" title="Nouveau plan" @close="creator.open = false">
      <form class="space-y-3" @submit.prevent="create">
        <div>
          <label class="label" for="new-name">Nom</label>
          <input id="new-name" v-model="creator.name" class="input" required maxlength="100" placeholder="Ex. : Livreurs à moto">
        </div>
        <p class="label">Partir de</p>
        <label v-for="t in meta.templates" :key="t.key" class="flex items-start gap-2 text-sm rounded-lg border p-2">
          <input v-model="creator.template" type="radio" :value="t.key" class="mt-1">
          <span><strong>{{ t.name }}</strong><br><span class="text-gray-600">{{ t.description }}</span></span>
        </label>
        <p v-if="creator.error" class="field-error">{{ creator.error }}</p>
        <button class="btn-primary w-full">Créer le plan</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useToastStore } from '../../stores/toasts'

const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }

const route = useRoute()
const router = useRouter()
const toasts = useToastStore()

const plans = ref([])
const meta = ref({ events: [], calcs: [], templates: [] })
const zones = ref([])
const reasons = ref([])
const planId = ref(null)
const plan = ref(null)
const saving = ref(false)
const error = ref('')
const creator = reactive({ open: false, name: '', template: 'fixed', error: '' })
let nextKey = 1

const sharedPlans = computed(() => plans.value.filter((p) => !p.personal))
const personalPlans = computed(() => plans.value.filter((p) => p.personal))

function editable(p) {
  return {
    ...p,
    rules: p.rules.map((r) => ({
      ...r,
      key: nextKey++,
      zone_amounts: { ...r.zone_amounts },
      conditions: { zone_ids: [], vehicle_types: [], incident_reason_ids: [], express: false, fragile: false, ...r.conditions },
    })),
  }
}

function select(id) {
  const found = plans.value.find((p) => p.id === id) ?? plans.value.find((p) => p.is_default) ?? plans.value[0]
  if (!found) return
  planId.value = found.id
  plan.value = editable(found)
  error.value = ''
  router.replace({ query: { plan: found.id } })
}

async function load(id) {
  const { data } = await http.get('/pay-plans')
  plans.value = data.data
  meta.value = data.meta
  select(id)
}

const rulesOf = (event) => plan.value.rules.filter((r) => r.event === event)

function addRule(event) {
  plan.value.rules.push({
    key: nextKey++, event, calc: 'fixed', amount: 0, percent: null, label: '', zone_amounts: {},
    conditions: { zone_ids: [], vehicle_types: [], incident_reason_ids: [], express: false, fragile: false },
  })
}

function removeRule(rule) {
  plan.value.rules = plan.value.rules.filter((r) => r !== rule)
}

function setZoneAmount(rule, zoneId, value) {
  if (value === '') delete rule.zone_amounts[zoneId]
  else rule.zone_amounts[zoneId] = Number(value)
}

function hasConditions(rule) {
  const c = rule.conditions
  return c.express || c.fragile || c.vehicle_types.length || c.zone_ids.length || (rule.event === 'failed_attempt' && c.incident_reason_ids.length)
}

function conditionsText(rule) {
  const c = rule.conditions
  const parts = []
  if (c.express) parts.push('express')
  if (c.fragile) parts.push('fragile')
  if (c.vehicle_types.length) parts.push(c.vehicle_types.map((v) => VEHICLES[v]).join(', '))
  if (c.zone_ids.length && rule.calc !== 'zone_grid') parts.push(`${c.zone_ids.length} zone(s)`)
  if (rule.event === 'failed_attempt' && c.incident_reason_ids.length) parts.push(`${c.incident_reason_ids.length} motif(s)`)
  return parts.join(' · ')
}

function payload(p) {
  const blank = (v) => (v === '' || v === undefined ? null : v)
  return {
    name: p.name,
    pickup_mode: p.pickup_mode,
    pickup_extra_parcel_amount: p.pickup_extra_parcel_amount || 0,
    min_amount: blank(p.min_amount),
    max_amount: blank(p.max_amount),
    rules: p.rules.map((r) => ({
      event: r.event,
      calc: r.calc,
      amount: r.amount || 0,
      percent: blank(r.percent),
      label: r.label || null,
      zone_amounts: r.calc === 'zone_grid' ? r.zone_amounts : null,
      conditions: {
        express: r.conditions.express || null,
        fragile: r.conditions.fragile || null,
        vehicle_types: r.conditions.vehicle_types,
        zone_ids: r.calc === 'zone_grid' ? [] : r.conditions.zone_ids,
        incident_reason_ids: r.event === 'failed_attempt' ? r.conditions.incident_reason_ids : [],
      },
    })),
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    await http.patch(`/pay-plans/${plan.value.id}`, payload(plan.value))
    await load(plan.value.id)
    toasts.success('Plan enregistré.')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function makeDefault() {
  try {
    await http.patch(`/pay-plans/${plan.value.id}`, { is_default: true })
    await load(plan.value.id)
    toasts.success('Plan par défaut changé.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function duplicate() {
  try {
    const { data } = await http.post('/pay-plans', { name: `${plan.value.name} (copie)`, copy_from: plan.value.id })
    await load(data.data.id)
    toasts.success('Copie créée : les modifications non enregistrées du plan d\'origine n\'y sont pas.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function remove() {
  const who = plan.value.personal ? `${plan.value.courier?.name} reviendra` : `Ses ${plan.value.couriers_count ?? 0} livreur(s) reviendront`
  if (!window.confirm(`Supprimer le plan « ${plan.value.name} » ? ${who} au plan par défaut. Les gains déjà acquis ne changent pas.`)) return
  try {
    await http.delete(`/pay-plans/${plan.value.id}`)
    await load(null)
    toasts.success('Plan supprimé.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function create() {
  creator.error = ''
  try {
    const { data } = await http.post('/pay-plans', { name: creator.name, template: creator.template })
    creator.open = false
    creator.name = ''
    await load(data.data.id)
    toasts.success(`Plan créé : attribuez-le à vos livreurs depuis la page Livreurs.`)
  } catch (e) {
    creator.error = apiErrorMessage(e)
  }
}

onMounted(async () => {
  await load(Number(route.query.plan) || null)
  ;[zones.value, reasons.value] = await Promise.all([
    http.get('/zones').then((r) => r.data.data),
    http.get('/incident-reasons').then((r) => r.data.data.filter((x) => !x.applies_to || x.applies_to === 'delivery' || x.applies_to === 'both')),
  ])
})

</script>
