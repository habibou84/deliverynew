<template>
  <section v-if="state" class="card p-4 space-y-4">
    <div>
      <h2 class="font-semibold">Anciennes courses à clôturer</h2>
      <p class="text-sm text-gray-600">
        Courses venues de l'ancienne application, pas terminées, dont le jour de livraison est passé depuis plus de
        {{ days }} jours. Rien n'est envoyé : ni message, ni webhook, ni gain livreur, et l'argent ne bouge pas.
        À faire une fois la reprise terminée.
      </p>
    </div>

    <div class="flex flex-wrap items-end gap-3">
      <label class="text-sm font-medium">Retard d'au moins
        <select v-model.number="days" class="input mt-1 block" @change="load">
          <option v-for="d in [7, 15, 30, 60, 90]" :key="d" :value="d">{{ d }} jours</option>
        </select>
      </label>
      <button type="button" class="btn-secondary" :disabled="busy" @click="load">Simuler</button>
    </div>

    <div v-if="running" class="space-y-1">
      <p class="text-sm">🧹 Nettoyage en cours : {{ last.done }} / {{ last.total }} courses clôturées</p>
      <div class="h-2 rounded bg-slate-200"><div class="h-2 rounded bg-emerald-600" :style="{ width: `${last.total ? Math.round(last.done * 100 / last.total) : 3}%` }" /></div>
      <p class="text-xs text-gray-500">Vous pouvez quitter la page : le nettoyage continue sur le serveur.</p>
    </div>

    <template v-else>
      <p class="text-xs text-gray-500">Simulation : rien n'a encore été changé.</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border p-3 space-y-2">
          <div class="flex items-center gap-2"><p class="flex-1 font-medium">Jamais ramassées</p><span class="text-xl font-bold tabular-nums">{{ sim.never_picked }}</span></div>
          <p class="text-sm text-gray-600">Le colis n'a jamais quitté l'e-commerçant.</p>
          <p class="rounded bg-slate-100 px-2 py-1 text-sm font-medium">→ Annulées</p>
          <label class="flex items-center gap-2 text-sm"><input v-model="include" type="checkbox" value="never_picked"> Inclure dans le nettoyage</label>
        </div>
        <div class="rounded-lg border p-3 space-y-2">
          <div class="flex items-center gap-2"><p class="flex-1 font-medium">Ramassées, au dépôt ou en échec</p><span class="text-xl font-bold tabular-nums">{{ sim.not_delivered }}</span></div>
          <p class="text-sm text-gray-600">Jamais livrées.<template v-if="sim.held"> Dont {{ sim.held }} encore chez un livreur : elles passent sur son point de caisse comme « à rendre ».</template></p>
          <p class="rounded bg-blue-50 px-2 py-1 text-sm font-medium text-blue-900">→ Retournées à l'e-commerçant</p>
          <label class="flex items-center gap-2 text-sm"><input v-model="include" type="checkbox" value="not_delivered"> Inclure dans le nettoyage</label>
        </div>
        <div class="rounded-lg border border-amber-200 p-3 space-y-2">
          <div class="flex items-center gap-2"><p class="flex-1 font-medium">En chemin depuis longtemps</p><span class="text-xl font-bold tabular-nums">{{ sim.on_the_way }}</span></div>
          <p class="text-sm text-gray-600">Livrées ou pas ? On ne peut pas le deviner : aucune action automatique.</p>
          <RouterLink :to="{ path: '/admin/reglages/reprise/a-verifier', query: { days } }" class="block rounded bg-amber-50 px-2 py-1 text-sm font-semibold text-amber-900">→ Liste à vérifier, livreur par livreur ›</RouterLink>
        </div>
        <div class="rounded-lg border p-3 space-y-2">
          <div class="flex items-center gap-2"><p class="flex-1 font-medium">Livrées, argent pas soldé</p><span class="text-xl font-bold tabular-nums">{{ sim.unsettled }}</span></div>
          <p class="text-sm text-gray-600">Argent encore chez le livreur ou pas reversé à l'e-commerçant : c'est de l'argent réel.</p>
          <p class="rounded bg-emerald-50 px-2 py-1 text-sm font-medium text-emerald-900">→ Pas touchées : à régler à la caisse</p>
        </div>
      </div>

      <div class="flex flex-wrap items-end gap-3 rounded-lg border p-3">
        <div class="flex-1 min-w-[16rem]">
          <p class="font-semibold">{{ toClose }} course(s) seront clôturées</p>
          <p class="text-sm text-gray-600">Chaque course garde une note « Clôturée lors du nettoyage de la reprise ». L'ancienne application ne pourra plus les rouvrir. Annulable pendant 7 jours.</p>
        </div>
        <label class="text-sm font-medium">Tapez CLÔTURER pour confirmer
          <input v-model="confirm" type="text" class="input mt-1 block" autocomplete="off">
        </label>
        <button type="button" class="btn-primary" :disabled="busy || !toClose || !confirmed" @click="start">Clôturer ces courses</button>
      </div>
    </template>

    <div v-if="last && last.status !== 'running'" class="flex flex-wrap items-center gap-3 rounded-lg bg-slate-50 p-3 text-sm">
      <p class="flex-1">
        <template v-if="last.status === 'undone'">Nettoyage du {{ dateTime(last.started_at) }} annulé le {{ dateTime(last.undone_at) }}.</template>
        <template v-else>✓ {{ last.done }} course(s) clôturées le {{ dateTime(last.finished_at) }}<template v-if="last.by"> par {{ last.by }}</template>.</template>
      </p>
      <button v-if="last.can_undo" type="button" class="btn-secondary" :disabled="busy" @click="undo">Annuler ce nettoyage (jusqu'au {{ date(last.undo_until) }})</button>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { date, dateTime } from '../utils/format'

/**
 * Réglages › Reprise de l'ancienne application › Anciennes courses à clôturer : simulation par cas, clôture en
 * arrière-plan (sans message), annulation pendant 7 jours ; les courses en chemin se vérifient sur une page à part.
 */
const toasts = useToastStore()
const state = ref(null)
const days = ref(30)
const include = ref(['never_picked', 'not_delivered'])
const confirm = ref('')
const busy = ref(false)
let timer = null

const sim = computed(() => state.value?.simulation ?? {})
const last = computed(() => state.value?.last ?? null)
const running = computed(() => last.value?.status === 'running')
const confirmed = computed(() => ['CLÔTURER', 'CLOTURER'].includes(confirm.value.trim().toUpperCase()))
const toClose = computed(() => (include.value.includes('never_picked') ? sim.value.never_picked ?? 0 : 0)
  + (include.value.includes('not_delivered') ? sim.value.not_delivered ?? 0 : 0))

async function call(request, success) {
  busy.value = true
  try {
    const { data } = await request()
    if (success) toasts.success(success)
    return data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return null
  } finally {
    busy.value = false
  }
}

async function load() {
  const data = await call(() => http.get('/company/legacy-purge', { params: { days: days.value } }))
  if (data) state.value = data
}

async function start() {
  const data = await call(() => http.post('/company/legacy-purge', { include: include.value, confirm: confirm.value.trim().toUpperCase() }, { params: { days: days.value } }), 'Nettoyage lancé.')
  if (data) {
    confirm.value = ''
    state.value = { ...state.value, last: data }
  }
}

async function undo() {
  if (!window.confirm('Annuler ce nettoyage ? Les courses reviennent à leur état d\'avant (sauf celles traitées depuis).')) return
  const data = await call(() => http.post(`/company/legacy-purge/${last.value.id}/undo`))
  if (data) {
    toasts.success(`${data.restored} course(s) remises comme avant.`)
    load()
  }
}

onMounted(() => {
  load()
  timer = setInterval(() => !document.hidden && running.value && !busy.value && load(), 5000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
