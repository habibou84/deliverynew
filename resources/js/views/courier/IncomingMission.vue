<template>
  <div :class="['min-h-[100dvh] flex flex-col items-center px-7 pt-16 pb-12 text-white', again ? 'bg-orange-900' : 'bg-emerald-900']">
    <p :class="['text-sm uppercase tracking-widest', again ? 'text-orange-200' : 'text-emerald-200']">Jibiat Livreur · {{ again ? '2e appel' : 'appel entrant' }}</p>

    <div :class="['mt-6 h-28 w-28 rounded-full grid place-items-center ring-[14px]', again ? 'bg-orange-500 ring-orange-500/25' : 'bg-emerald-500 ring-emerald-500/25']">
      <svg v-if="isPickup" class="h-14 w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5z" /><path d="M3 8l9 5 9-5" /><path d="M12 13v8" /></svg>
      <svg v-else class="h-14 w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h11v9H3z" /><path d="M14 10h4l3 3v3h-7" /><circle cx="7" cy="18" r="2" /><circle cx="17" cy="18" r="2" /></svg>
    </div>

    <p v-if="loading" class="mt-10 text-lg">Chargement…</p>
    <template v-else-if="ring">
      <h1 class="mt-8 text-3xl font-extrabold text-center">{{ ring.title }}</h1>
      <p v-if="ring.line1" class="mt-3 text-xl font-semibold text-center">{{ ring.line1 }}</p>
      <p v-if="ring.line2" class="mt-1 text-lg text-center opacity-90">{{ ring.line2 }}</p>
      <p v-if="again" class="mt-4 rounded-full bg-white/15 px-4 py-2 text-sm font-semibold">Pas de réponse au premier appel</p>
      <p v-if="error" class="mt-4 rounded-xl bg-red-600 px-4 py-2 text-sm text-center">{{ error }}</p>
    </template>
    <template v-else>
      <h1 class="mt-8 text-2xl font-bold text-center">Mission déjà traitée</h1>
      <p class="mt-2 text-center opacity-90">Elle a été reprise ou retirée par l'agence.</p>
    </template>

    <div class="flex-1" />

    <!-- Refus : la raison, comme dans la fiche mission -->
    <div v-if="refusing" class="w-full rounded-2xl bg-white p-4 text-slate-900 space-y-3">
      <ChoiceChips v-model="reason" :options="refusalOptions" label="Pourquoi refusez-vous ?" />
      <div class="grid grid-cols-2 gap-2">
        <button type="button" class="m-btn-secondary" :disabled="busy" @click="refusing = false">Retour</button>
        <button type="button" class="m-btn-danger" :disabled="busy || !reason" @click="refuse">Refuser</button>
      </div>
    </div>
    <div v-else-if="ring" class="w-full flex justify-between">
      <button type="button" class="flex w-32 flex-col items-center gap-2.5" :disabled="busy" @click="refusing = true">
        <span class="h-20 w-20 rounded-full bg-red-600 grid place-items-center"><svg class="h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></span>
        <span class="text-lg font-semibold">Refuser</span>
      </button>
      <button type="button" class="flex w-32 flex-col items-center gap-2.5" :disabled="busy" @click="accept">
        <span class="h-20 w-20 rounded-full bg-green-500 grid place-items-center"><svg class="h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg></span>
        <span class="text-lg font-semibold">{{ toAccept.length ? 'Accepter' : "J'y vais" }}</span>
      </button>
    </div>
    <button v-else type="button" class="m-btn-secondary w-full" @click="leave()">Voir mes missions</button>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { stopRing } from '../../native/ring'
import { refusalOptions } from '../../utils/refusals'

/**
 * Écran « appel entrant » : ouvert par la sonnerie d'une nouvelle mission (application Android). Accepter ou
 * refuser arrête la sonnerie et le rappel de deux minutes.
 */
const route = useRoute()
const router = useRouter()
const ids = String(route.query.ids || '')
const again = String(route.query.attempt || '1') === '2'
const ring = ref(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const refusing = ref(false)
const reason = ref('')

const missions = computed(() => ring.value?.missions ?? [])
const active = computed(() => missions.value.filter((m) => ['assigned', 'accepted'].includes(m.status)))
const toAccept = computed(() => active.value.filter((m) => m.status === 'assigned'))
const isPickup = computed(() => !missions.value.length || missions.value[0].type === 'pickup')

onMounted(async () => {
  try {
    const data = (await http.get('/courier/ring', { params: { ids, attempt: again ? 2 : 1 } })).data.data
    ring.value = data && data.missions.some((m) => ['assigned', 'accepted'].includes(m.status)) ? data : null
  } catch {
    ring.value = null
  } finally {
    loading.value = false
  }
  if (!ring.value) stopRing()
})

async function answered() {
  await http.post('/courier/ring/answer', { ids }).catch(() => {})
  await stopRing()
}

function leave() {
  stopRing()
  // Une seule mission : sa fiche ; plusieurs : la liste
  router.replace(active.value.length === 1 ? `/livreur/missions/${active.value[0].id}` : '/livreur')
}

async function accept() {
  busy.value = true
  error.value = ''
  try {
    for (const m of toAccept.value) await http.post(`/courier/assignments/${m.id}/accept`)
    await answered()
    leave()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function refuse() {
  busy.value = true
  error.value = ''
  try {
    for (const m of active.value) await http.post(`/courier/assignments/${m.id}/refuse`, { reason: reason.value })
    await answered()
    router.replace('/livreur')
  } catch (e) {
    error.value = apiErrorMessage(e)
    refusing.value = false
  } finally {
    busy.value = false
  }
}
</script>
