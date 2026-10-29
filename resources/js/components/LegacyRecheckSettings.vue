<template>
  <section v-if="state" class="card p-4 space-y-3">
    <div>
      <h2 class="font-semibold">Recomparaison complète</h2>
      <p class="text-sm text-gray-600">
        Relit toutes les courses reprises dans l'ancienne application : les modifiées sont mises à jour, les commandes
        supprimées là-bas sont annulées sans message quand aucun argent n'est en jeu, sinon mises de côté pour que vous
        décidiez. Chaque nuit, Jibiat repère aussi les nouvelles suppressions.
      </p>
    </div>

    <div v-if="running" class="space-y-1">
      <p class="text-sm">🔎 Recomparaison en cours : {{ last.done }} / {{ last.total }} courses relues</p>
      <div class="h-2 rounded bg-slate-200"><div class="h-2 rounded bg-emerald-600" :style="{ width: `${last.total ? Math.round(last.done * 100 / last.total) : 3}%` }" /></div>
      <p class="text-xs text-gray-500">Vous pouvez quitter la page : la recomparaison continue sur le serveur.</p>
    </div>
    <button v-else type="button" class="btn-secondary" :disabled="busy" @click="start">Lancer la recomparaison complète</button>

    <div v-if="last && !running" class="rounded-lg bg-slate-50 p-3 text-sm space-y-1">
      <p v-if="last.status === 'failed'" class="font-medium text-red-700">Recomparaison arrêtée : {{ last.error }}</p>
      <template v-else>
        <p class="font-medium">
          {{ last.source === 'nightly' ? 'Relecture de la nuit' : 'Recomparaison' }} du {{ dateTime(last.finished_at || last.started_at) }}<template v-if="last.by"> par {{ last.by }}</template>
          <template v-if="last.status === 'undone'"> (annulations défaites le {{ dateTime(last.undone_at) }})</template>
        </p>
        <ul class="list-disc pl-5 text-gray-700">
          <li>{{ last.counts.updated }} modifiée(s) mises à jour · {{ last.counts.unchanged }} sans changement<template v-if="last.counts.conflict"> · {{ last.counts.conflict }} modifiée(s) après la bascule (non recopiées)</template></li>
          <li>{{ last.counts.deleted_cancelled }} supprimée(s) annulée(s) · {{ last.counts.deleted_to_check }} à vérifier · {{ last.counts.deleted_closed }} déjà terminée(s)</li>
        </ul>
      </template>
      <button v-if="last.can_undo" type="button" class="btn-secondary mt-1" :disabled="busy" @click="undo">Défaire les annulations (jusqu'au {{ date(last.undo_until) }})</button>
    </div>

    <RouterLink v-if="state.to_check" to="/admin/reglages/reprise/supprimees" class="block rounded bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
      → {{ state.to_check }} commande(s) supprimée(s) dans l'ancienne application à vérifier ›
    </RouterLink>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { date, dateTime } from '../utils/format'

/** Réglages › Reprise › Recomparaison complète : modifiées, supprimées (annulées ou à vérifier), annulation 7 jours */
const toasts = useToastStore()
const state = ref(null)
const busy = ref(false)
let timer = null

const last = computed(() => state.value?.last ?? null)
const running = computed(() => last.value?.status === 'running')

async function load() {
  try {
    const { data } = await http.get('/company/legacy-recheck')
    state.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function start() {
  if (!window.confirm('Relire toutes les courses reprises dans l\'ancienne application ? Cela peut prendre un moment.')) return
  busy.value = true
  try {
    const { data } = await http.post('/company/legacy-recheck')
    state.value = { ...state.value, last: data.data }
    toasts.success('Recomparaison lancée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function undo() {
  if (!window.confirm('Défaire les annulations ? Ces courses reviennent comme avant, dans la liste des supprimées à vérifier.')) return
  busy.value = true
  try {
    const { data } = await http.post(`/company/legacy-recheck/${last.value.id}/undo`)
    toasts.success(`${data.data.restored} course(s) remises comme avant.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
  load()
}

onMounted(() => {
  load()
  timer = setInterval(() => !document.hidden && running.value && !busy.value && load(), 5000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
