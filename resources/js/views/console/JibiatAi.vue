<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">Jibiat IA</h1>
      <p class="text-sm text-slate-600">L'IA vient en points avec chaque formule (points et plafond réglés dans Console › Formules). Ici : l'interrupteur, le prix de chaque action en points, et ce que l'IA coûte vraiment ce mois-ci.</p>
    </div>
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <template v-if="data">
      <form class="card space-y-4 p-4" @submit.prevent="save">
        <label class="flex items-center gap-2 font-semibold">
          <input v-model="settings.enabled" type="checkbox" class="h-5 w-5" :disabled="!owner">
          Jibiat IA allumée pour toutes les entreprises
        </label>
        <p v-if="!settings.enabled" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Éteinte : aucune entreprise n'utilise l'IA ; les commandes WhatsApp sont lues avec les règles habituelles.</p>
        <div class="grid gap-3 sm:grid-cols-3">
          <label class="block text-sm">Commande WhatsApp écrite (points)<input v-model.number="settings.points_message" type="number" min="0" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Photo de commande (points)<input v-model.number="settings.points_photo" type="number" min="0" class="input mt-1 w-full" :disabled="!owner"><span class="text-xs text-slate-600">Bientôt en service</span></label>
          <label class="block text-sm">Pack de points vendu en plus (points)<input v-model.number="settings.pack_points" type="number" min="0" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Prix du pack (F)<input v-model.number="settings.pack_price" type="number" min="0" class="input mt-1 w-full" :disabled="!owner"><span class="text-xs text-slate-600">Payé d'avance par Wave ; 0 : pas de pack</span></label>
          <label class="block text-sm">1 dollar = (F)<input v-model.number="settings.usd_rate" type="number" min="100" max="2000" class="input mt-1 w-full" :disabled="!owner"><span class="text-xs text-slate-600">Pour le coût réel de l'IA</span></label>
        </div>
        <p class="text-xs text-slate-600">Modèle utilisé : <b>{{ data.model }}</b> (réglage ANTHROPIC_MODEL du serveur).</p>
        <button v-if="owner" type="submit" class="btn-primary" :disabled="saving">Enregistrer</button>
      </form>

      <div class="grid gap-3 sm:grid-cols-2">
        <div class="card p-4"><p class="text-sm text-slate-600">Points utilisés ce mois-ci</p><p class="text-xl font-bold">{{ data.totals.used.toLocaleString('fr-FR') }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-600">Coût réel de l'IA ce mois-ci</p><p class="text-xl font-bold">{{ money(data.totals.cost) }}</p></div>
      </div>

      <section class="card overflow-x-auto p-4">
        <h2 class="mb-2 font-semibold">Entreprises</h2>
        <p v-if="!data.companies.length" class="text-sm text-slate-600">Aucune entreprise n'a encore de points d'IA.</p>
        <table v-else class="w-full text-sm">
          <thead><tr class="text-left text-xs uppercase text-slate-600"><th class="py-1.5 pr-2">Entreprise</th><th class="px-2">Formule</th><th class="px-2 text-right">Points</th><th class="px-2 text-right">Coût réel</th><th class="px-2">État</th><th class="px-2"></th></tr></thead>
          <tbody>
            <tr v-for="c in data.companies" :key="c.id" class="border-t">
              <td class="py-2 pr-2 font-medium">{{ c.name }}</td>
              <td class="px-2">{{ c.plan || '—' }}<span v-if="c.legacy" class="block text-xs text-slate-600">ancien abonnement {{ c.legacy }}</span></td>
              <td class="px-2 text-right tabular-nums">{{ c.used.toLocaleString('fr-FR') }} / {{ c.points.toLocaleString('fr-FR') }}<span v-if="c.percent !== null" class="block text-xs text-slate-600">{{ c.percent }} %</span></td>
              <td class="px-2 text-right tabular-nums">{{ money(c.cost) }}<span class="block text-xs text-slate-600">plafond {{ c.cost_cap !== null ? money(c.cost_cap) : 'aucun' }}</span></td>
              <td class="px-2"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', badge(c)]">{{ status(c) }}</span></td>
              <td class="px-2 text-right">
                <button v-if="owner" type="button" :class="['text-xs font-semibold', c.disabled ? 'text-emerald-700' : 'text-red-700']" @click="toggle(c)">{{ c.disabled ? 'Rallumer' : 'Couper' }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { confirmDialog } from '../../composables/useDialog'
import { consoleCan } from '../../roles'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

/** Console › Jibiat IA : interrupteur, prix des actions en points, entreprises (points, coût réel, plafond, coupure) */
const auth = useAuthStore()
const toasts = useToastStore()
const owner = computed(() => consoleCan(auth.role, 'owner'))
const data = ref(null)
const settings = ref({})
const error = ref('')
const saving = ref(false)

function status(c) {
  if (c.disabled) return 'Coupée'
  if (!data.value.settings.enabled) return 'Éteinte'
  if (c.paused) return 'Pause (plafond)'
  if (c.points && c.used >= c.points) return 'Points épuisés'
  return c.on ? 'En service' : 'Sans points'
}
const badge = (c) => (c.disabled || c.paused ? 'bg-red-100 text-red-800' : c.percent >= 80 ? 'bg-amber-100 text-amber-800' : c.on ? 'bg-violet-100 text-violet-800' : 'bg-slate-100 text-slate-600')

function apply(d) {
  data.value = d
  settings.value = { ...d.settings, enabled: !!d.settings.enabled }
}

async function load() {
  try {
    apply((await http.get('/console/ai')).data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save() {
  if (!settings.value.enabled && data.value.settings.enabled && !await confirmDialog('Éteindre Jibiat IA pour toutes les entreprises ?')) return
  saving.value = true
  try {
    apply((await http.put('/console/ai', { settings: settings.value })).data.data)
    toasts.success('Réglages de Jibiat IA enregistrés.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function toggle(c) {
  if (!c.disabled && !await confirmDialog(`Couper Jibiat IA pour ${c.name} ?`)) return
  try {
    apply((await http.put(`/console/ai/companies/${c.id}`, { disabled: !c.disabled })).data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>
