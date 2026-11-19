<template>
  <!-- Activité des livreurs en un écran : les livreurs (liste ou carte) à gauche, ce qui demande l'attention à droite -->
  <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1.8fr)_minmax(20rem,1fr)]">
    <div class="min-w-0 space-y-3">
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="flex-1 text-lg font-bold">Les livreurs</h2>
        <div class="flex rounded-lg bg-slate-200 p-1 text-sm" role="group" aria-label="Affichage des livreurs">
          <RouterLink to="/admin/activite" :class="['rounded-md px-4 py-1.5', view === 'liste' ? 'bg-white font-semibold shadow-sm' : 'text-slate-700']" :aria-current="view === 'liste' ? 'page' : undefined">Liste</RouterLink>
          <RouterLink :to="{ path: '/admin/activite/carte', query: $route.query }" :class="['rounded-md px-4 py-1.5', view === 'carte' ? 'bg-white font-semibold shadow-sm' : 'text-slate-700']" :aria-current="view === 'carte' ? 'page' : undefined">Carte</RouterLink>
        </div>
      </div>
      <DailyTracking v-if="view === 'liste'" embedded stacked />
      <CourierMap v-else embedded />
    </div>
    <aside class="space-y-4" aria-label="À surveiller">
      <FieldReportsFeed />
      <HeldOverdueCard />
      <FreeCouriersCard />
    </aside>
  </div>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import FieldReportsFeed from '../../../components/activity/FieldReportsFeed.vue'
import FreeCouriersCard from '../../../components/activity/FreeCouriersCard.vue'
import HeldOverdueCard from '../../../components/activity/HeldOverdueCard.vue'
import CourierMap from '../CourierMap.vue'
import DailyTracking from './DailyTracking.vue'

/**
 * Courses › Activité des livreurs : les chiffres de l'équipe et les livreurs (liste du jour ou carte), avec à côté les
 * remontées terrain à traiter en direct, les colis gardés trop longtemps et les livreurs libres près de colis à ramasser.
 */
defineProps({ view: { type: String, default: 'liste' }, embedded: { type: Boolean, default: false } })
</script>
