<template>
  <!-- Trajets particuliers : un prix d'un départ précis vers une arrivée, avant le prix de la zone -->
  <section class="space-y-3">
    <div class="flex flex-wrap items-center gap-3">
      <p class="flex-1 text-sm text-slate-600">
        Avancé, pour les rares cas où le prix dépend aussi du point de départ : il passe avant le prix de la zone.
        Ligne = départ, colonne = arrivée. Une case grisée reprend le prix du sens inverse ; videz une case pour retirer le trajet.
      </p>
      <label class="flex items-center gap-2 text-sm"><input v-model="showNeighborhoods" type="checkbox"> Afficher les quartiers</label>
    </div>

    <p v-if="!loaded" class="text-slate-600">Chargement…</p>
    <div v-else class="card max-h-[65vh] overflow-auto">
      <table class="border-collapse text-xs">
        <thead class="sticky top-0 z-10 bg-slate-100">
          <tr>
            <th class="sticky left-0 bg-slate-100 p-2">Départ \ Arrivée</th>
            <th v-for="z in matrixZones" :key="z.id" class="whitespace-nowrap p-2 font-medium">{{ z.name }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="from in matrixZones" :key="from.id">
            <th class="sticky left-0 whitespace-nowrap bg-slate-50 p-2 text-left font-medium">{{ from.full_name }}</th>
            <td v-for="to in matrixZones" :key="to.id" class="border p-0.5">
              <input
                :value="cell(from.id, to.id) ?? ''" :placeholder="mirror(from.id, to.id) ?? ''"
                :class="['w-16 rounded px-1 py-1 text-right', mirror(from.id, to.id) !== null && cell(from.id, to.id) === null ? 'bg-slate-100' : '']"
                inputmode="numeric" :aria-label="`${from.name} vers ${to.name}`"
                @change="setCell(from.id, to.id, $event.target.value)"
              >
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="dirty" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status">
      Modifications non enregistrées.
      <button type="button" class="ml-2 underline" @click="load">Annuler les modifications</button>
    </p>
    <button type="button" class="btn-primary" :disabled="saving || !loaded || !dirty" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer les trajets' }}</button>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { confirmDialog } from '../../composables/useDialog'

const props = defineProps({ gridId: { type: Number, required: true } })

const toasts = useToastStore()
const zones = ref([])
const prices = ref({}) // "départ-arrivée" -> prix
const loaded = ref(false)
const dirty = ref(false)
const saving = ref(false)
const showNeighborhoods = ref(false)
const leaveWarning = 'Des trajets modifiés ne sont pas enregistrés. Quitter quand même ?'

const key = (a, b) => `${a}-${b}`
const cell = (a, b) => prices.value[key(a, b)] ?? null
const mirror = (a, b) => (a === b ? null : prices.value[key(b, a)] ?? null)

// Communes actives (et lieux qui ont déjà un trajet) ; les quartiers à la demande
const matrixZones = computed(() => {
  const used = new Set(Object.keys(prices.value).flatMap((k) => k.split('-').map(Number)))
  return zones.value.filter((z) => used.has(z.id) || (z.is_active && (showNeighborhoods.value || !z.parent_id)))
})

function setCell(a, b, raw) {
  dirty.value = true
  const value = raw === '' ? null : Number(raw)
  if (value === null || Number.isNaN(value)) delete prices.value[key(a, b)]
  else prices.value[key(a, b)] = value
}

async function load() {
  loaded.value = false
  const grid = (await http.get(`/pricing-grids/${props.gridId}`)).data.data
  prices.value = Object.fromEntries(grid.rules.map((r) => [key(r.origin_zone_id, r.destination_zone_id), r.price]))
  dirty.value = false
  loaded.value = true
}

async function save() {
  saving.value = true
  try {
    // Un trajet est symétrique si le sens inverse n'a pas de prix propre
    const rules = Object.entries(prices.value).map(([k, price]) => {
      const [o, d] = k.split('-').map(Number)
      return { origin_zone_id: o, destination_zone_id: d, price, is_symmetric: prices.value[key(d, o)] === undefined }
    })
    await http.put(`/pricing-grids/${props.gridId}/rules`, { rules })
    toasts.success('Trajets enregistrés.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onBeforeRouteLeave(async () => !dirty.value || await confirmDialog(leaveWarning))
function warnBeforeUnload(event) {
  if (dirty.value) event.preventDefault()
}
window.addEventListener('beforeunload', warnBeforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))

onMounted(async () => {
  zones.value = (await http.get('/zones')).data.data
  await load()
})
</script>
