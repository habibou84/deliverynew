<template>
  <div v-if="data" class="card space-y-3 p-4 text-sm">
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold">✨ Jibiat IA</h2>
      <span v-if="data.points.monthly" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">comprise dans votre formule</span>
      <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">pas dans votre formule</span>
    </div>
    <p class="text-slate-600">L'IA lit les commandes que vos e-commerçants envoient sur WhatsApp et les transforme en courses.</p>

    <template v-if="data.points.monthly">
      <!-- Compteur de points du mois -->
      <div>
        <div class="mb-1 flex justify-between"><span>Points IA de {{ monthLabel }}</span><b>{{ data.points.left.toLocaleString('fr-FR') }} restants sur {{ data.points.monthly.toLocaleString('fr-FR') }}</b></div>
        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100"><div :class="['h-full rounded-full', percent >= 80 ? 'bg-amber-500' : 'bg-violet-600']" :style="{ width: `${percent}%` }" /></div>
      </div>
      <p v-if="stopped" class="rounded-lg bg-amber-50 p-3 text-amber-900">⏸ {{ stopped }} Jibiat continue avec les règles habituelles : les commandes écrites sont toujours reçues.</p>
      <ul v-if="data.usage.length" class="flex flex-wrap gap-2">
        <li v-for="u in data.usage" :key="u.feature" class="rounded-lg bg-violet-50 px-2 py-1 text-violet-900"><b>{{ u.calls }}</b> · {{ u.label.toLowerCase() }} ({{ u.points }} pt)</li>
      </ul>
      <p class="text-xs text-slate-600">
        <template v-for="(p, i) in data.prices" :key="p.key">{{ i ? ' · ' : '' }}{{ p.label }} : {{ p.points }} point{{ p.points > 1 ? 's' : '' }}<template v-if="!p.available"> (bientôt)</template></template>.
        Les points repartent à zéro le 1er du mois.
      </p>
    </template>

    <!-- Ancien abonnement IA à part -->
    <p v-if="data.legacy" class="rounded-lg bg-slate-100 p-3">
      Ancien abonnement {{ data.legacy.name }}, facturé à part jusqu'à son arrêt.
      <template v-if="data.legacy.stop_at"> Il s'arrêtera le {{ date(data.legacy.stop_at) }}. <button type="button" class="font-semibold text-violet-700" :disabled="busy" @click="act('resume')">Le continuer</button></template>
      <button v-else type="button" class="font-semibold text-red-700" :disabled="busy" @click="stop">L'arrêter</button>
    </p>

    <!-- Formule sans points : formules qui en ont -->
    <div v-if="!data.points.monthly" class="rounded-lg bg-violet-50 p-3 text-violet-900">
      <template v-if="data.plans_with_ai.length">
        <p class="font-medium">Formules avec des points d'IA :</p>
        <ul class="mt-1 space-y-0.5">
          <li v-for="p in data.plans_with_ai" :key="p.id">✓ <b>{{ p.name }}</b> ({{ money(p.price) }} / mois) : {{ p.points.toLocaleString('fr-FR') }} points par mois</li>
        </ul>
        <p class="mt-1">Pour changer de formule, écrivez à l'équipe Jibiat.</p>
      </template>
      <p v-else>Bientôt dans les formules Jibiat.</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { date, money } from '../utils/format'
import { confirmDialog } from '../composables/useDialog'

/** Jibiat IA dans « Mon abonnement » : points du mois compris dans la formule, consommation, ancien abonnement à part */
const toasts = useToastStore()
const data = ref(null)
const error = ref('')
const busy = ref(false)

const monthLabel = computed(() => (data.value ? new Date(`${data.value.month}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long' }) : ''))
const percent = computed(() => {
  const p = data.value?.points
  return p?.monthly ? Math.min(100, Math.round(p.used * 100 / p.monthly)) : 0
})
// Pourquoi l'IA ne sert pas en ce moment (sans parler du plafond, interne à Jibiat)
const stopped = computed(() => {
  const d = data.value
  if (!d) return ''
  if (!d.enabled || d.disabled) return 'Jibiat IA est arrêtée pour le moment.'
  if (d.points.left <= 0) return 'Vos points du mois sont épuisés : l\'IA reprend le 1er du mois.'
  if (d.paused) return 'L\'IA est en pause jusqu\'au 1er du mois.'
  return ''
})

async function run(request, success) {
  busy.value = true
  error.value = ''
  try {
    data.value = (await request()).data.data
    if (success) toasts.success(success)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

const act = (name) => run(() => http.post(`/company/ai/${name}`), name === 'resume' ? 'L\'abonnement continue.' : null)
async function stop() {
  if (!await confirmDialog('Arrêter l\'ancien abonnement Jibiat IA ? Il continue jusqu\'à la fin de la période payée.')) return
  run(() => http.post('/company/ai/stop'), 'Arrêt programmé.')
}

onMounted(() => run(() => http.get('/company/ai')))
</script>
