<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/reglages/reprise/controles" class="text-sm font-medium text-emerald-700">‹ Contrôles et ménage</RouterLink>
      <h1 class="text-xl font-bold">Tarifs proposés</h1>
      <p class="text-sm text-gray-600">
        Les prix de l'ancienne application (un prix par destination), placés sur les lieux standard. Rien ne s'applique
        tant que vous n'avez pas validé ; les courses déjà créées gardent leur prix. Valider crée un groupe « depuis
        partout » dans Tarifs, modifiable ensuite.
      </p>
    </div>

    <p v-if="!state" class="text-gray-500">Chargement…</p>
    <template v-else>
      <div class="card flex flex-wrap items-center gap-3 p-4">
        <label class="flex items-center gap-2 text-sm font-medium">Grille
          <select v-model="gridId" class="input w-auto" @change="load">
            <option v-for="g in state.grids" :key="g.id" :value="g.id">{{ g.name }}{{ g.is_default ? ' (par défaut)' : '' }}</option>
          </select>
        </label>
        <span class="flex-1" />
        <button v-if="state.groups.length > 1" type="button" class="btn-primary" :disabled="busy" @click="applyAll">Tout valider ({{ state.groups.length }} groupes)</button>
      </div>

      <p v-if="!state.groups.length && !state.unlinked.length" class="card p-4 text-sm text-gray-600">
        Aucun prix de l'ancienne application à proposer. Les prix arrivent avec l'envoi des zones depuis l'ancienne
        application (page « Migration vers Jibiat », Envoyer) : relancer l'envoi ne crée pas de doublon.
      </p>

      <div v-if="state.groups.length" class="card divide-y">
        <div v-for="g in state.groups" :key="g.price" class="flex flex-wrap items-center gap-3 p-3">
          <div class="w-28 font-semibold">À {{ money(g.price) }}</div>
          <div class="min-w-[14rem] flex-1 text-sm text-gray-700">
            <span v-for="(z, i) in g.zones" :key="z.id">{{ i ? ' · ' : '' }}{{ z.old_name !== z.label ? `${z.old_name} → ` : '' }}{{ z.label }}<span v-if="z.in_group" class="text-xs text-gray-500"> (déjà dans un groupe)</span></span>
          </div>
          <label class="flex items-center gap-1 rounded border px-2 text-sm">
            <input v-model.number="prices[g.price]" type="number" min="0" inputmode="numeric" :aria-label="`Prix Jibiat des lieux à ${g.price} F`" class="w-20 border-0 py-2 text-right font-bold">F
          </label>
          <div class="flex gap-2">
            <button type="button" class="btn-primary" :disabled="busy" @click="apply(g)">{{ prices[g.price] !== g.price ? `Valider à ${money(prices[g.price] || 0)}` : 'Valider' }}</button>
            <button type="button" class="btn-secondary" :disabled="busy" @click="ignore(g.zones)">Ignorer</button>
          </div>
        </div>
      </div>

      <section v-if="state.unlinked.length" class="card space-y-2 bg-amber-50 p-4">
        <h2 class="font-semibold text-amber-900">À rattacher d'abord</h2>
        <p class="text-sm text-amber-900">
          {{ state.unlinked.map((z) => `${z.label} (${money(z.price)})`).join(', ') }} : ces zones ne sont pas encore
          rattachées à un lieu standard.
        </p>
        <RouterLink to="/admin/reglages/reprise/zones" class="btn-secondary inline-flex">Rattacher les zones ›</RouterLink>
      </section>
    </template>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { money } from '../../../utils/format'

/**
 * Reprise › Tarifs proposés : prix de l'ancienne application, regroupés par montant, validés en groupes de prix.
 */
const toasts = useToastStore()
const state = ref(null)
const gridId = ref(null)
const busy = ref(false)
const prices = reactive({})

async function load() {
  try {
    state.value = (await http.get('/company/zone-links/prices', { params: gridId.value ? { grid_id: gridId.value } : {} })).data.data
    gridId.value = state.value.grid_id
    for (const g of state.value.groups) prices[g.price] ??= g.price
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function post(url, body) {
  busy.value = true
  try {
    await http.post(url, body, { params: { grid_id: gridId.value } })
    return true
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return false
  } finally {
    busy.value = false
  }
}

async function apply(g) {
  if (await post('/company/zone-links/prices', { zone_ids: g.zones.map((z) => z.id), price: prices[g.price] ?? g.price })) {
    toasts.success('Tarif validé.')
    await load()
  }
}

async function applyAll() {
  if (!window.confirm(`Valider les ${state.value.groups.length} groupes de prix ?`)) return
  for (const g of [...state.value.groups]) {
    if (!(await post('/company/zone-links/prices', { zone_ids: g.zones.map((z) => z.id), price: prices[g.price] ?? g.price }))) break
  }
  await load()
}

async function ignore(zones) {
  if (await post('/company/zone-links/prices/ignore', { zone_ids: zones.map((z) => z.id) })) await load()
}

onMounted(load)
</script>
