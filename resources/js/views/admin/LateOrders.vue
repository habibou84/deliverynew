<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <RouterLink to="/admin/courses" class="text-sm text-blue-600">‹ Courses</RouterLink>
        <h1 class="text-xl font-bold">Courses en retard</h1>
        <p class="text-sm text-slate-600">Date de livraison dépassée, et pas encore livrées, retournées ni annulées.</p>
      </div>
      <RouterLink v-if="tab === 'pas-ramassees' && auth.can('settings.manage')" to="/admin/reglages/courses" class="inline-flex items-center gap-1 text-sm text-blue-700"><Icone nom="reglages" taille="petite" /> Message du soir</RouterLink>
    </div>

    <Onglets
      label="Courses en retard" :model-value="tab"
      :items="tabs.map((t) => ({ value: t.key, label: t.label, count: t.count, urgent: !!t.count }))"
      @update:model-value="select"
    />
    <p class="text-sm text-slate-600">{{ current.help }}</p>

    <!-- Pas ramassées : nouvelle date à demander ou à choisir (ancienne page « À reporter ») -->
    <RescheduleOrders v-if="tab === 'pas-ramassees'" embedded />
    <!-- Ramassées pas livrées, ou toutes : actions sur les courses (ancienne page « Courses non traitées ») -->
    <UntreatedOrders v-else embedded :stage="tab === 'ramassees' ? 'picked' : 'all'" />
  </div>
</template>

<script setup>
import Onglets from '../../components/ui/Onglets.vue'
import Icone from '../../components/ui/Icone.vue'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useDispatchCountStore } from '../../stores/dispatchCounts'
import RescheduleOrders from './RescheduleOrders.vue'
import UntreatedOrders from './UntreatedOrders.vue'

/** Courses en retard : un seul menu, trois onglets (pas ramassées, ramassées pas livrées, toutes) */
const auth = useAuthStore()
const dispatchCounts = useDispatchCountStore()
const route = useRoute()
const router = useRouter()

const tabs = computed(() => {
  const c = dispatchCounts.counts
  return [
    { key: 'pas-ramassees', label: 'Pas ramassées', count: c.to_reschedule || 0, help: 'Colis jamais ramassés : ils sont cachés chez les livreurs tant que la date n\'est pas actualisée. Demandez une nouvelle date ou choisissez-la.' },
    { key: 'ramassees', label: 'Ramassées, pas livrées', count: c.exceptions?.untreated_picked || 0, help: 'Colis chez un livreur ou à l\'entrepôt : à livrer, reporter ou faire revenir.' },
    { key: 'toutes', label: 'Toutes', count: c.exceptions?.untreated || 0, help: 'Toutes les courses en retard, quelle que soit l\'étape.' },
  ]
})
const tab = ref(['pas-ramassees', 'ramassees', 'toutes'].includes(route.query.onglet) ? route.query.onglet : 'pas-ramassees')
const current = computed(() => tabs.value.find((t) => t.key === tab.value))

function select(key) {
  tab.value = key
  router.replace({ query: { onglet: key } })
}

onMounted(() => dispatchCounts.fetchCounts().catch(() => {}))
</script>
