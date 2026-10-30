<template>
  <section v-if="state" class="card p-4 space-y-3">
    <div>
      <h2 class="font-semibold">Recomparaison avec l'ancienne application</h2>
      <p class="text-sm text-gray-600">
        Demande à l'ancienne application les commandes créées ou modifiées depuis la date choisie (les nouvelles arrivent
        dans Jibiat), puis relit les courses déjà là, des plus récentes aux plus anciennes : les modifiées sont mises à jour, les commandes
        supprimées là-bas sont annulées sans message quand aucun argent n'est en jeu, sinon mises de côté pour que vous
        décidiez. Chaque nuit, Jibiat repère aussi les nouvelles suppressions.
      </p>
    </div>

    <div v-if="running" class="space-y-1">
      <template v-if="last.phase === 'pull'">
        <p class="text-sm">🔎 1/2 · Commandes créées ou modifiées dans l'ancienne application : {{ last.counts.pulled }} lues ({{ last.counts.created }} nouvelles, {{ last.counts.updated }} mises à jour)</p>
        <div class="h-2 rounded bg-slate-200"><div class="h-2 w-1/12 animate-pulse rounded bg-emerald-600" /></div>
      </template>
      <template v-else>
        <p class="text-sm">🔎 2/2 · Courses déjà dans Jibiat, des plus récentes aux plus anciennes : {{ last.done }} / {{ last.total }} relues</p>
        <div class="h-2 rounded bg-slate-200"><div class="h-2 rounded bg-emerald-600" :style="{ width: `${last.total ? Math.round(last.done * 100 / last.total) : 3}%` }" /></div>
      </template>
      <p class="text-xs text-gray-500">Vous pouvez quitter la page : la recomparaison continue sur le serveur.</p>
    </div>
    <div v-else class="flex flex-wrap items-end gap-3">
      <label class="text-sm font-medium">Comparer les commandes à partir du
        <input v-model="since" type="date" class="input mt-1 block" :max="todayIso" @change="load">
        <span v-if="state.default_since" class="mt-1 block text-xs font-normal text-gray-500">
          Par défaut : {{ date(state.default_since) }} (premier lancement de la reprise)
          <button v-if="since !== state.default_since" type="button" class="underline" @click="since = state.default_since; load()">remettre</button>
        </span>
      </label>
      <p class="basis-full text-sm text-gray-600">D'abord les commandes créées ou modifiées là-bas{{ since ? ` depuis le ${date(since)}` : '' }}, puis {{ state.count }} course(s) déjà dans Jibiat relues.</p>
      <button type="button" class="btn-secondary" :disabled="busy" @click="start">Lancer la recomparaison</button>
    </div>
    <p v-if="!running" class="text-xs text-gray-500">Date de création de la commande dans l'ancienne application. Les commandes plus anciennes déjà terminées ne sont pas relues ; celles encore en cours le sont chaque nuit.</p>

    <div v-if="last && !running" class="rounded-lg bg-slate-50 p-3 text-sm space-y-1">
      <p v-if="last.status === 'failed'" class="font-medium text-red-700">Recomparaison arrêtée : {{ last.error }}</p>
      <template v-else>
        <p class="font-medium">
          {{ last.source === 'nightly' ? 'Relecture de la nuit' : 'Recomparaison' }}<template v-if="last.since"> depuis le {{ date(last.since) }}</template>, faite le {{ dateTime(last.finished_at || last.started_at) }}<template v-if="last.by"> par {{ last.by }}</template>
          <template v-if="last.status === 'undone'"> (annulations défaites le {{ dateTime(last.undone_at) }})</template>
        </p>
        <ul class="list-disc pl-5 text-gray-700">
          <li v-if="last.source !== 'nightly'">{{ last.counts.created }} nouvelle(s) commande(s) ajoutée(s) depuis l'ancienne application</li>
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
const since = ref(null)
const todayIso = new Date().toLocaleDateString('en-CA')
let timer = null

const last = computed(() => state.value?.last ?? null)
const running = computed(() => last.value?.status === 'running')

async function load() {
  try {
    const { data } = await http.get('/company/legacy-recheck', { params: since.value ? { since: since.value } : {} })
    state.value = data.data
    since.value = data.data.since
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function start() {
  if (!window.confirm('Lancer la recomparaison avec l\'ancienne application ? Cela peut prendre un moment.')) return
  busy.value = true
  try {
    const { data } = await http.post('/company/legacy-recheck', { since: since.value })
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
