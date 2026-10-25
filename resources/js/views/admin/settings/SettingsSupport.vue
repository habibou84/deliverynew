<template>
  <SettingsPage icon="🛟" title="Accès de l'assistance Jibiat" intro="Chaque fois que l'assistance Jibiat ouvre votre espace pour vous aider : qui, quand, pourquoi et combien d'actions. Vous êtes prévenu à chaque ouverture.">
    <div class="card p-4 space-y-3">
      <p v-if="active" class="rounded-lg bg-sky-50 p-3 text-sm text-sky-900">
        🛟 <strong>{{ active.opened_by }}</strong> (assistance Jibiat) a accès à votre espace jusqu'à {{ time(active.expires_at) }}<span v-if="active.reason"> · {{ active.reason }}</span>.
      </p>
      <p v-if="loading" class="text-sm text-gray-500">Chargement…</p>
      <p v-else-if="!sessions.length" class="text-sm text-gray-500">L'assistance Jibiat n'a jamais ouvert votre espace.</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[32rem] text-sm">
          <thead class="text-left text-gray-500"><tr><th class="py-1">Date</th><th>Personne</th><th>Motif</th><th>Durée</th><th>Actions</th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="s in sessions" :key="s.id">
              <td class="py-2 whitespace-nowrap">{{ dateTime(s.created_at) }}</td>
              <td>{{ s.opened_by }}</td>
              <td>{{ s.reason || '—' }}</td>
              <td class="whitespace-nowrap">
                <span v-if="s.open" class="text-sky-700">en cours</span>
                <template v-else>{{ minutes(s) }} min</template>
              </td>
              <td>{{ s.actions }} modification(s)</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-gray-500">
        Un accès dure au plus une heure. Il se ferme tout seul, et chaque action faite pendant l'accès est enregistrée.
        Une question sur un accès ? Contactez Jibiat.
      </p>
    </div>
  </SettingsPage>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import { useToastStore } from '../../../stores/toasts'
import { dateTime } from '../../../utils/format'

const toasts = useToastStore()
const sessions = ref([])
const active = ref(null)
const loading = ref(true)

const time = (iso) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const minutes = (s) => Math.max(1, Math.round((new Date(s.expires_at) - new Date(s.created_at)) / 60000))

onMounted(async () => {
  try {
    const { data } = await http.get('/company/support-sessions')
    sessions.value = data.data
    active.value = data.active ?? null
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
})
</script>
