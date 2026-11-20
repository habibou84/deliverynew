<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Courses</h1>
    <!-- Courses, Activité des livreurs, Retours marchands -->
    <CoursesTabs />
    <!-- Écrans détaillés (remontées, colis, disponibles) : retour à l'écran d'ensemble -->
    <RouterLink v-if="!overview" to="/admin/activite" class="inline-block text-sm font-medium text-blue-700 hover:underline">← Activité des livreurs</RouterLink>
    <RouterView v-slot="{ Component }">
      <component :is="Component" embedded />
    </RouterView>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import CoursesTabs from '../../../components/CoursesTabs.vue'

const route = useRoute()
// Écran d'ensemble (liste ou carte) : pas de lien de retour
const overview = computed(() => ['/admin/activite', '/admin/activite/', '/admin/activite/carte'].includes(route.path))
</script>
