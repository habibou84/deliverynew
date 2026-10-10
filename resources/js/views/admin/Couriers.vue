<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Livreurs</h1>
      <RouterLink v-if="auth.can('users.manage')" to="/admin/utilisateurs" class="btn-secondary">+ Créer un compte livreur</RouterLink>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="c in couriers" :key="c.id" class="card p-4 space-y-2">
        <div class="flex items-center justify-between">
          <div>
            <p class="font-semibold">{{ c.name }}</p>
            <a :href="telLink(c.phone)" class="text-sm text-blue-600">{{ c.phone }}</a>
          </div>
          <span :class="['text-xs rounded-full px-2 py-0.5', c.is_available ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600']">
            {{ c.is_available ? 'Disponible' : 'Indisponible' }}
          </span>
        </div>
        <p class="text-xs text-gray-500">
          Paie : {{ c.pay_plan ? c.pay_plan.name : 'plan par défaut' }}<span v-if="c.pay_plan?.personal"> (personnel)</span>
        </p>
        <p class="text-sm text-gray-600">{{ VEHICLES[c.vehicle_type] }} {{ c.vehicle_plate ? `· ${c.vehicle_plate}` : '' }} · <strong>{{ c.active_assignments_count }}</strong> mission(s) en cours</p>
        <p class="text-sm">Zones : {{ c.zones.map((z) => z.name).join(', ') || 'toutes' }}</p>
        <p v-if="c.last_location_at" class="text-xs text-gray-500">
          Position du {{ dateTime(c.last_location_at) }} · <a :href="mapsLink(c.current_lat, c.current_lng)" target="_blank" class="text-blue-600">voir</a>
        </p>
        <div class="flex gap-3 text-sm">
          <RouterLink :to="{ path: '/admin/courses', query: { courier_id: c.id } }" class="text-blue-600">Courses</RouterLink>
          <button v-if="auth.can('users.manage')" class="text-blue-600" @click="openForm(c)">Modifier</button>
        </div>
      </div>
      <p v-if="!couriers.length" class="text-gray-500">Aucun livreur actif.</p>
    </div>

    <Modal :open="form.open" title="Profil livreur" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div>
          <label class="label">Véhicule</label>
          <select v-model="form.data.vehicle_type" class="input">
            <option v-for="(label, value) in VEHICLES" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
        <div><label class="label">Immatriculation</label><input v-model="form.data.vehicle_plate" class="input"></div>
        <div v-if="canPay">
          <label class="label" for="courier-plan">Plan de paie</label>
          <select id="courier-plan" v-model="form.data.pay_plan_id" class="input">
            <option :value="null">Plan par défaut{{ defaultPlan ? ` (${defaultPlan.name})` : '' }}</option>
            <option v-for="p in planOptions" :key="p.id" :value="p.id">{{ p.name }}{{ p.personal ? ' (personnel)' : '' }}</option>
          </select>
          <p class="text-xs text-gray-500 mt-1">
            Les gains déjà acquis ne changent pas.
            <button type="button" class="text-blue-600" @click="customize">{{ personalPlan ? 'Modifier son plan personnel' : 'Personnaliser pour ce livreur' }}</button>
            · <RouterLink to="/admin/paie-livreurs" class="text-blue-600">Gérer les plans</RouterLink>
          </p>
        </div>
        <div>
          <label class="label">Zones desservies</label>
          <div class="grid grid-cols-2 gap-1 max-h-48 overflow-y-auto border rounded p-2">
            <label v-for="z in zones" :key="z.id" class="flex items-center gap-2 text-sm">
              <input v-model="form.data.zone_ids" type="checkbox" :value="z.id"> {{ z.full_name }}
            </label>
          </div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.data.is_available" type="checkbox"> Disponible</label>
        <div><label class="label">Notes</label><textarea v-model="form.data.notes" rows="2" class="input" /></div>
        <p v-if="form.error" class="field-error">{{ form.error }}</p>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { dateTime, mapsLink, telLink } from '../../utils/format'

const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }

const auth = useAuthStore()
const couriers = ref([])
const zones = ref([])
const form = reactive({ open: false, id: null, data: {}, error: '' })
const router = useRouter()
const plans = ref([])
const canPay = computed(() => auth.can('settings.manage'))
const defaultPlan = computed(() => plans.value.find((p) => p.is_default))
const personalPlan = computed(() => plans.value.find((p) => p.personal && p.courier?.id === form.id))
// Plans partagés et plan personnel de ce livreur
const planOptions = computed(() => plans.value.filter((p) => !p.is_default && (!p.personal || p.courier?.id === form.id) || p.id === form.data.pay_plan_id))

async function customize() {
  try {
    const plan = personalPlan.value ?? (await http.post('/pay-plans', { courier_id: form.id })).data.data
    router.push({ path: '/admin/paie-livreurs', query: { plan: plan.id } })
  } catch (e) {
    form.error = apiErrorMessage(e)
  }
}

async function load() {
  couriers.value = (await http.get('/couriers')).data.data
}

function openForm(c) {
  form.id = c.id
  form.data = {
    vehicle_type: c.vehicle_type,
    vehicle_plate: c.vehicle_plate,
    ...(canPay.value ? { pay_plan_id: c.pay_plan_id } : {}),
    is_available: c.is_available,
    notes: c.notes,
    zone_ids: c.zones.map((z) => z.id),
  }
  form.error = ''
  form.open = true
}

async function save() {
  try {
    await http.patch(`/couriers/${form.id}`, form.data)
    form.open = false
    load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  }
}

onMounted(async () => {
  load()
  zones.value = (await http.get('/zones')).data.data
  if (canPay.value) plans.value = (await http.get('/pay-plans')).data.data
})
</script>
