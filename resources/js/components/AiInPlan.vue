<template>
  <div v-if="data" class="card space-y-3 p-4 text-sm">
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold">✨ Jibiat IA</h2>
      <span v-if="sub.included" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">{{ sub.tier_name }} · comprise dans votre formule</span>
      <span v-else-if="sub.on" class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800">{{ sub.tier_name }} · ancien abonnement</span>
      <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">Pas dans votre formule</span>
    </div>
    <p class="text-slate-600">L'IA lit les messages WhatsApp de vos e-commerçants et les transforme en courses, et conseille votre dispatch.</p>

    <!-- IA en service : consommation du mois -->
    <template v-if="sub.on">
      <p v-if="sub.paused" class="rounded-lg bg-amber-50 p-3 text-amber-900">⏸ L'IA est en pause jusqu'au mois prochain : la consommation a atteint la limite de votre niveau. Jibiat continue avec les règles habituelles. Une recharge la relance.</p>
      <p v-if="!sub.included && sub.stop_at" class="rounded-lg bg-slate-100 p-3">L'IA s'arrêtera le {{ date(sub.stop_at) }}. <button type="button" class="font-semibold text-violet-700" :disabled="busy" @click="act('resume')">Continuer l'IA</button></p>
      <Meter :label="`Courses traitées en ${monthLabel}`" :value="data.usage.orders" :max="data.usage.included_orders" />
      <Meter v-if="data.usage.voice_included" label="Notes vocales (minutes)" :value="data.usage.voice_minutes" :max="data.usage.voice_included" />
      <ul v-if="data.usage.features.length" class="flex flex-wrap gap-2">
        <li v-for="f in data.usage.features" :key="f.feature" class="rounded-lg bg-violet-50 px-2 py-1 text-violet-900"><b>{{ f.calls }}</b> · {{ f.label }}</li>
      </ul>
      <p class="text-xs text-slate-600">Au-delà du volume : {{ money(data.settings.overage_per_order) }} par course, sur votre facture.<template v-if="data.usage.recharges"> Recharges ce mois-ci : {{ money(data.usage.recharges) }}.</template></p>
      <div class="flex flex-wrap gap-2">
        <template v-if="sub.included || sub.status === 'active'">
          <button v-for="amount in data.recharges" :key="amount" type="button" class="btn-secondary" :disabled="busy" @click="recharge(amount)">Recharger {{ money(amount) }}</button>
        </template>
        <button v-if="!sub.included && !sub.stop_at" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="stop">Arrêter l'IA</button>
      </div>
    </template>

    <!-- Formule sans IA : formules qui la comprennent -->
    <div v-if="!sub.included" class="rounded-lg bg-violet-50 p-3 text-violet-900">
      <template v-if="data.plans_with_ai.length">
        <p class="font-medium">Jibiat IA est comprise dans ces formules :</p>
        <ul class="mt-1 space-y-0.5">
          <li v-for="p in data.plans_with_ai" :key="p.id">✓ <b>{{ p.name }}</b> ({{ money(p.price) }} / mois) : {{ p.tier_name }}</li>
        </ul>
        <p class="mt-1">Pour changer de formule, écrivez à l'équipe Jibiat.</p>
      </template>
      <p v-else>Bientôt comprise dans les formules Jibiat.</p>
    </div>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { date, money } from '../utils/format'
import { confirmDialog } from '../composables/useDialog'

/** Jibiat IA dans « Mon abonnement » : niveau compris dans la formule, consommation du mois, recharges */
const Meter = defineComponent({
  props: { label: String, value: Number, max: Number },
  setup(props) {
    return () => {
      const pct = props.max ? Math.min(100, Math.round(props.value * 100 / props.max)) : 0
      return h('div', [
        h('div', { class: 'mb-1 flex justify-between' }, [h('span', props.label), h('b', `${props.value.toLocaleString('fr-FR')} / ${props.max.toLocaleString('fr-FR')}`)]),
        h('div', { class: 'h-2.5 overflow-hidden rounded-full bg-slate-100' }, h('div', { class: ['h-full rounded-full', pct >= 80 ? 'bg-amber-500' : 'bg-violet-600'], style: { width: `${pct}%` } })),
      ])
    }
  },
})

const toasts = useToastStore()
const data = ref(null)
const error = ref('')
const busy = ref(false)

const sub = computed(() => data.value?.subscription ?? {})
const monthLabel = computed(() => (data.value ? new Date(`${data.value.usage.month}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long' }) : ''))

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

const act = (name) => run(() => http.post(`/company/ai/${name}`), name === 'resume' ? 'L\'IA continue.' : null)
async function stop() {
  const trialing = sub.value.status === 'trial'
  if (!await confirmDialog(trialing ? 'Arrêter l\'essai maintenant ?' : 'Arrêter Jibiat IA ? Elle continue jusqu\'à la fin de la période payée.')) return
  run(() => http.post('/company/ai/stop'), trialing ? 'Essai arrêté.' : 'Arrêt programmé.')
}
async function recharge(amount) {
  if (!await confirmDialog(`Recharger ${money(amount)} ? Ajouté à votre prochaine facture.`)) return
  run(() => http.post('/company/ai/recharge', { amount }), 'Recharge ajoutée.')
}

onMounted(() => run(() => http.get('/company/ai')))
</script>
