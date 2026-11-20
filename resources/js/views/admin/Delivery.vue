<template>
  <div class="max-w-5xl space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Zones et tarifs</h1>
        <p class="text-sm text-slate-600">Où vous livrez et à quel prix, au même endroit.</p>
      </div>
      <!-- Grille dont on règle les prix : la grille normale, ou une grille négociée -->
      <div v-if="grids.length > 1 && !['grilles', 'anciennes'].includes(tab)" class="flex items-center gap-2">
        <label for="grid" class="text-sm font-semibold text-slate-700">Prix de la grille</label>
        <select id="grid" v-model="gridId" class="input w-auto" @change="chooseGrid(gridId)">
          <option v-for="g in grids" :key="g.id" :value="g.id">{{ g.name }}{{ g.is_default ? ' (normale)' : '' }}</option>
        </select>
      </div>
    </div>

    <p v-if="grid && !grid.is_default && !['grilles', 'anciennes'].includes(tab)" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">
      Vous réglez la grille négociée « {{ grid.name }} » ({{ grid.merchants_count ?? 0 }} e-commerçant{{ (grid.merchants_count ?? 0) > 1 ? 's' : '' }}).
      Un lieu sans prix ici reprend celui de la grille normale.
    </p>

    <!-- Onglets -->
    <div role="tablist" aria-label="Zones et tarifs" class="flex flex-wrap gap-1 border-b border-slate-300">
      <button
        v-for="t in tabs" :key="t.value" type="button" role="tab" :aria-selected="tab === t.value"
        :class="['-mb-px min-h-11 border-b-2 px-4 text-sm font-semibold', tab === t.value ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-600 hover:text-slate-900']"
        @click="setTab(t.value)"
      >
        {{ t.label }}
      </button>
    </div>

    <PriceCheck v-if="gridId && !['grilles', 'anciennes'].includes(tab)" :grid-id="gridId" />
    <LegacyAddresses v-if="tab === 'anciennes'" />

    <template v-if="gridId">
      <ZonePrices v-if="tab === 'zones'" :key="`z${gridId}`" :grid-id="gridId" :is-default="!!grid?.is_default" />
      <template v-else-if="tab === 'supplements'">
        <SurchargesEditor :key="`s${gridId}`" :grid-id="gridId" />
        <ReturnFeeSettings />
      </template>
    </template>
    <PricingGridsManager v-if="tab === 'grilles'" :grids="grids" @changed="loadGrids" @edit="(id) => { chooseGrid(id); setTab('zones') }" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '../../bootstrap/axios'
import PriceCheck from '../../components/delivery/PriceCheck.vue'
import PricingGridsManager from '../../components/delivery/PricingGridsManager.vue'
import SurchargesEditor from '../../components/delivery/SurchargesEditor.vue'
import ZonePrices from '../../components/delivery/ZonePrices.vue'
import ReturnFeeSettings from '../../components/settings/ReturnFeeSettings.vue'
import LegacyAddresses from '../../components/delivery/LegacyAddresses.vue'
import { useAuthStore } from '../../stores/auth'

/**
 * Zones et tarifs : un tableau unique des zones (on ramasse ici, on livre ici, prix « Partout » et prix de chaque
 * trajet), les suppléments et les retours, les grilles négociées. Remplace les anciennes pages Zones et Tarifs.
 */
const TABS = [
  { value: 'zones', label: 'Zones et prix' },
  { value: 'supplements', label: 'Suppléments et retours' },
  { value: 'grilles', label: 'Grilles négociées' },
]

// Reprise de l'ancienne application : ses adresses à part, seulement pour les entreprises qui l'ont faite
const auth = useAuthStore()
const tabs = computed(() => (auth.user?.company?.uses_legacy ? [...TABS, { value: 'anciennes', label: 'Adresses de l\'ancienne application' }] : TABS))
const route = useRoute()
const router = useRouter()
// « trajets » : l'ancien onglet, désormais dans Zones et prix
const tab = ref(tabs.value.some((t) => t.value === route.query.onglet) ? route.query.onglet : 'zones')
const grids = ref([])
const gridId = ref(Number(route.query.grille) || null)
const grid = computed(() => grids.value.find((g) => g.id === gridId.value) ?? null)

function setTab(value) {
  tab.value = value
  router.replace({ query: { ...route.query, onglet: value === 'zones' ? undefined : value } })
}

function chooseGrid(id) {
  gridId.value = id
  const isDefault = grids.value.find((g) => g.id === id)?.is_default
  router.replace({ query: { ...route.query, grille: isDefault ? undefined : id } })
}

async function loadGrids() {
  grids.value = (await http.get('/pricing-grids')).data.data
  if (!grids.value.some((g) => g.id === gridId.value)) gridId.value = grids.value.find((g) => g.is_default)?.id ?? grids.value[0]?.id ?? null
}

onMounted(loadGrids)
</script>
