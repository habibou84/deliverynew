<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/reglages/reprise/controles" class="text-sm font-medium text-emerald-700">‹ Contrôles et ménage</RouterLink>
      <h1 class="text-xl font-bold">Argent chez les livreurs : point de départ</h1>
      <p class="text-sm text-gray-600">
        Dans l'ancienne application, les encaissements des livreurs n'étaient pas toujours mis à jour. Choisissez une
        date : avant elle, l'argent est considéré comme remis, sauf ce que chaque livreur a vraiment en main.
      </p>
    </div>

    <section class="card p-4 space-y-3">
      <h2 class="font-semibold">1. Date du point de départ</h2>
      <div class="flex flex-wrap gap-2">
        <button v-if="state?.switch_date" type="button" :class="choice(cutoff === state.switch_date)" @click="setCutoff(state.switch_date)">
          Date de bascule : {{ date(state.switch_date) }}<span class="block text-xs font-normal">Conseillé</span>
        </button>
        <button type="button" :class="choice(cutoff === todayIso)" @click="setCutoff(todayIso)">
          Hier soir<span class="block text-xs font-normal">Tout ce qui a été livré avant aujourd'hui</span>
        </button>
        <label :class="choice(cutoff !== todayIso && cutoff !== state?.switch_date)">
          Autre date
          <input v-model="cutoff" type="date" class="input mt-1 block" :max="todayIso" @change="load">
        </label>
      </div>
      <p class="text-sm text-gray-600">Les courses livrées à partir de cette date restent chez le livreur tant qu'il ne les a pas versées à la caisse.</p>
    </section>

    <section v-if="state" class="card p-4 space-y-3">
      <h2 class="font-semibold">2. Livreur par livreur</h2>
      <p class="text-sm text-gray-600">
        Comptez l'argent avec chaque livreur. S'il a tout remis, laissez « Tout remis ». Sinon, tapez le montant qu'il a
        vraiment : Jibiat garde les courses les plus récentes qui font ce montant.
      </p>
      <p v-if="!state.couriers.length" class="text-sm text-gray-600">Aucun argent de l'ancienne application n'est compté chez les livreurs.</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs text-gray-500">
            <tr><th class="px-3 py-2">Livreur</th><th class="px-3">Compté aujourd'hui</th><th class="px-3">Depuis la date</th><th class="px-3">Ce qu'il a vraiment</th><th class="px-3 text-right">Après</th></tr>
          </thead>
          <tbody>
            <tr v-for="c in state.couriers" :key="c.courier_id ?? 0" class="border-t">
              <td class="px-3 py-2 font-medium">{{ c.name }}</td>
              <td class="px-3 whitespace-nowrap">{{ money(c.total) }}<span class="block text-xs text-gray-500">{{ c.count }} course(s)<template v-if="c.oldest">, depuis le {{ date(c.oldest) }}</template></span></td>
              <td class="px-3 whitespace-nowrap">{{ money(c.since_total) }}<span class="block text-xs text-gray-500">{{ c.since_count }} course(s)</span></td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap items-center gap-2">
                  <button type="button" :class="pill(!hasAmount(c))" @click="clearAmount(c)">Tout remis</button>
                  <label :class="['flex items-center gap-1', pill(hasAmount(c))]">
                    <span class="text-xs">A en main</span>
                    <input :value="amounts[key(c)] ?? ''" type="number" min="0" step="5" inputmode="numeric" class="w-28 border-0 bg-transparent p-0 text-sm font-semibold focus:ring-0"
                      :aria-label="`Montant que ${c.name} a vraiment`" placeholder="Montant" @input="setAmount(c, $event.target.value)">
                    <span>F</span>
                  </label>
                </div>
              </td>
              <td class="px-3 text-right font-semibold whitespace-nowrap">{{ money(after(c)) }}</td>
            </tr>
          </tbody>
          <tfoot class="border-t bg-slate-50 font-semibold">
            <tr><td class="px-3 py-2">Total</td><td class="px-3">{{ money(state.totals.total) }}</td><td class="px-3">{{ money(state.totals.since_total) }}</td><td></td><td class="px-3 text-right text-emerald-700">{{ money(totalAfter) }}</td></tr>
          </tfoot>
        </table>
      </div>
      <p class="text-xs text-gray-500">« Après » est une estimation : avec un montant, Jibiat garde les courses les plus récentes tant qu'elles tiennent dans ce montant.</p>
    </section>

    <section v-if="state?.couriers.length" class="card p-4 flex flex-wrap items-end gap-3">
      <div class="flex-1 min-w-[16rem]">
        <h2 class="font-semibold">3. {{ money(state.totals.total - totalAfter) }} seront marqués « remis dans l'ancienne application »</h2>
        <p class="text-sm text-gray-600">Rien ne bouge dans la caisse : pas de versement, pas de message aux livreurs ni aux e-commerçants. Ce qui est dû aux e-commerçants ne change pas. Annulable pendant 7 jours.</p>
      </div>
      <label class="text-sm font-medium">Tapez VALIDER pour confirmer
        <input v-model="confirm" type="text" class="input mt-1 block" autocomplete="off">
      </label>
      <button type="button" class="btn-primary" :disabled="busy || confirm.trim().toUpperCase() !== 'VALIDER'" @click="apply">Faire le point de départ</button>
    </section>

    <div v-if="last" class="flex flex-wrap items-center gap-3 rounded-lg bg-slate-50 p-3 text-sm">
      <p class="flex-1">
        <template v-if="last.status === 'undone'">Point de départ du {{ date(last.cutoff) }} annulé le {{ dateTime(last.undone_at) }}.</template>
        <template v-else>✓ Point de départ du {{ date(last.cutoff) }} fait le {{ dateTime(last.created_at) }}<template v-if="last.by"> par {{ last.by }}</template> : {{ money(last.settled_amount) }} marqués remis ({{ last.settled_count }} course(s)).</template>
      </p>
      <button v-if="last.can_undo" type="button" class="btn-secondary" :disabled="busy" @click="undo">Annuler (jusqu'au {{ date(last.undo_until) }})</button>
    </div>

    <p class="text-sm text-gray-600">Une course réglée ici ne revient plus chez le livreur, même quand l'ancienne application est recopiée. Elle garde la note « Argent remis dans l'ancienne application (point de départ) ».</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { date, dateTime, money } from '../../../utils/format'

/** Réglages › Reprise › Argent chez les livreurs : point de départ à une date, livreur par livreur, annulable 7 jours */
const toasts = useToastStore()
const todayIso = new Date().toLocaleDateString('en-CA')
const state = ref(null)
const cutoff = ref(null)
const amounts = ref({})
const confirm = ref('')
const busy = ref(false)

const last = computed(() => state.value?.last ?? null)
const key = (c) => c.courier_id ?? 0
const hasAmount = (c) => amounts.value[key(c)] !== undefined
const choice = (on) => ['rounded-lg border px-3 py-2 text-left text-sm font-semibold', on ? 'border-emerald-600 bg-emerald-50 text-emerald-900' : 'border-slate-300 bg-white']
const pill = (on) => ['rounded-lg border px-3 py-2 text-sm', on ? 'border-emerald-600 bg-emerald-50 font-semibold text-emerald-900' : 'border-slate-300 bg-white']

function setAmount(c, value) {
  const n = parseInt(value, 10)
  if (Number.isNaN(n)) clearAmount(c)
  else amounts.value = { ...amounts.value, [key(c)]: Math.max(0, n) }
}

function clearAmount(c) {
  const { [key(c)]: _, ...rest } = amounts.value
  amounts.value = rest
}

const after = (c) => (hasAmount(c) ? Math.min(amounts.value[key(c)], c.total) : c.since_total)
const totalAfter = computed(() => (state.value?.couriers ?? []).reduce((s, c) => s + after(c), 0))

function setCutoff(value) {
  cutoff.value = value
  load()
}

async function load() {
  busy.value = true
  try {
    const { data } = await http.get('/company/legacy-cash', { params: cutoff.value ? { cutoff: cutoff.value } : {} })
    state.value = data.data
    cutoff.value = data.data.cutoff
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function apply() {
  busy.value = true
  try {
    const { data } = await http.post('/company/legacy-cash', { cutoff: cutoff.value, amounts: amounts.value, confirm: 'VALIDER' })
    toasts.success(`Point de départ fait : ${money(data.data.settled_amount)} marqués remis.`)
    confirm.value = ''
    amounts.value = {}
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
  load()
}

async function undo() {
  if (!window.confirm('Annuler ce point de départ ? L\'argent revient chez les livreurs comme avant.')) return
  busy.value = true
  try {
    const { data } = await http.post(`/company/legacy-cash/${last.value.id}/undo`)
    toasts.success(`${data.data.restored} encaissement(s) remis chez les livreurs.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
  load()
}

onMounted(load)
</script>
