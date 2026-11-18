<template>
  <!-- Solder l'ancien : sommes dues aux e-commerçants pour les courses reprises, réglées hors Jibiat -->
  <section v-if="state" class="card p-4 space-y-4">
    <div>
      <h2 class="font-semibold">Solder l'ancien : sommes dues aux e-commerçants</h2>
      <p class="text-sm text-slate-600">
        Pour les courses de l'ancienne application livrées avant la date choisie, ce qui reste dû aux e-commerçants est marqué
        <strong>réglé hors Jibiat</strong> (déjà payé en dehors de l'application). Elles disparaissent de « À payer par e-commerçant ».
        Aucun message n'est envoyé. Les courses créées dans Jibiat ne bougent pas. Annulable pendant 7 jours.
      </p>
    </div>

    <div class="flex flex-wrap items-end gap-3">
      <label class="text-sm font-medium" for="settle-before">Courses livrées avant le
        <input id="settle-before" v-model="before" type="date" class="input mt-1 block" :max="today" @change="load">
      </label>
      <button type="button" class="btn-secondary" :disabled="busy" @click="load">Simuler</button>
    </div>

    <p class="text-xs text-slate-600">Simulation : rien n'a encore été changé.</p>
    <div class="grid gap-3 sm:grid-cols-3">
      <div class="rounded-lg border p-3"><p class="text-sm text-slate-600">E-commerçants</p><p class="text-xl font-bold tabular-nums">{{ totals.merchants }}</p></div>
      <div class="rounded-lg border p-3"><p class="text-sm text-slate-600">Courses</p><p class="text-xl font-bold tabular-nums">{{ totals.orders }}</p></div>
      <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3"><p class="text-sm text-emerald-900">Montant soldé</p><p class="text-xl font-bold tabular-nums text-emerald-800">{{ money(totals.amount) }}</p></div>
    </div>

    <details v-if="state.simulation.merchants.length" class="rounded-lg border">
      <summary class="cursor-pointer p-3 text-sm font-medium">Voir le détail par e-commerçant{{ totals.merchants > state.simulation.merchants.length ? ` (les ${state.simulation.merchants.length} plus gros)` : '' }}</summary>
      <ul class="divide-y border-t text-sm">
        <li v-for="m in state.simulation.merchants" :key="m.merchant_id" class="flex items-center gap-3 px-3 py-2">
          <span class="min-w-0 flex-1 truncate">{{ m.name }}</span>
          <span class="text-slate-600">{{ m.orders }} course{{ m.orders > 1 ? 's' : '' }}</span>
          <span class="w-28 text-right font-semibold tabular-nums">{{ money(m.amount) }}</span>
        </li>
      </ul>
    </details>

    <label v-if="totals.cash_count" class="flex items-start gap-2 rounded-lg border p-3 text-sm">
      <input v-model="clearCash" type="checkbox" class="mt-1">
      <span>
        Compter aussi comme remis à la caisse l'argent de ces courses encore noté chez les livreurs
        (<strong>{{ totals.cash_count }}</strong> encaissement{{ totals.cash_count > 1 ? 's' : '' }}, {{ money(totals.cash_amount) }})
        <span class="block text-xs text-slate-600">Recommandé : sinon cet argent reste réclamé aux livreurs dans la caisse.</span>
      </span>
    </label>

    <div class="flex flex-wrap items-end gap-3 rounded-lg border p-3">
      <div class="min-w-[16rem] flex-1">
        <p class="font-semibold">{{ totals.merchants }} e-commerçant{{ totals.merchants > 1 ? 's' : '' }} soldé{{ totals.merchants > 1 ? 's' : '' }}, {{ money(totals.amount) }}</p>
        <p class="text-sm text-slate-600">Un reversement « réglé hors Jibiat » par e-commerçant garde la trace dans son historique.</p>
      </div>
      <label class="text-sm font-medium" for="settle-confirm">Tapez SOLDER pour confirmer
        <input id="settle-confirm" v-model="confirm" type="text" class="input mt-1 block" autocomplete="off">
      </label>
      <button type="button" class="btn-primary" :disabled="busy || !canRun || confirm.trim().toUpperCase() !== 'SOLDER'" @click="run">Solder l'ancien</button>
    </div>

    <div v-if="state.last" class="flex flex-wrap items-center gap-3 rounded-lg bg-slate-50 p-3 text-sm">
      <p class="flex-1">
        <template v-if="state.last.status === 'undone'">Solde du {{ dateTime(state.last.created_at) }} annulé le {{ dateTime(state.last.undone_at) }}.</template>
        <template v-else>
          ✓ {{ state.last.merchants }} e-commerçant(s) soldé(s), {{ money(state.last.amount) }}, le {{ dateTime(state.last.created_at) }}<template v-if="state.last.by"> par {{ state.last.by }}</template><template v-if="state.last.cash_count"> ; {{ state.last.cash_count }} encaissement(s) compté(s) remis</template>.
        </template>
      </p>
      <button v-if="state.last.can_undo" type="button" class="btn-secondary" :disabled="busy" @click="undo">Annuler ce solde (jusqu'au {{ date(state.last.undo_until) }})</button>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { confirmDialog } from '../composables/useDialog'
import { useToastStore } from '../stores/toasts'
import { date, dateTime, money } from '../utils/format'

const toasts = useToastStore()
const today = new Date().toLocaleDateString('sv-SE')
const before = ref(today)
const state = ref(null)
const clearCash = ref(true)
const confirm = ref('')
const busy = ref(false)

const totals = computed(() => state.value?.simulation.totals ?? {})
const canRun = computed(() => totals.value.merchants > 0 || (clearCash.value && totals.value.cash_count > 0))

async function load() {
  busy.value = true
  try {
    state.value = (await http.get('/company/legacy-settlement', { params: { before: before.value || undefined } })).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function run() {
  busy.value = true
  try {
    const { data } = await http.post('/company/legacy-settlement', { before: before.value, clear_cash: clearCash.value, confirm: confirm.value })
    toasts.success(`${data.data.merchants} e-commerçant(s) soldé(s) : ${money(data.data.amount)}.`)
    confirm.value = ''
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function undo() {
  if (!await confirmDialog('Annuler ce solde ? Les sommes redeviennent dues aux e-commerçants et l\'argent revient chez les livreurs.')) return
  busy.value = true
  try {
    await http.post(`/company/legacy-settlement/${state.value.last.id}/undo`)
    toasts.success('Solde annulé.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
