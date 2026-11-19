<template>
  <!-- Courses : trois onglets (la liste, l'activité des livreurs, les retours aux e-commerçants) -->
  <Onglets label="Courses" :items="items" />
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../bootstrap/axios'
import { useFieldReportStore } from '../stores/fieldReports'
import { useHeldParcelStore } from '../stores/heldParcels'
import Onglets from './ui/Onglets.vue'

const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()
const returns = ref(0)

// Pastille rouge : ce qui demande une action (remontées à traiter, colis gardés trop longtemps, colis à rendre)
const items = computed(() => [
  { to: '/admin/courses', label: 'Courses', exact: true },
  { to: '/admin/activite', label: 'Activité des livreurs', count: fieldReports.open.total + heldParcels.counts.overdue, urgent: true },
  { to: '/admin/retours', label: 'Retours marchands', count: returns.value, urgent: true },
])

onMounted(async () => {
  returns.value = (await http.get('/return-slips/count').catch(() => null))?.data?.data?.to_return ?? 0
})
</script>
