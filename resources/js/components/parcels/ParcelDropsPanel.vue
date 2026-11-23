<template>
  <!-- Dépôts en libre-service : colis déposés par les livreurs dans le bac, à contrôler -->
  <div class="space-y-4">
    <p class="text-sm text-slate-600">
      Les livreurs déposent leurs colis dans le bac « Retours livreurs » en scannant la borne du dépôt, chaque colis, et en prenant une photo.
      Contrôlez le bac : cochez (ou scannez) les colis présents. Un colis absent demande un motif ; le livreur est prévenu.
      Sans contrôle, le dépôt est confirmé automatiquement au bout de 24 h.
    </p>

    <p v-if="loaded && !pendingDrops.length" class="card p-6 text-center text-slate-600">Aucun dépôt à contrôler.</p>

    <section v-for="d in pendingDrops" :key="d.id" class="card p-4 space-y-3">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="font-semibold">{{ d.courier.name }} · {{ d.orders.length }} colis déposé{{ d.orders.length > 1 ? 's' : '' }}</p>
          <p class="text-sm text-slate-600">Le {{ dateTime(d.dropped_at) }} · confirmé automatiquement le {{ dateTime(d.auto_confirm_at) }} sans contrôle</p>
        </div>
        <AuthImage v-if="d.has_photo" :src="`/parcel-drops/${d.id}/photo`" alt="Photo du bac prise par le livreur" class="h-24 w-32 rounded object-cover ring-1 ring-slate-200 cursor-zoom-in" @click="zoom = d" />
      </header>

      <ul class="border rounded divide-y text-sm">
        <li v-for="o in d.orders.filter((x) => x.pending)" :key="o.id" :class="['p-2 space-y-1.5', state(d).found.includes(o.id) ? 'bg-emerald-50' : '']">
          <label class="flex items-center gap-2">
            <input v-model="state(d).found" type="checkbox" :value="o.id" :aria-label="`Colis ${o.tracking_code} présent dans le bac`">
            <span class="font-mono text-xs">{{ o.tracking_code }}</span>
            <span class="flex-1 min-w-0 truncate">{{ o.recipient_name }} <span class="text-xs text-slate-600">· {{ o.merchant }}</span></span>
            <span v-if="d.typed_codes.includes(o.tracking_code)" class="text-xs text-amber-800" title="Étiquette illisible : le livreur a tapé le code">⌨ code tapé</span>
            <span class="text-xs text-slate-600">{{ o.status_label }}</span>
          </label>
          <div v-if="!state(d).found.includes(o.id)" class="flex flex-wrap items-center gap-2 pl-6">
            <select v-model="reasonOf(d, o).reason" class="input !py-1 text-xs sm:w-56" :aria-label="`Motif pour ${o.tracking_code}`">
              <option :value="undefined" disabled>Absent du bac : pourquoi ? *</option>
              <option v-for="r in DROP_REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
            <input v-if="reasonOf(d, o).reason === 'other'" v-model="reasonOf(d, o).note" class="input !py-1 text-xs flex-1 min-w-40" maxlength="500" placeholder="Précisez (obligatoire)">
          </div>
        </li>
      </ul>

      <p v-if="state(d).error" class="field-error">{{ state(d).error }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" @click="scanning = d"><Icone nom="scanner" /> Scanner les colis du bac</button>
        <button type="button" class="btn-secondary" @click="state(d).found = d.orders.filter((x) => x.pending).map((x) => x.id)">Tout présent</button>
        <button type="button" class="btn-primary ml-auto" :disabled="state(d).busy" @click="control(d)">
          Valider le contrôle ({{ state(d).found.length }} présent{{ state(d).found.length > 1 ? 's' : '' }}<template v-if="absent(d).length">, {{ absent(d).length }} absent{{ absent(d).length > 1 ? 's' : '' }}</template>)
        </button>
      </div>
    </section>

    <details v-if="doneDrops.length" class="card p-4">
      <summary class="cursor-pointer font-semibold">Dépôts contrôlés ces 7 derniers jours ({{ doneDrops.length }})</summary>
      <ul class="mt-3 divide-y text-sm">
        <li v-for="d in doneDrops" :key="d.id" class="py-2 flex flex-wrap justify-between gap-2">
          <span>{{ d.courier.name }} · {{ d.orders.length }} colis · déposés le {{ dateTime(d.dropped_at) }}</span>
          <span :class="d.status === 'auto' ? 'text-amber-700' : 'text-emerald-700'">
            {{ d.status === 'auto' ? 'Confirmé automatiquement (non contrôlé)' : `Contrôlé par ${d.controlled_by || '—'} le ${dateTime(d.controlled_at)}` }}
          </span>
        </li>
      </ul>
    </details>

    <Modal :open="!!zoom" title="Photo du bac" wide @close="zoom = null">
      <AuthImage v-if="zoom" :src="`/parcel-drops/${zoom.id}/photo`" alt="Photo du bac prise par le livreur" class="w-full rounded" />
    </Modal>
    <QrScanner v-if="scanning" title="Scanner l'étiquette d'un colis du bac" hint="Visez le QR code de l'étiquette." @read="onScan" @close="scanning = null" />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import AuthImage from '../AuthImage.vue'
import Modal from '../Modal.vue'
import QrScanner from '../courier/QrScanner.vue'
import Icone from '../ui/Icone.vue'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'
import { DROP_REASONS, codeFromScan, incomplete } from '../../utils/parcelReception'

const emit = defineEmits(['changed'])
const toasts = useToastStore()
const drops = ref([])
const loaded = ref(false)
const zoom = ref(null)
const scanning = ref(null)
const states = reactive({})

const pendingDrops = computed(() => drops.value.filter((d) => d.status === 'pending'))
const doneDrops = computed(() => drops.value.filter((d) => d.status !== 'pending'))
const state = (d) => (states[d.id] ??= { found: [], reasons: {}, busy: false, error: '' })
const reasonOf = (d, o) => (state(d).reasons[o.id] ??= { reason: undefined, note: '' })
const absent = (d) => d.orders.filter((o) => o.pending && !state(d).found.includes(o.id))

async function load() {
  try {
    drops.value = (await http.get('/parcel-drops')).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loaded.value = true
  }
}

function onScan(text) {
  const d = scanning.value
  const code = codeFromScan(text)
  const o = d.orders.find((x) => x.pending && x.tracking_code.toUpperCase() === code)
  if (!o) {
    toasts.error(`${code} ne fait pas partie de ce dépôt.`)
  } else if (!state(d).found.includes(o.id)) {
    state(d).found.push(o.id)
    toasts.success(`${o.tracking_code} présent (${state(d).found.length}/${d.orders.filter((x) => x.pending).length}).`)
  }
  // Scanner suivant
  scanning.value = null
  setTimeout(() => { if (absent(d).length) scanning.value = d }, 600)
}

async function control(d) {
  const s = state(d)
  s.error = ''
  const lacking = incomplete(absent(d), s.reasons)
  if (lacking.length) {
    s.error = `Indiquez pourquoi ces colis ne sont pas dans le bac : ${lacking.join(', ')}.`
    return
  }
  s.busy = true
  try {
    await http.post(`/parcel-drops/${d.id}/control`, {
      found_ids: s.found,
      missing: absent(d).map((o) => ({ order_id: o.id, reason: s.reasons[o.id].reason, note: s.reasons[o.id].note?.trim() || undefined })),
    })
    toasts.success(absent(d).length ? `Contrôle validé : ${d.courier.name} est prévenu des colis absents.` : 'Contrôle validé : colis confirmés au dépôt.')
    await load()
    emit('changed')
  } catch (e) {
    s.error = apiErrorMessage(e)
  } finally {
    s.busy = false
  }
}

onMounted(load)
defineExpose({ load })
</script>
