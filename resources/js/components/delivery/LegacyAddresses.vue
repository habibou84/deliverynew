<template>
  <!-- Adresses de l'ancienne application : zones reprises sans lieu de la liste commune. Elles ne sont plus proposées
       comme destinations ; elles restent liées aux commandes reprises. Visible seulement après une reprise. -->
  <section class="space-y-3">
    <p class="text-sm text-slate-600">
      Destinations venues de l'ancienne application. Elles ne sont plus proposées pour les nouvelles courses : elles servent
      seulement aux commandes reprises. Rattachez-les à un lieu de la liste pour retrouver leurs courses dans vos zones.
    </p>
    <RouterLink to="/admin/reglages/reprise/zones" class="btn-secondary inline-block">Rattacher aux lieux de la liste</RouterLink>
    <p v-if="zones === null" class="card p-6 text-center text-slate-600">Chargement…</p>
    <p v-else-if="!zones.length" class="card p-4 text-sm text-emerald-800">✅ Plus aucune adresse de l'ancienne application : toutes sont rattachées.</p>
    <div v-else class="card divide-y">
      <div v-for="z in zones" :key="z.id" class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
        <span class="font-medium">{{ z.name }}<span v-if="z.parent" class="font-normal text-slate-600"> · {{ z.parent }}</span></span>
        <span class="text-slate-600">{{ z.orders }} commande{{ z.orders > 1 ? 's' : '' }} reprise{{ z.orders > 1 ? 's' : '' }}<template v-if="z.legacy_price"> · ancien prix {{ money(z.legacy_price) }}</template></span>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'

const zones = ref(null)
const money = (n) => `${new Intl.NumberFormat('fr-FR').format(n)} F`

onMounted(async () => {
  try {
    zones.value = (await http.get('/zones/legacy')).data.data
  } catch {
    zones.value = []
  }
})
</script>
