<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Courses</h1>
    <!-- Courses, Activité des livreurs, Retours marchands, Courses en retard -->
    <CoursesTabs />
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm text-slate-600">Date de livraison dépassée, et pas encore livrées, retournées ni annulées.</p>
      <RouterLink v-if="tab === 'pas-ramassees' && auth.can('settings.manage')" to="/admin/reglages/courses" class="inline-flex items-center gap-1 text-sm text-blue-700"><Icone nom="reglages" taille="petite" /> Message du soir</RouterLink>
    </div>

    <!-- À décider (ramassés, date dépassée) d'abord ; jamais ramassés ensuite -->
    <Onglets
      label="Courses en retard" :model-value="tab"
      :items="tabs.map((t) => ({ value: t.key, label: t.label, count: t.count, urgent: t.key === 'a-decider' && !!t.count }))"
      @update:model-value="select"
    />

    <PickedLateGroups v-if="tab === 'a-decider'" />

    <template v-else>
      <!-- Âge du retard : hier et avant-hier (à reporter vite), plus de 2 jours -->
      <div role="group" aria-label="Âge du retard" class="inline-flex flex-wrap overflow-hidden rounded-lg border border-slate-400">
        <button
          v-for="a in AGES" :key="a.value" type="button"
          :class="['min-h-10 px-4 text-sm', age === a.value ? 'bg-[var(--admin-color,#0f172a)] font-semibold text-white' : 'bg-white text-slate-900 hover:bg-slate-50', a.value ? 'border-l border-slate-400' : '']"
          :aria-pressed="age === a.value" @click="selectAge(a.value)"
        >
          {{ a.label }}<span v-if="ageCount(a.value) !== null" class="ml-1.5 tabular-nums opacity-80">{{ ageCount(a.value) }}</span>
        </button>
      </div>
      <p class="text-sm text-slate-600">Colis jamais ramassés : ils sont cachés chez les livreurs tant que la date n'est pas actualisée. Demandez une nouvelle date ou choisissez-la.</p>
      <!-- Jamais ramassées : nouvelle date à demander ou à choisir -->
      <RescheduleOrders embedded :age="age" />
    </template>
  </div>
</template>

<script setup>
import CoursesTabs from '../../components/CoursesTabs.vue'
import PickedLateGroups from '../../components/PickedLateGroups.vue'
import Onglets from '../../components/ui/Onglets.vue'
import Icone from '../../components/ui/Icone.vue'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useDispatchCountStore } from '../../stores/dispatchCounts'
import RescheduleOrders from './RescheduleOrders.vue'

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
// Jamais ramassées, par âge (null : pas encore chargés)
const ageCount = (value) => {
  const o = dispatchCounts.counts.overdue
  if (!o) return null
  return value ? o[value].to_reschedule : o.recent.to_reschedule + o.old.to_reschedule
}

const tabs = computed(() => {
  const c = dispatchCounts.counts
  const o = c.overdue
  return [
    { key: 'a-decider', label: 'À décider · ramassés, date dépassée', count: c.exceptions?.picked_late || 0 },
    { key: 'pas-ramassees', label: 'Jamais ramassés', count: age.value && o ? o[age.value].to_reschedule : (c.to_reschedule || 0) },
  ]
})
// Anciens onglets « ramassées » et « toutes » : À décider
const tab = ref(route.query.onglet === 'pas-ramassees' ? 'pas-ramassees' : 'a-decider')

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
