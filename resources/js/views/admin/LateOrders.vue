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
    <!-- Âge du retard : hier et avant-hier (à reporter vite), plus de 2 jours (non traitées depuis longtemps) -->
    <div role="group" aria-label="Âge du retard" class="inline-flex flex-wrap overflow-hidden rounded-lg border border-slate-400">
      <button
        v-for="a in AGES" :key="a.value" type="button"
        :class="['min-h-10 px-4 text-sm', age === a.value ? 'bg-[var(--admin-color,#0f172a)] font-semibold text-white' : 'bg-white text-slate-900 hover:bg-slate-50', a.value ? 'border-l border-slate-400' : '']"
        :aria-pressed="age === a.value" @click="selectAge(a.value)"
      >
        {{ a.label }}<span v-if="ageCount(a.value) !== null" class="ml-1.5 tabular-nums opacity-80">{{ ageCount(a.value) }}</span>
      </button>
    </div>
    <p class="text-sm text-slate-600">{{ current.help }}</p>

    <!-- Pas ramassées : nouvelle date à demander ou à choisir (ancienne page « À reporter ») -->
    <RescheduleOrders v-if="tab === 'pas-ramassees'" embedded :age="age" />
    <!-- Ramassées pas livrées, ou toutes : actions sur les courses (ancienne page « Courses non traitées ») -->
    <UntreatedOrders v-else embedded :stage="tab === 'ramassees' ? 'picked' : 'all'" :age="age" />
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

// Âge du retard (?age=) : toutes les dates, hier et avant-hier, plus de 2 jours
const AGES = [
  { value: '', label: 'Toutes les dates' },
  { value: 'recent', label: 'Hier et avant-hier' },
  { value: 'old', label: 'Plus de 2 jours' },
]
const age = ref(['recent', 'old'].includes(route.query.age) ? route.query.age : '')
// Compteurs de l'onglet choisi, par âge (null : pas encore chargés)
const TAB_KEYS = { 'pas-ramassees': 'to_reschedule', ramassees: 'untreated_picked', toutes: 'untreated' }
const ageCount = (value) => {
  const o = dispatchCounts.counts.overdue
  if (!o) return null
  const key = TAB_KEYS[tab.value]
  return value ? o[value][key] : o.recent[key] + o.old[key]
}

const tabs = computed(() => {
  const c = dispatchCounts.counts
  const o = c.overdue
  // Avec un âge choisi, les onglets comptent seulement les courses de cet âge
  const n = (key, all) => (age.value && o ? o[age.value][key] : all)
  return [
    { key: 'pas-ramassees', label: 'Pas ramassées', count: n('to_reschedule', c.to_reschedule || 0), help: 'Colis jamais ramassés : ils sont cachés chez les livreurs tant que la date n\'est pas actualisée. Demandez une nouvelle date ou choisissez-la.' },
    { key: 'ramassees', label: 'Ramassées, pas livrées', count: n('untreated_picked', c.exceptions?.untreated_picked || 0), help: 'Colis chez un livreur ou à l\'entrepôt : à livrer, reporter ou faire revenir.' },
    { key: 'toutes', label: 'Toutes', count: n('untreated', c.exceptions?.untreated || 0), help: 'Toutes les courses en retard, quelle que soit l\'étape.' },
  ]
})
// Arrivée depuis le poste de pilotage avec un âge (?age=) : toutes les étapes
const tab = ref(['pas-ramassees', 'ramassees', 'toutes'].includes(route.query.onglet) ? route.query.onglet : (route.query.age ? 'toutes' : 'pas-ramassees'))
const current = computed(() => tabs.value.find((t) => t.key === tab.value))

const query = () => ({ onglet: tab.value, ...(age.value ? { age: age.value } : {}) })

function select(key) {
  tab.value = key
  router.replace({ query: query() })
}

function selectAge(value) {
  age.value = value
  router.replace({ query: query() })
}

onMounted(() => dispatchCounts.fetchCounts().catch(() => {}))
</script>
