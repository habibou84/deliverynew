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
        <p v-if="c.delivery_commission || c.pickup_commission" class="text-xs text-gray-500">
          Commission : {{ money(c.pickup_commission) }} ramassage · {{ money(c.delivery_commission) }} livraison
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
        <fieldset class="border rounded p-3">
          <legend class="text-sm font-medium px-1">Rémunération par course (F) · 0 si salarié</legend>
          <div class="grid grid-cols-3 gap-2">
            <div><label class="label">Ramassage</label><input v-model.number="form.data.pickup_commission" type="number" min="0" class="input"></div>
            <div><label class="label">Livraison</label><input v-model.number="form.data.delivery_commission" type="number" min="0" class="input"></div>
            <div><label class="label">Retour</label><input v-model.number="form.data.return_commission" type="number" min="0" class="input"></div>
          </div>
        </fieldset>
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
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { dateTime, mapsLink, money, telLink } from '../../utils/format'

const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }

const auth = useAuthStore()
const couriers = ref([])
const zones = ref([])
const form = reactive({ open: false, id: null, data: {}, error: '' })

async function load() {
  couriers.value = (await http.get('/couriers')).data.data
}

function openForm(c) {
  form.id = c.id
  form.data = {
    vehicle_type: c.vehicle_type,
    vehicle_plate: c.vehicle_plate,
    pickup_commission: c.pickup_commission,
    delivery_commission: c.delivery_commission,
    return_commission: c.return_commission,
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
})
</script>
