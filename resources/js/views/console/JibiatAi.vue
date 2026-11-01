<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">Jibiat IA</h1>
      <p class="text-sm text-gray-500">L'abonnement IA des entreprises, à part de leur formule : ce qu'elles paient et ce que l'IA coûte vraiment ce mois-ci.</p>
    </div>
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <template v-if="data">
      <div class="grid gap-3 sm:grid-cols-4">
        <div class="card p-4"><p class="text-sm text-gray-500">Courses du mois</p><p class="text-xl font-bold">{{ data.totals.orders.toLocaleString('fr-FR') }}</p></div>
        <div class="card p-4"><p class="text-sm text-gray-500">Payé (estimation du mois)</p><p class="text-xl font-bold">{{ money(data.totals.paid) }}</p></div>
        <div class="card p-4"><p class="text-sm text-gray-500">Coût réel de l'IA</p><p class="text-xl font-bold">{{ money(data.totals.cost) }}</p></div>
        <div class="card p-4"><p class="text-sm text-gray-500">Marge</p><p :class="['text-xl font-bold', data.totals.margin >= 0 ? 'text-emerald-700' : 'text-red-700']">{{ signed(data.totals.margin) }}</p></div>
      </div>

      <section class="card overflow-x-auto p-4">
        <h2 class="mb-2 font-semibold">Entreprises</h2>
        <p v-if="!data.companies.length" class="text-sm text-gray-500">Aucune entreprise n'a encore essayé Jibiat IA.</p>
        <table v-else class="w-full text-sm">
          <thead><tr class="text-left text-xs uppercase text-gray-500"><th class="py-1.5 pr-2">Entreprise</th><th class="px-2">Niveau</th><th class="px-2 text-right">Courses</th><th class="px-2 text-right">Payé</th><th class="px-2 text-right">Coût IA</th><th class="px-2 text-right">Marge</th><th class="px-2"></th></tr></thead>
          <tbody>
            <tr v-for="c in data.companies" :key="c.id" class="border-t">
              <td class="py-2 pr-2 font-medium">{{ c.name }}</td>
              <td class="px-2">
                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', badge(c)]">{{ status(c) }}</span>
              </td>
              <td class="px-2 text-right tabular-nums">{{ c.orders.toLocaleString('fr-FR') }}<span v-if="c.included_orders" class="block text-xs text-gray-500">sur {{ c.included_orders.toLocaleString('fr-FR') }}</span></td>
              <td class="px-2 text-right tabular-nums">{{ money(c.paid) }}</td>
              <td class="px-2 text-right tabular-nums">{{ money(c.cost) }}<span v-if="c.cost_cap" class="block text-xs text-gray-500">plafond {{ money(c.cost_cap) }}</span></td>
              <td :class="['px-2 text-right tabular-nums font-semibold', c.margin >= 0 ? 'text-emerald-700' : 'text-red-700']">{{ signed(c.margin) }}</td>
              <td class="px-2 text-xs text-amber-800">{{ c.volume_percent >= 80 && c.next ? `${c.volume_percent} % du volume : proposer ${data.tiers[c.next].name}` : '' }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <form class="card space-y-4 p-4" @submit.prevent="save">
        <h2 class="font-semibold">Niveaux</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1.5 pr-2 font-medium">Niveau</th><th class="px-2 font-medium">Proposé</th><th class="px-2 font-medium">Prix / mois (F)</th><th class="px-2 font-medium">Courses / mois</th><th class="px-2 font-medium">Dispatch / jour</th><th class="px-2 font-medium">Notes vocales (min)</th><th class="px-2 font-medium">Plafond de coût (F)</th></tr></thead>
            <tbody>
              <tr v-for="(t, key) in tiers" :key="key" class="border-t">
                <td class="py-2 pr-2 font-semibold">{{ t.name }}</td>
                <td class="px-2"><input v-model="t.active" type="checkbox" class="h-4 w-4" :disabled="!owner" :aria-label="`${t.name} proposé`"></td>
                <td class="px-2"><input v-model.number="t.price" type="number" min="0" class="input w-28" :disabled="!owner" :aria-label="`Prix de ${t.name}`"></td>
                <td class="px-2"><input v-model.number="t.orders" type="number" min="1" class="input w-28" :disabled="!owner" :aria-label="`Courses de ${t.name}`"></td>
                <td class="px-2"><input v-model.number="t.dispatch_per_day" type="number" min="0" max="96" class="input w-20" :disabled="!owner" :aria-label="`Dispatch de ${t.name}`"></td>
                <td class="px-2"><input v-model.number="t.voice_minutes" type="number" min="0" class="input w-24" :disabled="!owner" :aria-label="`Notes vocales de ${t.name}`"></td>
                <td class="px-2"><input v-model.number="t.cost_cap" type="number" min="0" class="input w-28" :disabled="!owner" :aria-label="`Plafond de ${t.name}`"></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="text-xs text-gray-500">Plafond de coût atteint : l'IA se met en pause pour l'entreprise jusqu'au mois suivant (ou une recharge), et Jibiat continue avec les règles habituelles.</p>
        <div class="grid gap-3 sm:grid-cols-4">
          <label class="block text-sm">Course au-delà du volume (F)<input v-model.number="settings.overage_per_order" type="number" min="0" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Jours d'essai gratuit<input v-model.number="settings.trial_days" type="number" min="0" max="90" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Mois payés à l'année (sur 12)<input v-model.number="settings.yearly_months" type="number" min="1" max="12" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">1 dollar = (F)<input v-model.number="settings.usd_rate" type="number" min="100" max="2000" class="input mt-1 w-full" :disabled="!owner"><span class="text-xs text-gray-500">Pour le coût réel de l'IA</span></label>
        </div>
        <button v-if="owner" type="submit" class="btn-primary" :disabled="saving">Enregistrer</button>
      </form>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { consoleCan } from '../../roles'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

/** Console › Jibiat IA : entreprises (payé, coût réel, marge), niveaux et réglages */
const auth = useAuthStore()
const toasts = useToastStore()
const owner = computed(() => consoleCan(auth.role, 'owner'))
const data = ref(null)
const tiers = ref({})
const settings = ref({})
const error = ref('')
const saving = ref(false)

const signed = (n) => `${n >= 0 ? '+' : '−'}${money(Math.abs(n))}`
function status(c) {
  const name = c.tier_name || '—'
  if (!c.status) return 'Sans abonnement'
  if (c.paused) return `${name} · en pause`
  if (c.status === 'trial' && c.on) return `${name} · essai`
  if (c.status === 'active' && c.stop_at) return `${name} · s'arrête`
  if (c.status === 'active') return `${name}${c.period === 'yearly' ? ' · à l\'année' : ''}`
  return c.status === 'expired' ? 'Essai terminé' : 'Arrêtée'
}
const badge = (c) => (c.paused || c.volume_percent >= 80 ? 'bg-amber-100 text-amber-800' : c.on ? 'bg-violet-100 text-violet-800' : 'bg-slate-100 text-slate-600')

function apply(d) {
  data.value = d
  tiers.value = JSON.parse(JSON.stringify(d.tiers))
  settings.value = { ...d.settings }
}

async function load() {
  try {
    apply((await http.get('/console/ai')).data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save() {
  saving.value = true
  try {
    apply((await http.put('/console/ai', { tiers: tiers.value, settings: settings.value })).data.data)
    toasts.success('Réglages de Jibiat IA enregistrés.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
