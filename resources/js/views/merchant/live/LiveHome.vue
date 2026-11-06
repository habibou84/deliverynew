<template>
  <!-- Onglet Live (téléphone) : le live en cours d'abord, sinon Préparer ; puis les lives passés et leurs chiffres -->
  <div class="space-y-3.5">
    <p v-if="error" class="rounded-2xl bg-red-50 px-4 py-3 text-[15px] text-red-700" role="alert">{{ error }}</p>
    <p v-else-if="!d" class="m-card p-6 text-center text-slate-500">Chargement…</p>

    <template v-else>
      <!-- Live en cours -->
      <RouterLink v-if="d.current?.status === 'live'" :to="`/marchand/live/${d.current.id}`" class="tap block rounded-[22px] bg-gradient-to-br from-rose-700 to-rose-500 p-4 text-white shadow-soft">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/20 px-2.5 py-0.5 text-[12px] font-extrabold"><i class="block h-2 w-2 animate-pulse rounded-full bg-white" />EN DIRECT</span>
        <p class="mt-1.5 text-[20px] font-extrabold leading-tight">{{ d.current.name }}</p>
        <p class="mt-2 rounded-xl bg-white py-2.5 text-center text-[16px] font-bold text-rose-700">Ouvrir l'écran du live</p>
      </RouterLink>

      <!-- Pas de live en cours : préparer (ou reprendre la préparation) -->
      <RouterLink v-else to="/marchand/live/preparer" class="tap block rounded-[22px] bg-brand-grad p-4 text-white shadow-soft">
        <p class="text-[13px] font-bold opacity-80">🔴 LIVE</p>
        <p class="text-[20px] font-extrabold leading-tight">{{ d.current ? 'Reprendre la préparation' : 'Préparer mon live' }}</p>
        <p class="mt-1 text-[14px] opacity-90">Choisissez vos produits, téléchargez la carte, et les commandes arrivent toutes seules.</p>
      </RouterLink>

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
        <RouterLink v-for="l in d.past" :key="l.id" :to="`/marchand/live/${l.id}/bilan`" class="tap flex items-center justify-between gap-3 px-4 py-3 active:bg-slate-50">
          <span class="min-w-0">
            <b class="block truncate text-[15px]">{{ l.name }}</b>
            <span class="text-[13px] text-slate-500">{{ day(l.started_at || l.ended_at) }} · {{ l.items_count }} produit{{ l.items_count > 1 ? 's' : '' }}</span>
          </span>
          <span class="shrink-0 text-[15px] font-bold">{{ l.orders_count }} cde{{ l.orders_count > 1 ? 's' : '' }} ›</span>
        </RouterLink>
      </div>
      <p v-else class="m-card p-5 text-center text-[15px] text-slate-500">Vos lives passés et leurs chiffres apparaîtront ici.</p>

      <LiveIntro :offer="d.offer" folded />
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import LiveIntro from './LiveIntro.vue'

const d = ref(null)
const error = ref('')
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
