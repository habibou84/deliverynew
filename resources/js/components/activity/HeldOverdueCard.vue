<template>
  <!-- Colis gardés trop longtemps par les livreurs -->
  <section class="card overflow-hidden" aria-labelledby="held-title">
    <div class="flex items-center gap-2 border-b p-4">
      <h2 id="held-title" class="flex-1 font-bold">Colis gardés trop longtemps</h2>
      <span v-if="store.counts.overdue" class="rounded-full bg-red-600 px-2.5 py-0.5 text-xs font-bold text-white">{{ store.counts.overdue }}</span>
    </div>
    <p v-if="!loaded" class="p-4 text-sm text-slate-600">Chargement…</p>
    <p v-else-if="!couriers.length" class="flex items-center gap-2 p-4 text-sm text-emerald-700"><Icone nom="valider" taille="petite" /> Aucun colis gardé trop longtemps{{ alertHours ? ` (plus de ${alertHours} h)` : '' }}.</p>
    <ul v-else class="divide-y">
      <li v-for="c in couriers.slice(0, 5)" :key="c.courier_id" class="p-4 text-sm">
        <p><b>{{ c.name }}</b> · {{ c.overdue }} colis<template v-if="c.oldest"> depuis {{ heldFor(c.oldest) }}</template></p>
        <p class="text-xs text-slate-600">{{ c.count }} colis en main au total</p>
      </li>
    </ul>
    <RouterLink to="/admin/activite/colis" class="block border-t px-4 py-3 text-center text-sm font-semibold text-blue-700 hover:bg-slate-50">Tous les colis chez les livreurs ›</RouterLink>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../../bootstrap/axios'
import { useHeldParcelStore } from '../../stores/heldParcels'
import Icone from '../ui/Icone.vue'

const store = useHeldParcelStore()
const couriers = ref([])
const alertHours = ref(null)
const loaded = ref(false)

function heldFor(at) {
  const h = Math.round((Date.now() - new Date(at).getTime()) / 3600000)
  return h < 48 ? `${h} h` : `${Math.floor(h / 24)} jours`
}

async function load() {
  try {
    const { data } = await http.get('/parcels/held', { params: { overdue: 1 } })
    couriers.value = data.data.filter((c) => c.overdue)
    alertHours.value = data.meta.alert_hours
    store.setCounts(data.meta)
  } finally {
    loaded.value = true
  }
}

let timer
onMounted(() => {
  load()
  timer = setInterval(() => !document.hidden && load(), 60000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
