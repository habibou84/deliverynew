<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Activité des livreurs</h1>

    <!-- Onglets : chaque ancienne page garde son adresse sous /admin/activite -->
    <Onglets label="Onglets de l'activité des livreurs" :items="tabs.map((t) => ({ to: t.to, exact: t.exact, label: t.label, count: t.badge, urgent: !!t.badge }))" />

    <RouterView v-slot="{ Component }">
      <component :is="Component" embedded />
    </RouterView>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { RouterView } from 'vue-router'
import Onglets from '../../../components/ui/Onglets.vue'
import { useFieldReportStore } from '../../../stores/fieldReports'
import { useHeldParcelStore } from '../../../stores/heldParcels'

const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()

const tabs = computed(() => [
  { to: '/admin/activite', label: 'Suivi du jour', exact: true },
  { to: '/admin/activite/carte', label: 'Carte' },
  { to: '/admin/activite/disponibles', label: 'Disponibles' },
  { to: '/admin/activite/colis', label: 'Colis en main', badge: heldParcels.counts.overdue },
  { to: '/admin/activite/remontees', label: 'Remontées terrain', badge: fieldReports.open.total },
  { to: '/admin/activite/planning', label: 'Planning' },
])
</script>
