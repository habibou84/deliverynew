<template>
  <!-- Quartiers manquants signalés par les entreprises : à ajouter à la liste commune (socle), puis marquer « ajouté » -->
  <div class="card space-y-2 p-3 text-sm">
    <p class="font-medium">Quartiers signalés <span v-if="reports" class="font-normal text-slate-600">({{ reports.length }} en attente)</span></p>
    <p v-if="reports && !reports.length" class="text-slate-600">Aucun quartier en attente.</p>
    <ul v-else-if="reports" class="divide-y">
      <li v-for="r in reports" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
        <span><b>{{ r.name }}</b> · {{ r.parent }}<span class="text-slate-600"> · {{ r.company || '—' }}{{ r.user ? ` (${r.user})` : '' }}</span></span>
        <span v-if="canDecide" class="flex gap-2">
          <button class="btn-secondary !py-1" @click="decide(r, 'added')">Ajouté</button>
          <button class="btn-ghost !py-1 text-red-700" @click="decide(r, 'rejected')">Refuser</button>
        </span>
      </li>
    </ul>
    <p class="text-xs text-slate-600">Un quartier s'ajoute dans la liste commune (habibou84/socle) : il arrive ensuite chez toutes les entreprises et dans Jibiat Marchand.</p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'

defineProps({ canDecide: { type: Boolean, default: false } })
const toasts = useToastStore()
const reports = ref(null)

async function load() {
  try {
    reports.value = (await http.get('/console/place-reports')).data.data
  } catch {
    reports.value = []
  }
}

async function decide(report, status) {
  try {
    await http.put(`/console/place-reports/${report.id}`, { status })
    reports.value = reports.value.filter((r) => r.id !== report.id)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>
