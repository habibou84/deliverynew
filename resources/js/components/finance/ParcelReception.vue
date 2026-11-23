<template>
  <!-- Colis d'un livreur reçus au dépôt : à rendre (cochés), gardés pour leur livraison, déjà déposés dans le bac -->
  <div class="space-y-3">
    <div v-if="due.length" class="space-y-1">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold">📦 Colis à rendre au dépôt ({{ due.length }})</p>
        <button type="button" class="text-xs text-blue-700" @click="toggleDue">{{ allDue ? 'Tout décocher' : 'Tout cocher' }}</button>
      </div>
      <p class="text-xs text-slate-600">Comptez les colis que le livreur vous remet. Décochez un colis manquant : le motif est obligatoire et le livreur est prévenu.</p>
      <ul class="border rounded divide-y max-h-72 overflow-y-auto text-sm">
        <li v-for="p in due" :key="p.id" :class="['p-2 space-y-1.5', checked(p) ? 'bg-emerald-50' : 'bg-amber-50']">
          <label class="flex items-center gap-2">
            <input :checked="checked(p)" type="checkbox" :aria-label="`Colis ${p.tracking_code} reçu`" @change="toggle(p)">
            <span class="font-mono text-xs">{{ p.tracking_code }}</span>
            <span class="flex-1 min-w-0 truncate">{{ p.recipient_name || p.recipient_phone }} <span class="text-xs text-slate-600">· {{ p.merchant }}</span></span>
            <span class="text-xs text-slate-600 whitespace-nowrap">{{ p.rescheduled_to ? `Reporté au ${date(p.rescheduled_to)}` : (p.incident || p.status_label) }}</span>
            <span :class="['text-xs whitespace-nowrap', isOld(p.held_since) ? 'text-red-600 font-semibold' : 'text-slate-600']">{{ heldFor(p.held_since) }}</span>
          </label>
          <!-- Décoché : pourquoi ? -->
          <div v-if="!checked(p)" class="flex flex-wrap items-center gap-2 pl-6">
            <select v-model="reasonOf(p).reason" class="input !py-1 text-xs sm:w-56" :aria-label="`Motif pour ${p.tracking_code}`">
              <option :value="undefined" disabled>Motif : pourquoi pas reçu ? *</option>
              <option v-for="r in GAP_REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
            <input v-if="reasonOf(p).reason === 'other'" v-model="reasonOf(p).note" class="input !py-1 text-xs flex-1 min-w-40" maxlength="500" placeholder="Précisez (obligatoire)">
          </div>
        </li>
      </ul>
      <p v-if="missingCount" class="text-xs text-amber-800">⚠ {{ missingCount }} colis non reçu{{ missingCount > 1 ? 's' : '' }} : {{ courierName || 'le livreur' }} recevra une notification avec le motif et pourra confirmer ou contester.</p>
    </div>

    <div v-if="kept.length" class="space-y-1">
      <p class="text-sm font-semibold">🌙 Gardés pour leur livraison ({{ kept.length }})</p>
      <p class="text-xs text-slate-600">Le livreur les garde pour les livrer : cochez seulement s'il vous les remet quand même.</p>
      <ul class="border rounded divide-y max-h-40 overflow-y-auto text-sm">
        <li v-for="p in kept" :key="p.id" class="flex items-center gap-2 p-2">
          <input :checked="checked(p)" type="checkbox" :aria-label="`Colis ${p.tracking_code} reçu`" @change="toggle(p)">
          <span class="font-mono text-xs">{{ p.tracking_code }}</span>
          <span class="flex-1 min-w-0 truncate">{{ p.recipient_name || p.recipient_phone }}</span>
          <span class="text-xs text-indigo-700 whitespace-nowrap">Livraison du {{ date(p.kept_for) }}</span>
        </li>
      </ul>
    </div>

    <p v-if="dropped.length" class="rounded bg-sky-50 p-2 text-xs text-sky-900">
      🧺 {{ dropped.length }} colis déjà déposé{{ dropped.length > 1 ? 's' : '' }} dans le bac du dépôt, en attente du contrôle : {{ dropped.map((p) => p.tracking_code).join(', ') }}.
    </p>
    <p v-if="toMerchant.length" class="rounded bg-slate-50 p-2 text-xs text-slate-700">
      🔁 {{ toMerchant.length }} colis à rapporter au marchand (mission retour) : ils ne passent pas par le dépôt.
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { date } from '../../utils/format'
import { GAP_REASONS } from '../../utils/parcelReception'

const props = defineProps({
  parcels: { type: Array, required: true },
  // Motifs des colis décochés, par colis : { [id]: { reason, note } } (objet réactif du parent)
  reasons: { type: Object, required: true },
  courierName: { type: String, default: '' },
})
const returned = defineModel('returned', { type: Array, required: true })

const due = computed(() => props.parcels.filter((p) => p.due))
const kept = computed(() => props.parcels.filter((p) => !p.due && p.kept_for && !p.on_the_road && !p.dropped))
const dropped = computed(() => props.parcels.filter((p) => p.dropped))
const toMerchant = computed(() => props.parcels.filter((p) => p.return_mission && !p.dropped))
const checked = (p) => returned.value.includes(p.id)
const allDue = computed(() => due.value.every(checked))
const missingCount = computed(() => due.value.filter((p) => !checked(p)).length)

function reasonOf(p) {
  if (!props.reasons[p.id]) props.reasons[p.id] = { reason: undefined, note: '' }
  return props.reasons[p.id]
}
function toggle(p) {
  returned.value = checked(p) ? returned.value.filter((id) => id !== p.id) : [...returned.value, p.id]
}
function toggleDue() {
  const ids = due.value.map((p) => p.id)
  returned.value = allDue.value ? returned.value.filter((id) => !ids.includes(id)) : [...new Set([...returned.value, ...ids])]
}

const isOld = (since) => since && Date.now() - new Date(since).getTime() > 24 * 3600000
function heldFor(since) {
  const hours = Math.floor((Date.now() - new Date(since).getTime()) / 3600000)
  if (hours < 1) return "depuis moins d'1 h"
  return hours >= 24 ? `depuis ${Math.floor(hours / 24)} j` : `depuis ${hours} h`
}
</script>
