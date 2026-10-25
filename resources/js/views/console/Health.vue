<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Santé de la plateforme</h1>
        <p class="text-sm text-gray-600">À regarder chaque matin. Une panne est aussi signalée dans la cloche et par WhatsApp.</p>
      </div>
      <button class="btn-secondary" :disabled="loading" @click="load">{{ loading ? 'Vérification…' : '↻ Vérifier' }}</button>
    </div>

    <p v-if="summary" :class="['rounded-lg p-3 text-sm font-medium', summary.cls]">{{ summary.text }}</p>

    <ul v-if="checks.length" class="card divide-y">
      <li v-for="c in checks" :key="c.key" class="flex items-start gap-3 p-3 text-sm">
        <span class="mt-0.5 text-lg" :aria-label="STATUS[c.status].label">{{ STATUS[c.status].icon }}</span>
        <div class="min-w-0">
          <p class="font-medium">{{ c.label }}</p>
          <p :class="STATUS[c.status].text">{{ c.detail }}</p>
        </div>
      </li>
    </ul>
    <p v-if="checkedAt" class="text-xs text-gray-500">Vérifié le {{ dateTime(checkedAt) }}. Le planning complet est dans docs/MAINTENANCE.md.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const STATUS = {
  ok: { icon: '✅', label: 'Bon', text: 'text-gray-600' },
  warning: { icon: '🟠', label: 'À surveiller', text: 'text-amber-800' },
  error: { icon: '🔴', label: 'En panne', text: 'text-red-700' },
}

const toasts = useToastStore()
const checks = ref([])
const checkedAt = ref(null)
const loading = ref(false)

const summary = computed(() => {
  if (!checks.value.length) return null
  const errors = checks.value.filter((c) => c.status === 'error').length
  const warnings = checks.value.filter((c) => c.status === 'warning').length
  if (errors) return { cls: 'bg-red-50 text-red-800', text: `${errors} point${errors > 1 ? 's' : ''} en panne à régler maintenant.` }
  if (warnings) return { cls: 'bg-amber-50 text-amber-900', text: `Tout fonctionne, ${warnings} point${warnings > 1 ? 's' : ''} à surveiller.` }
  return { cls: 'bg-emerald-50 text-emerald-800', text: 'Tout fonctionne.' }
})

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/console/health')
    checks.value = data.data.checks
    checkedAt.value = data.data.checked_at
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
