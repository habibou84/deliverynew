<template>
  <div class="max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">Mon abonnement</h1>
    <div v-if="summary" class="card p-4">
      <SubscriptionSummary :summary="summary" />
    </div>
    <p v-else class="text-gray-500">Chargement…</p>
    <p class="text-sm text-gray-500">
      Pour changer de formule, ajouter des livreurs au-delà de la limite ou activer un module, contactez-nous.
    </p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import SubscriptionSummary from '../../components/SubscriptionSummary.vue'

const summary = ref(null)

onMounted(async () => {
  summary.value = (await http.get('/subscription')).data.data
})
</script>
