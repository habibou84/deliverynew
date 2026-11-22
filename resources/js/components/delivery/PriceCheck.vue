<template>
  <!-- Vérifier un prix : départ et arrivée, le prix calculé et d'où il vient -->
  <details class="card p-4" :open="open" @toggle="open = $event.target.open">
    <summary class="cursor-pointer font-semibold">Vérifier un prix</summary>
    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
      <div>
        <label class="label" for="check-from">Départ (ramassage)</label>
        <ZoneSelect id="check-from" v-model="from" :zones="zones" />
      </div>
      <div>
        <label class="label" for="check-to">Arrivée (livraison)</label>
        <ZoneSelect id="check-to" v-model="to" :zones="zones" />
      </div>
      <p v-if="result" class="text-right">
        <strong class="text-xl tabular-nums">{{ money(result.total) }}</strong>
      </p>
    </div>
    <p v-if="result" class="mt-2 text-sm text-slate-600">
      {{ result.source === 'group' ? 'Prix de la destination' : result.source === 'base' ? 'Prix de base (destination sans prix)' : `Trajet particulier : ${result.rule?.join(' → ')}` }}<template v-if="result.grid_id !== gridId"> (repris de la grille normale)</template>.
    </p>
    <p v-else-if="error" class="mt-2 text-sm text-red-700">{{ error }}</p>
  </details>
</template>

<script setup>
import { ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { money } from '../../utils/format'
import ZoneSelect from '../ZoneSelect.vue'

const props = defineProps({ gridId: { type: Number, required: true } })

const open = ref(false)
const zones = ref([])
const from = ref(null)
const to = ref(null)
const result = ref(null)
const error = ref('')

async function run() {
  result.value = null
  error.value = ''
  if (!from.value || !to.value) return
  try {
    result.value = (await http.get(`/pricing-grids/${props.gridId}/check`, { params: { pickup_zone_id: from.value, delivery_zone_id: to.value } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

watch(open, async (value) => {
  if (value && !zones.value.length) zones.value = (await http.get('/zones')).data.data
})
watch(() => [from.value, to.value, props.gridId], run)
</script>
