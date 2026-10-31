<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Activité des livreurs</h1>

    <!-- Onglets : chaque ancienne page garde son adresse sous /admin/activite -->
    <nav class="flex gap-1 overflow-x-auto border-b border-slate-300" aria-label="Onglets de l'activité des livreurs">
      <RouterLink
        v-for="t in tabs"
        :key="t.to"
        :to="t.to"
        :class="['whitespace-nowrap px-3 py-2.5 text-sm border-b-[3px] -mb-px', isCurrent(t) ? 'border-emerald-700 font-bold text-emerald-900' : 'border-transparent text-slate-700 hover:text-slate-900']"
        :aria-current="isCurrent(t) ? 'page' : undefined"
      >
        {{ t.label }}
        <span v-if="t.badge" :class="['ml-1 rounded-full px-1.5 text-xs font-semibold text-white', t.tone === 'orange' ? 'bg-orange-600' : 'bg-red-600']">{{ t.badge > 99 ? '99+' : t.badge }}</span>
      </RouterLink>
    </nav>

    <RouterView v-slot="{ Component }">
      <component :is="Component" embedded />
    </RouterView>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useFieldReportStore } from '../../../stores/fieldReports'
import { useHeldParcelStore } from '../../../stores/heldParcels'

const route = useRoute()
const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()

const tabs = computed(() => [
  { to: '/admin/activite', label: 'Suivi du jour', exact: true },
  { to: '/admin/activite/carte', label: 'Carte' },
  { to: '/admin/activite/disponibles', label: 'Disponibles' },
  { to: '/admin/activite/colis', label: 'Colis en main', badge: heldParcels.counts.overdue, tone: 'orange' },
  { to: '/admin/activite/remontees', label: 'Remontées terrain', badge: fieldReports.open.total },
  { to: '/admin/activite/planning', label: 'Planning' },
])
const isCurrent = (t) => (t.exact ? route.path === t.to || route.path === `${t.to}/` : route.path.startsWith(t.to))
</script>
