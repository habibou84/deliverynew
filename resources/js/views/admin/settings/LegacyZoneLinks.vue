<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/reglages/reprise/controles" class="text-sm font-medium text-emerald-700">‹ Contrôles et ménage</RouterLink>
      <h1 class="text-xl font-bold">Rattacher les zones reprises</h1>
      <p class="text-sm text-gray-600">
        Les zones venues de l'ancienne application sont rapprochées de la liste commune des lieux. Validez chaque
        correspondance : les courses, les prix, les e-commerçants, les destinataires et le planning passent sur le lieu
        standard. Rien n'est envoyé à personne ; annulable pendant 7 jours.
      </p>
    </div>

    <p v-if="!state" class="text-gray-500">Chargement…</p>
    <template v-else>
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-900">{{ counts.sure }} sûre(s)</span>
        <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-900">{{ counts.confirm }} à confirmer</span>
        <span class="rounded-full bg-slate-200 px-3 py-1 text-sm font-semibold text-slate-700">{{ counts.none }} sans correspondance</span>
        <span class="flex-1" />
        <button v-if="counts.sure" type="button" class="btn-primary" :disabled="busy" @click="linkSure">Valider les {{ counts.sure }} sûres</button>
      </div>

      <p v-if="!state.proposals.length" class="card p-4 text-sm text-emerald-800">✅ Toutes les zones sont rattachées à un lieu standard ou gardées telles quelles.</p>

      <div v-else class="card divide-y">
        <div v-for="p in state.proposals" :key="p.id" :class="['flex flex-wrap items-center gap-3 p-3', p.confidence === 'confirm' ? 'bg-amber-50' : '']">
          <div class="min-w-[10rem] flex-1">
            <p class="font-semibold">{{ p.name }}<span v-if="p.parent" class="font-normal text-gray-500"> · {{ p.parent }}</span></p>
            <p class="text-xs text-gray-500">{{ p.orders }} course(s)<template v-if="p.legacy_price"> · ancien prix {{ money(p.legacy_price) }}</template></p>
          </div>
          <div class="min-w-[12rem] flex-1 text-sm">
            <template v-if="p.place">
              <span class="font-medium">{{ p.place.label }}</span>
              <span class="block text-xs" :class="p.confidence === 'sure' ? 'text-emerald-700' : 'text-amber-800'">
                {{ p.confidence === 'sure' ? 'Sûr' : 'À confirmer' }}{{ p.merge_into ? ` · fusion avec la zone « ${p.merge_into.name} »` : '' }}
              </span>
            </template>
            <span v-else class="text-gray-500">Aucun lieu trouvé</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <button v-if="p.place" type="button" class="btn-primary" :disabled="busy" @click="link(p, p.place.code)">{{ p.merge_into ? 'Fusionner' : 'Valider' }}</button>
            <button type="button" class="btn-secondary" :disabled="busy" @click="choosing = choosing === p.id ? null : p.id">Autre lieu…</button>
            <button type="button" class="btn-secondary" :disabled="busy" @click="keep(p)">Garder telle quelle</button>
          </div>
          <div v-if="choosing === p.id" class="relative w-full">
            <label class="label" :for="`place-${p.id}`">Lieu standard pour « {{ p.name }} »</label>
            <input :id="`place-${p.id}`" v-model="placeQuery" class="input" placeholder="Tapez un quartier ou une commune…" autocomplete="off" @input="searchPlaces">
            <ul v-if="places.length" class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded border bg-white shadow">
              <li v-for="pl in places" :key="pl.code">
                <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50" @click="link(p, pl.code)">{{ pl.label }}</button>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <section class="card flex flex-wrap items-center gap-3 p-4">
        <div class="flex-1 min-w-[16rem]">
          <h2 class="font-semibold">{{ state.unknown_orders }} course(s) « À préciser »</h2>
          <p class="text-sm text-gray-600">La commune écrite dans l'ancienne application est relue et cherchée de nouveau (noms, anciens noms retenus, liste commune). Seule la zone change : ni prix, ni message.</p>
          <p v-if="reclassed" class="mt-1 text-sm text-emerald-800">{{ reclassed.reclassed }} reclassée(s) sur {{ reclassed.checked }} relue(s) · encore {{ reclassed.left }}.</p>
        </div>
        <button type="button" class="btn-secondary" :disabled="busy || !state.unknown_orders || !state.can_reclass" @click="reclass">Reclasser</button>
      </section>

      <section v-if="state.recent.length" class="card p-4 space-y-2">
        <h2 class="font-semibold">Derniers rattachements</h2>
        <div v-for="l in state.recent" :key="l.id" class="flex flex-wrap items-center gap-2 text-sm">
          <span class="flex-1">{{ l.zone }} → {{ l.place }} <span class="text-gray-500">({{ l.action === 'merge' ? 'fusion' : 'rattachement' }}, {{ dateTime(l.created_at) }})</span></span>
          <button type="button" class="btn-secondary" :disabled="busy" @click="undo(l)">Annuler</button>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { dateTime, money } from '../../../utils/format'

/**
 * Reprise › Rattacher les zones : zones reprises rapprochées des lieux de la liste commune, courses « À préciser ».
 */
const toasts = useToastStore()
const state = ref(null)
const busy = ref(false)
const choosing = ref(null)
const placeQuery = ref('')
const places = ref([])
const reclassed = ref(null)

const counts = computed(() => {
  const c = { sure: 0, confirm: 0, none: 0 }
  for (const p of state.value?.proposals ?? []) c[p.confidence]++
  return c
})

async function load() {
  state.value = (await http.get('/company/zone-links')).data.data
}

async function run(request, success) {
  busy.value = true
  try {
    const { data } = await request()
    if (success || data.message) toasts.success(success || data.message)
    choosing.value = null
    await load()
    return data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return null
  } finally {
    busy.value = false
  }
}

const link = (p, code) => run(() => http.post(`/company/zone-links/zones/${p.id}`, { place: code }))
const keep = (p) => run(() => http.post(`/company/zone-links/zones/${p.id}/keep`), `« ${p.name} » gardée comme zone de l'entreprise.`)
const undo = (l) => run(() => http.post(`/company/zone-links/${l.id}/undo`), 'Rattachement annulé.')

async function linkSure() {
  if (!window.confirm(`Rattacher les ${counts.value.sure} zones sûres à leur lieu standard ?`)) return
  const data = await run(() => http.post('/company/zone-links/sure'))
  if (data) toasts.success(`${data.count} zone(s) rattachée(s).`)
}

async function reclass() {
  const data = await run(() => http.post('/company/zone-links/reclass'))
  if (data) reclassed.value = data
}

let timer = null
function searchPlaces() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    places.value = placeQuery.value.trim().length < 2 ? [] : (await http.get('/company/zone-links/places', { params: { q: placeQuery.value } })).data.data
  }, 200)
}

onMounted(() => load().catch((e) => toasts.error(apiErrorMessage(e))))
</script>
