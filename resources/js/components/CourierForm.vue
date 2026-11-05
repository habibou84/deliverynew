<template>
  <Modal :open="open" :title="`Profil livreur${courier?.name ? ` : ${courier.name}` : ''}`" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="save">
      <p v-if="courier?.vehicle" class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
        Véhicule : <RouterLink :to="{ path: '/admin/vehicules', query: { id: courier.vehicle.id } }" class="text-blue-600">{{ courier.vehicle.label }}</RouterLink> (à changer dans sa fiche)
      </p>
      <template v-else>
        <div>
          <label class="label" for="cf-vehicle">Véhicule</label>
          <select id="cf-vehicle" v-model="data.vehicle_type" class="input">
            <option v-for="(label, value) in VEHICLES" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
        <div><label class="label" for="cf-plate">Immatriculation</label><input id="cf-plate" v-model="data.vehicle_plate" class="input"></div>
      </template>
      <div v-if="canPay">
        <label class="label" for="courier-plan">Plan de paie</label>
        <select id="courier-plan" v-model="data.pay_plan_id" class="input">
          <option :value="null">Plan par défaut{{ defaultPlan ? ` (${defaultPlan.name})` : '' }}</option>
          <option v-for="p in planOptions" :key="p.id" :value="p.id">{{ p.name }}{{ p.personal ? ' (personnel)' : '' }}</option>
        </select>
        <p class="text-xs text-gray-500 mt-1">
          Les gains déjà acquis ne changent pas.
          <button type="button" class="text-blue-600" @click="customize">{{ personalPlan ? 'Modifier son plan personnel' : 'Personnaliser pour ce livreur' }}</button>
          · <RouterLink to="/admin/paie-livreurs" class="text-blue-600">Gérer les plans</RouterLink>
        </p>
      </div>
      <div v-if="canPay">
        <label class="label" for="courier-accept">Acceptation des missions</label>
        <select id="courier-accept" v-model="data.mission_acceptance" class="input">
          <option :value="null">Comme l'entreprise</option>
          <option v-for="m in ACCEPT_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
        </select>
      </div>
      <div>
        <label class="label">Zones desservies</label>
        <div class="max-h-56 overflow-y-auto border rounded divide-y">
          <div v-for="z in zones" :key="z.id" class="flex items-center justify-between gap-2 px-2 py-1 text-sm">
            <span class="min-w-0 truncate">{{ z.full_name }}</span>
            <select :value="zoneRole(z.id)" class="input w-36 py-1" :aria-label="`Rôle de la zone ${z.full_name}`" @change="setZoneRole(z.id, $event.target.value)">
              <option value="">—</option>
              <option value="main">Principale</option>
              <option value="backup">De secours</option>
            </select>
          </div>
        </div>
        <p class="text-xs text-gray-500 mt-1">Zone de secours : il y livre automatiquement quand personne de la zone principale n'est libre.</p>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div><label class="label" for="c-parcels">Colis au plus</label><input id="c-parcels" v-model.number="data.max_parcels" type="number" min="1" class="input" placeholder="Sans limite"></div>
        <div><label class="label" for="c-cash">Argent en main au plus</label><input id="c-cash" v-model.number="data.max_cash" type="number" min="0" step="1000" class="input" placeholder="Sans limite"></div>
        <div><label class="label" for="c-shift">Fin de service</label><input id="c-shift" v-model="data.shift_ends_at" type="time" class="input"></div>
      </div>
      <label class="flex items-center gap-2 text-sm"><input v-model="data.is_available" type="checkbox"> Disponible</label>
      <div><label class="label" for="cf-notes">Notes</label><textarea id="cf-notes" v-model="data.notes" rows="2" class="input" /></div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </form>
  </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { ACCEPT_MODES } from '../views/admin/settings/options'
import Modal from './Modal.vue'

/** Fenêtre « Profil livreur » : véhicule, paie, zones, limites, disponibilité (page Livreurs et fiche du livreur) */
const props = defineProps({
  open: { type: Boolean, default: false },
  // Livreur au format de GET /couriers/{id}
  courier: { type: Object, default: null },
})
const emit = defineEmits(['close', 'saved'])

const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }
const auth = useAuthStore()
const router = useRouter()
const canPay = computed(() => auth.can('settings.manage'))
const data = ref({})
const error = ref('')
const saving = ref(false)
const zones = ref([])
const plans = ref([])
let loaded = false

const defaultPlan = computed(() => plans.value.find((p) => p.is_default))
const personalPlan = computed(() => plans.value.find((p) => p.personal && p.courier?.id === props.courier?.id))
// Plans partagés et plan personnel de ce livreur
const planOptions = computed(() => plans.value.filter((p) => (!p.is_default && (!p.personal || p.courier?.id === props.courier?.id)) || p.id === data.value.pay_plan_id))

async function loadLists() {
  if (loaded) return
  loaded = true
  zones.value = (await http.get('/zones')).data.data
  if (canPay.value) plans.value = (await http.get('/pay-plans')).data.data
}

watch(() => [props.open, props.courier], () => {
  if (!props.open || !props.courier) return
  const c = props.courier
  data.value = {
    vehicle_type: c.vehicle_type,
    vehicle_plate: c.vehicle_plate,
    ...(canPay.value ? { pay_plan_id: c.pay_plan_id, mission_acceptance: c.mission_acceptance ?? null } : {}),
    is_available: c.is_available,
    notes: c.notes,
    zone_ids: (c.zones || []).map((z) => z.id),
    backup_zone_ids: (c.backup_zones || []).map((z) => z.id),
    max_parcels: c.max_parcels,
    max_cash: c.max_cash,
    shift_ends_at: c.shift_ends_at ? String(c.shift_ends_at).slice(0, 5) : null,
  }
  error.value = ''
  loadLists().catch((e) => { error.value = apiErrorMessage(e) })
}, { immediate: true })

const zoneRole = (id) => (data.value.zone_ids?.includes(id) ? 'main' : data.value.backup_zone_ids?.includes(id) ? 'backup' : '')
function setZoneRole(id, role) {
  data.value.zone_ids = data.value.zone_ids.filter((z) => z !== id).concat(role === 'main' ? [id] : [])
  data.value.backup_zone_ids = data.value.backup_zone_ids.filter((z) => z !== id).concat(role === 'backup' ? [id] : [])
}

async function customize() {
  try {
    const plan = personalPlan.value ?? (await http.post('/pay-plans', { courier_id: props.courier.id })).data.data
    router.push({ path: '/admin/paie-livreurs', query: { plan: plan.id } })
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save() {
  saving.value = true
  try {
    // Champs vidés : sans limite
    const blank = (v) => (v === '' ? null : v)
    const { data: res } = await http.patch(`/couriers/${props.courier.id}`, {
      ...data.value, max_parcels: blank(data.value.max_parcels), max_cash: blank(data.value.max_cash), shift_ends_at: data.value.shift_ends_at || null,
    })
    emit('saved', res.data)
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
