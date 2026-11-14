<template>
  <!-- Étape « Comptes et zones » : copie des comptes chaque minute et commandes en attente d'un compte -->
  <section v-if="state" class="card p-4 space-y-3">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-[16rem] flex-1">
        <h2 class="font-semibold">Copie des comptes chaque minute</h2>
        <p class="text-sm text-slate-600">
          Les nouveaux e-commerçants et livreurs de l'ancienne application arrivent seuls dans Jibiat, avec leur numéro et leur mot de passe.
          Jusqu'à la bascule, une fiche modifiée là-bas est aussi mise à jour ici ; après, c'est Jibiat qui fait foi.
        </p>
      </div>
      <button v-if="state.copy" type="button" class="btn-secondary" :disabled="busy" @click="syncNow">{{ busy ? 'Copie…' : 'Copier les comptes maintenant' }}</button>
    </div>

    <div v-if="state.copy" class="grid gap-2 text-sm sm:grid-cols-3">
      <div class="rounded-lg bg-emerald-50 p-3 text-emerald-900">✅ Copie active <span class="block text-xs">Module version {{ state.module_version }}</span></div>
      <div class="rounded-lg bg-slate-50 p-3">Dernière copie<span class="block font-semibold">{{ state.last_at ? dateTime(state.last_at) : 'pas encore' }}</span></div>
      <div class="rounded-lg bg-slate-50 p-3">Aujourd'hui
        <span class="block font-semibold">{{ today.merchants }} e-commerçant(s), {{ today.couriers }} livreur(s) repris · {{ today.updated }} fiche(s) mise(s) à jour</span>
      </div>
    </div>
    <p v-else class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      La copie des comptes demande le module de lecture des commandes version 4 ou plus
      (version actuelle : {{ state.module_version || 'inconnue' }}). Téléchargez-le à nouveau dans
      <RouterLink to="/admin/reglages/reprise/brancher" class="font-medium underline">Brancher l'ancienne application</RouterLink>
      et remplacez l'ancien fichier. En attendant, un e-commerçant inconnu est créé depuis sa commande et reçoit son compte au prochain envoi.
    </p>

    <div class="space-y-2 border-t pt-3">
      <h3 class="font-semibold">Commandes en attente d'un compte <span class="text-sm font-normal text-slate-600">({{ state.pending.total }})</span></h3>
      <p v-if="!state.pending.total" class="text-sm text-slate-600">Aucune : toutes les commandes ont trouvé leur e-commerçant.</p>
      <template v-else>
        <p class="text-sm text-slate-600">
          Leur e-commerçant n'a pas pu être repris (souvent un numéro absent ou déjà pris). Corrigez sa fiche dans l'ancienne application :
          la commande est reprise seule à la copie suivante.
        </p>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-xs uppercase text-slate-600"><th class="py-1.5 pr-2">Commande</th><th class="px-2">E-commerçant</th><th class="px-2">Raison</th><th class="px-2">Essais</th></tr></thead>
            <tbody>
              <tr v-for="p in state.pending.items" :key="p.legacy_id" class="border-t">
                <td class="py-2 pr-2 font-medium">n° {{ p.legacy_id }}</td>
                <td class="px-2">{{ p.client_name || '—' }}<span v-if="p.client_legacy_id" class="block text-xs text-slate-600">client n° {{ p.client_legacy_id }}</span></td>
                <td class="px-2 text-slate-700">{{ p.reason }}</td>
                <td class="px-2 tabular-nums">{{ p.attempts }}<span v-if="p.last_tried_at" class="block text-xs text-slate-600">{{ dateTime(p.last_tried_at) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="state.pending.total > state.pending.items.length" class="text-xs text-slate-600">Les {{ state.pending.items.length }} plus récentes sont affichées.</p>
      </template>
    </div>
  </section>
  <p v-else-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'

/** Reprise › Comptes et zones : état de la copie des comptes et commandes en attente d'un compte */
const toasts = useToastStore()
const state = ref(null)
const error = ref('')
const busy = ref(false)
const today = computed(() => state.value?.today || { merchants: 0, couriers: 0, updated: 0 })

async function syncNow() {
  busy.value = true
  try {
    const data = (await http.post('/company/legacy-orders/accounts/sync')).data.data
    state.value = data
    const r = data.report || { merchants: 0, couriers: 0, updated: 0 }
    toasts.success(`Comptes copiés : ${r.merchants} e-commerçant(s), ${r.couriers} livreur(s), ${r.updated} mise(s) à jour${data.retried ? `, ${data.retried} commande(s) relancée(s)` : ''}.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    state.value = (await http.get('/company/legacy-orders/accounts')).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>
