<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">✨ Jibiat IA</h1>
      <p class="text-sm text-slate-600">L'intelligence artificielle de Jibiat, comprise dans votre formule.</p>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>

    <template v-if="data">
      <!-- Résumé -->
      <div class="grid gap-3 sm:grid-cols-3">
        <div class="card p-4">
          <p class="text-sm text-slate-600">Votre formule</p>
          <p class="font-bold">{{ data.plan ? `${data.plan.name} · ${money(data.plan.price)} / mois` : '—' }}</p>
        </div>
        <div class="card p-4">
          <p class="text-sm text-slate-600">Jibiat IA</p>
          <p class="font-bold">{{ statusLine }}
            <span v-if="sub.included" class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">comprise</span></p>
        </div>
        <div class="card p-4">
          <p class="text-sm text-slate-600">Vos courses ce mois-ci</p>
          <p class="font-bold">{{ data.usage.orders }}</p>
        </div>
      </div>

      <!-- Consommation (IA en service) -->
      <section v-if="sub.on" class="card space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">Votre consommation de {{ monthLabel }}</h2>
          <span class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800">
            {{ sub.tier_name }}<template v-if="sub.included"> · dans votre formule</template><template v-else-if="sub.status === 'trial'"> · essai, {{ sub.trial_days_left }} jour(s) restant(s)</template><template v-else> · abonnement {{ sub.period === 'yearly' ? 'à l\'année' : 'au mois' }}</template>
          </span>
        </div>
        <p v-if="sub.paused" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">⏸ L'IA est en pause jusqu'au mois prochain : la consommation a atteint la limite de votre niveau. Jibiat continue avec les règles habituelles. Une recharge la relance.</p>
        <p v-if="!sub.included && sub.stop_at" class="rounded-lg bg-slate-100 p-3 text-sm">L'IA s'arrêtera le {{ date(sub.stop_at) }}. <button type="button" class="font-semibold text-violet-700" :disabled="busy" @click="act('resume')">Continuer l'IA</button></p>
        <div v-if="data.usage.features.length" class="grid gap-2 sm:grid-cols-3">
          <div v-for="f in data.usage.features" :key="f.feature" class="rounded-lg bg-violet-50 p-3">
            <b class="block text-xl">{{ f.calls }}</b><span class="text-xs text-violet-800">{{ f.label }}</span>
          </div>
        </div>
        <p v-else class="text-sm text-slate-600">Rien encore ce mois-ci : l'IA travaille dès qu'un e-commerçant envoie un message WhatsApp.</p>
        <Meter label="Courses traitées ce mois-ci" :value="data.usage.orders" :max="data.usage.included_orders" />
        <Meter v-if="data.usage.voice_included" label="Notes vocales (minutes)" :value="data.usage.voice_minutes" :max="data.usage.voice_included" />
        <p class="text-xs text-slate-600">Au-delà du volume : {{ money(data.settings.overage_per_order) }} par course, sur votre facture Jibiat.<template v-if="data.usage.recharges"> Recharges ce mois-ci : {{ money(data.usage.recharges) }}.</template></p>
        <div class="flex flex-wrap gap-2">
          <template v-if="sub.included || sub.status === 'active'">
            <button v-for="amount in data.recharges" :key="amount" type="button" class="btn-secondary" :disabled="busy" @click="recharge(amount)">Recharger {{ money(amount) }}</button>
          </template>
          <button v-if="!sub.included && !sub.stop_at" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="stop">Arrêter l'IA</button>
        </div>
        <p v-if="!sub.included" class="text-xs text-slate-600">Votre abonnement à part continue comme avant. Jibiat IA est maintenant comprise dans les formules : avec une formule qui la comprend, plus rien à payer à part.</p>
      </section>

      <!-- Niveaux et formules qui les comprennent -->
      <section class="space-y-3">
        <div class="rounded-2xl bg-gradient-to-r from-violet-900 via-violet-600 to-purple-500 p-5 text-white">
          <h2 class="text-xl font-bold">✨ Laissez l'IA faire le travail répétitif</h2>
          <p class="mt-1 max-w-3xl text-sm opacity-90">Elle lit les messages, photos et fichiers Excel, répond à vos e-commerçants, repère les courses à risque et conseille votre dispatch. Rien ne change sans votre accord.</p>
        </div>
        <div v-if="!sub.included" class="card space-y-2 p-4">
          <h3 class="font-semibold">Jibiat IA est comprise dans ces formules</h3>
          <ul v-if="data.plans_with_ai.length" class="space-y-1 text-sm">
            <li v-for="p in data.plans_with_ai" :key="p.id">✓ <b>{{ p.name }}</b> ({{ money(p.price) }} / mois) : {{ p.tier_name }}</li>
          </ul>
          <p v-else class="text-sm text-slate-600">Bientôt dans les formules Jibiat.</p>
          <p class="text-sm">Pour changer de formule, écrivez à l'équipe Jibiat. <RouterLink to="/admin/abonnement" class="font-medium text-violet-700 underline">Voir mon abonnement</RouterLink></p>
        </div>
        <div class="grid gap-4 lg:grid-cols-3">
          <div v-for="t in data.tiers" :key="t.key" :class="['relative card p-4', t.key === sub.tier && sub.on ? 'border-2 border-violet-600' : '']">
            <span v-if="t.key === sub.tier && sub.on" class="absolute -top-3 right-4 rounded-full bg-violet-600 px-2 py-0.5 text-xs font-bold text-white">Votre niveau</span>
            <h3 class="text-lg font-bold">{{ t.name }}</h3>
            <p class="text-sm text-slate-600">jusqu'à {{ t.orders.toLocaleString('fr-FR') }} courses / mois · ≈ {{ Math.round(t.orders / 30) }} par jour</p>
            <ul class="my-3 space-y-1.5 text-sm">
              <li v-for="f in t.features" :key="f.key" class="flex gap-2">
                <span class="font-bold text-violet-600">✓</span>
                <span>{{ featureLabel(f, t) }}<span v-if="!f.available" class="ml-1 rounded bg-slate-100 px-1.5 text-xs text-slate-600">bientôt</span></span>
              </li>
            </ul>
            <p v-if="plansFor(t.key).length" class="text-xs text-slate-600">Dans : {{ plansFor(t.key).join(', ') }}</p>
          </div>
        </div>
        <p class="text-xs text-slate-600">Les fonctions marquées « bientôt » arrivent au fil des semaines, sans changer le prix.</p>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { date, money } from '../../utils/format'
