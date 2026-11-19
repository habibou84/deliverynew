<template>
  <!-- Livreurs libres (ou bientôt) et colis à ramasser près d'eux : de quoi leur confier une mission -->
  <section v-if="loaded && rows.length" class="card overflow-hidden border-emerald-200" aria-labelledby="free-title">
    <div class="flex items-center gap-2 border-b bg-emerald-50 p-4">
      <h2 id="free-title" class="flex-1 font-bold text-emerald-900">Libres près de colis à ramasser</h2>
      <span class="rounded-full bg-emerald-700 px-2.5 py-0.5 text-xs font-bold text-white">{{ rows.length }}</span>
    </div>
    <ul class="divide-y">
      <li v-for="c in rows.slice(0, 4)" :key="c.id" class="p-4 text-sm">
        <p><b>{{ c.name }}</b> · {{ c.state === 'free' ? 'libre' : 'bientôt libre' }}</p>
        <p class="text-emerald-900"><b>{{ c.nearby.count }} colis</b> à ramasser près de lui ({{ c.nearby.stops }} boutique{{ c.nearby.stops > 1 ? 's' : '' }})</p>
      </li>
    </ul>
    <RouterLink to="/admin/activite/disponibles" class="block border-t px-4 py-3 text-center text-sm font-semibold text-blue-700 hover:bg-slate-50">Lui confier une mission ramassage ›</RouterLink>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../../bootstrap/axios'

const couriers = ref([])
const loaded = ref(false)
const rows = computed(() => couriers.value.filter((c) => ['free', 'soon'].includes(c.state) && c.nearby?.count))

async function load() {
  try {
    couriers.value = (await http.get('/couriers/availability')).data.data
  } catch {
    couriers.value = []
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
