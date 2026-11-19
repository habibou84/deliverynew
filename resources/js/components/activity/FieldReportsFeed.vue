<template>
  <!-- Remontées terrain à traiter, en direct : les plus récentes en haut, « Traité » en un clic -->
  <section class="card overflow-hidden" aria-labelledby="feed-title">
    <div class="flex items-center gap-2 border-b p-4">
      <h2 id="feed-title" class="flex-1 font-bold">Remontées terrain</h2>
      <span v-if="store.open.total" class="rounded-full bg-red-600 px-2.5 py-0.5 text-xs font-bold text-white">{{ store.open.total }} à traiter</span>
    </div>
    <p v-if="!loaded" class="p-4 text-sm text-slate-600">Chargement…</p>
    <p v-else-if="!reports.length" class="flex items-center gap-2 p-4 text-sm text-emerald-700"><Icone nom="valider" taille="petite" /> Rien à traiter pour le moment.</p>
    <ul v-else class="divide-y">
      <li v-for="r in reports" :key="r.id" :class="['space-y-1 p-4', fresh.has(r.id) ? 'bg-amber-50' : '']">
        <p class="text-sm">
          <b>{{ r.courier.name }}</b>
          <template v-if="r.order"> · <RouterLink :to="`/admin/courses/${r.order.id}`" class="font-mono text-blue-700 hover:underline">{{ r.order.tracking_code }}</RouterLink></template>
          · <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold ring-1', toneClass(KINDS[r.kind]?.tone || 'info')]">{{ title(r) }}</span>
          <span v-if="fresh.has(r.id)" class="ml-1 rounded bg-red-600 px-1.5 text-[11px] font-bold text-white">NOUVEAU</span>
        </p>
        <p v-if="r.note" class="text-[15px]">« {{ r.note }} »</p>
        <p v-if="r.order" class="text-xs text-slate-600">{{ r.order.recipient_name || 'Client' }} · {{ r.order.zone }} · {{ r.order.merchant }}</p>
        <div class="flex items-center justify-between gap-2 pt-1">
          <span class="text-xs text-slate-600">{{ ago(r.created_at) }}<template v-if="r.photos"> · {{ r.photos }} photo(s)</template></span>
          <button type="button" class="btn-primary !px-3 !py-1 text-sm" :disabled="busy === r.id" @click="handle(r)"><Icone nom="valider" taille="petite" /> Traité</button>
        </div>
      </li>
    </ul>
    <RouterLink to="/admin/activite/remontees" class="block border-t px-4 py-3 text-center text-sm font-semibold text-blue-700 hover:bg-slate-50">Toutes les remontées et l'historique ›</RouterLink>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useFieldReportStore } from '../../stores/fieldReports'
import { useToastStore } from '../../stores/toasts'
import { toneClass } from '../../utils/format'
import Icone from '../ui/Icone.vue'

const KINDS = {
  incident: { label: 'Problème', tone: 'critique' },
  note: { label: 'Note', tone: 'info' },
  refusal: { label: 'Mission refusée', tone: 'attention' },
  expense: { label: 'Frais déclarés', tone: 'info' },
}
const store = useFieldReportStore()
const toasts = useToastStore()
const reports = ref([])
const loaded = ref(false)
const busy = ref(null)
// Arrivées depuis l'ouverture de l'écran : mises en évidence
const fresh = ref(new Set())

const title = (r) => (r.kind === 'incident' ? r.incident || r.status_label || 'Problème' : KINDS[r.kind]?.label || 'Remontée')
function ago(at) {
  const min = Math.round((Date.now() - new Date(at).getTime()) / 60000)
  if (min < 1) return 'à l\'instant'
  if (min < 60) return `il y a ${min} min`
  if (min < 1440) return `il y a ${Math.floor(min / 60)} h`
  return `il y a ${Math.floor(min / 1440)} j`
}

async function load() {
  try {
    const { data } = await http.get('/field-reports', { params: { state: 'open', per_page: 8 } })
    const known = new Set(reports.value.map((r) => r.id))
    if (loaded.value) fresh.value = new Set([...fresh.value, ...data.data.filter((r) => !known.has(r.id)).map((r) => r.id)])
    reports.value = data.data
    store.setCounts(data.open)
  } finally {
    loaded.value = true
  }
}

async function handle(r) {
  busy.value = r.id
  try {
    const { data } = await http.post(`/field-reports/${r.id}/handle`, { comment: null })
    store.setCounts(data.open)
    reports.value = reports.value.filter((x) => x.id !== r.id)
    toasts.success('Remontée marquée comme traitée.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = null
  }
}

// Nouvelle remontée en direct (store) ou toutes les 30 s
watch(() => store.version, load)
let timer
onMounted(() => {
  load()
  timer = setInterval(() => !document.hidden && load(), 30000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