import { confirmDialog } from '../../composables/useDialog'

/** Jibiat IA : niveau compris dans la formule, consommation du mois, recharges ; ancien abonnement à part (arrêt) */
const Meter = defineComponent({
  props: { label: String, value: Number, max: Number },
  setup(props) {
    return () => {
      const pct = props.max ? Math.min(100, Math.round(props.value * 100 / props.max)) : 0
      return h('div', [
        h('div', { class: 'mb-1 flex justify-between text-sm' }, [h('span', props.label), h('b', `${props.value.toLocaleString('fr-FR')} / ${props.max.toLocaleString('fr-FR')}`)]),
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
const monthLabel = computed(() => (data.value ? new Date(`${data.value.usage.month}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }) : ''))
const statusLine = computed(() => {
  const s = sub.value
  if (s.included) return s.tier_name
  if (s.on && s.status === 'trial') return `${s.tier_name} · essai`
  if (s.on) return s.tier_name
  if (s.status === 'expired') return 'Essai terminé'
  if (s.status === 'stopped') return 'Arrêtée'
  return 'Pas dans votre formule'
})

const plansFor = (tier) => (data.value?.plans_with_ai ?? []).filter((p) => p.tier === tier).map((p) => p.name)
function featureLabel(f, t) {
  if (f.key === 'dispatch') return t.dispatch_per_day >= 24 ? 'Assistant de dispatch toutes les 30 min' : `Assistant de dispatch : ${t.dispatch_per_day} analyses / jour`
  if (f.key === 'voice') return `Notes vocales : ${t.voice_minutes} min / mois`
  return f.label
}

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

const load = () => run(() => http.get('/company/ai'))
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

onMounted(load)
</script>
