<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/reglages/reprise" class="text-sm font-medium text-emerald-700">‹ Reprise de l'ancienne application</RouterLink>
      <h1 class="text-xl font-bold">En chemin depuis plus de {{ days }} jours : à vérifier</h1>
      <p class="text-sm text-gray-600">Pour chaque colis, demandez au livreur s'il l'a livré ou s'il l'a encore. Rien n'est envoyé aux clients.</p>
    </div>

    <p v-if="loaded && !couriers.length" class="card p-4 text-sm text-gray-600">Plus aucune ancienne course en chemin à vérifier.</p>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <button v-for="c in couriers" :key="c.courier_id ?? 'none'" type="button"
        :class="['card p-3 text-left', selected === (c.courier_id ?? 0) ? 'ring-2 ring-emerald-600' : '']" @click="pick(c.courier_id ?? 0)">
        <span class="block font-semibold">{{ c.name }}</span>
        <span class="block text-sm text-gray-600">{{ c.count }} colis<template v-if="c.oldest"> · le plus ancien : {{ date(c.oldest) }}</template></span>
      </button>
    </div>

    <div v-if="orders" class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-2 border-b p-3">
        <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" :checked="allChecked" @change="toggleAll"> Tout cocher</label>
        <span class="flex-1 text-sm text-gray-600">{{ checked.length }} coché(s) · {{ total }} colis</span>
        <button type="button" class="btn-secondary" :disabled="busy || !checked.length" @click="resolve('delivered')">Livrées (argent à remettre)</button>
        <button type="button" class="btn-primary" :disabled="busy || !checked.length" @click="resolve('returned')">Retournées à l'e-commerçant</button>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs text-gray-500">
            <tr><th class="w-10 px-3 py-2"></th><th class="px-3">Ancien n°</th><th class="px-3">Destinataire</th><th class="px-3">E-commerçant</th><th class="px-3">Jour prévu</th><th class="px-3 text-right">À encaisser</th></tr>
          </thead>
          <tbody>
            <tr v-for="o in orders" :key="o.id" class="border-t">
              <td class="px-3 py-2"><input v-model="checked" type="checkbox" :value="o.id" :aria-label="`Choisir la course ${o.legacy_id}`"></td>
              <td class="px-3"><RouterLink :to="`/admin/courses/${o.id}`" class="text-emerald-700">{{ o.legacy_id }}</RouterLink></td>
              <td class="px-3">{{ o.recipient }}</td>
              <td class="px-3">{{ o.merchant }}</td>
              <td class="px-3 whitespace-nowrap">{{ date(o.day) }}</td>
              <td class="px-3 text-right tabular-nums whitespace-nowrap">{{ money(o.cod_amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="total > orders.length" class="border-t p-3 text-sm text-gray-600">… et {{ total - orders.length }} autres : traitez ces {{ orders.length }}-ci, la suite s'affiche ensuite.</p>
    </div>

    <p class="text-sm text-gray-600">« Livrées » : la course passe livrée et l'argent s'ajoute au point de caisse du livreur. « Retournées » : le colis est à rendre à l'e-commerçant.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { date, money } from '../../../utils/format'

/** Nettoyage de la reprise : anciennes courses « en chemin », à vérifier livreur par livreur */
const route = useRoute()
const toasts = useToastStore()
const days = Number(route.query.days) || 30
const couriers = ref([])
const orders = ref(null)
const total = ref(0)
const selected = ref(null)
const checked = ref([])
const busy = ref(false)
const loaded = ref(false)

const allChecked = computed(() => orders.value?.length > 0 && checked.value.length === orders.value.length)

async function load() {
  busy.value = true
  try {
    const params = { days, ...(selected.value !== null ? { courier_id: selected.value || '' } : {}) }
    const { data } = await http.get('/company/legacy-purge/on-the-way', { params })
    couriers.value = data.data.couriers
    orders.value = data.data.orders
    total.value = data.data.total ?? 0
    checked.value = []
    loaded.value = true
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

function pick(id) {
  selected.value = id
  load()
}

function toggleAll() {
  checked.value = allChecked.value ? [] : orders.value.map((o) => o.id)
}

async function resolve(outcome) {
  busy.value = true
  try {
    const { data } = await http.post('/company/legacy-purge/on-the-way', { ids: checked.value, outcome }, { params: { days } })
    toasts.success(`${data.data.done} course(s) ${outcome === 'delivered' ? 'livrées' : 'retournées'}.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
  load()
}

onMounted(load)
</script>
