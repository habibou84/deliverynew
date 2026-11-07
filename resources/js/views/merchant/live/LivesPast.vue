<template>
  <!-- Mes lives passés : leurs chiffres et la liste (« Refaire » reprend les produits dans le live en préparation) -->
  <div class="space-y-3.5">
    <p v-if="error" class="rounded-2xl bg-red-50 px-4 py-3 text-[15px] text-red-700" role="alert">{{ error }}</p>
    <p v-else-if="!d" class="m-card p-6 text-center text-slate-500">Chargement…</p>

    <template v-else>
      <!-- Chiffres des lives passés -->
      <div v-if="d.insights?.lives" class="grid grid-cols-2 gap-2.5">
        <div class="m-card p-3">
          <p class="text-[12.5px] text-slate-500">Lives faits</p>
          <p class="text-[20px] font-extrabold">{{ d.insights.lives }}</p>
          <p class="text-[12.5px] text-slate-500">{{ d.insights.orders }} commande{{ d.insights.orders > 1 ? 's' : '' }}</p>
        </div>
        <div class="m-card p-3">
          <p class="text-[12.5px] text-slate-500">Meilleure heure</p>
          <p class="text-[20px] font-extrabold">{{ d.insights.best_hour === null ? '—' : `${d.insights.best_hour} h – ${d.insights.best_hour + 1} h` }}</p>
          <p class="text-[12.5px] text-slate-500">le plus de commandes</p>
        </div>
        <div v-if="d.insights.top_item" class="m-card col-span-2 p-3">
          <p class="text-[12.5px] text-slate-500">L'article le plus commandé</p>
          <p class="text-[17px] font-extrabold">{{ d.insights.top_item.name }} <span class="text-[14px] font-semibold text-slate-500">· {{ d.insights.top_item.quantity }} vendu{{ d.insights.top_item.quantity > 1 ? 's' : '' }}</span></p>
        </div>
      </div>

      <!-- Lives passés -->
      <div v-if="d.past.length" class="m-card divide-y divide-slate-100">
        <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">MES LIVES PASSÉS</p>
        <div v-for="l in d.past" :key="l.id" class="flex items-center gap-2 pr-3">
          <RouterLink :to="`/marchand/live/${l.id}/bilan`" class="tap flex min-w-0 flex-1 items-center justify-between gap-3 py-3 pl-4 active:bg-slate-50">
            <span class="min-w-0">
              <b class="block truncate text-[15px]">{{ l.name }}</b>
              <span class="text-[13px] text-slate-500">{{ day(l.started_at || l.ended_at) }} · {{ l.items_count }} produit{{ l.items_count > 1 ? 's' : '' }} · {{ l.orders_count }} cde{{ l.orders_count > 1 ? 's' : '' }}</span>
            </span>
          </RouterLink>
          <button v-if="!live" type="button" class="shrink-0 rounded-xl bg-slate-100 px-3 py-2 text-[13.5px] font-bold text-slate-800 disabled:opacity-50" :disabled="redoing" @click="redo(l)">Refaire</button>
        </div>
      </div>
      <p v-else class="m-card p-5 text-center text-[15px] text-slate-500">Vos lives passés et leurs chiffres apparaîtront ici.</p>

    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'

const d = ref(null)
const error = ref('')
const router = useRouter()
const toasts = useToastStore()
const live = computed(() => d.value?.current?.status === 'live')
// « Refaire » : le live en préparation reprend les produits, l'ordre et les prix de ce live passé
const redoing = ref(false)
async function redo(past) {
  redoing.value = true
  try {
    const draft = (await http.post('/live/prepare')).data.data
    await http.post(`/live/${draft.live.id}/copy/${past.id}`)
    router.push('/marchand/live/preparer')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    redoing.value = false
  }
}
const day = (v) => (v ? new Date(v).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : '')

async function load() {
  try {
    d.value = (await http.get('/live')).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}
onMounted(load)
defineExpose({ load })
</script>
