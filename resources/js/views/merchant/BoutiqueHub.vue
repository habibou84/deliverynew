<template>
  <!-- Onglet Boutique (téléphone) : la boutique en ligne et le stock des produits (chez moi et à l'entrepôt de l'agence) -->
  <div class="space-y-3.5">
    <div v-if="parts.length > 1" class="flex rounded-2xl bg-slate-200 p-1">
      <button v-for="p in parts" :key="p.key" type="button" :class="['tap flex-1 rounded-xl py-2.5 text-[15px] font-bold', part === p.key ? 'bg-white shadow-sm' : 'text-slate-600']" @click="part = p.key">{{ p.label }}</button>
    </div>

    <!-- Stock : quantités par endroit -->
    <template v-if="part === 'stock'">
      <div v-if="places.length" class="grid grid-cols-2 gap-2.5">
        <div v-for="pl in places" :key="pl.key" class="m-card p-3">
          <p class="text-[12.5px] text-slate-600">{{ pl.hub ? '🏬' : '🏠' }} {{ pl.label }}</p>
          <p class="text-[20px] font-extrabold">{{ pl.qty }}</p>
          <p class="text-[12.5px] text-slate-600">article{{ pl.qty > 1 ? 's' : '' }} en stock</p>
        </div>
      </div>
      <Stock @loaded="summarize" />
    </template>

    <Shop v-else-if="part === 'shop'" />

    <EmptyState v-else art="stock" title="Pas encore de boutique" text="La boutique en ligne et le stock ne sont pas ouverts pour votre compte. Demandez-les à votre agence de livraison." />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import Shop from './Shop.vue'
import Stock from './Stock.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const parts = computed(() => [
  auth.hasFeature('stock') && { key: 'stock', label: 'Mon stock' },
  auth.can('integrations.manage') && auth.hasFeature('shop') && { key: 'shop', label: 'Boutique en ligne' },
].filter(Boolean))
const part = ref(parts.value[0]?.key ?? null)

// Total en stock par endroit : chez l'e-commerçant et dans chaque entrepôt de l'agence
const places = ref([])
function summarize(products) {
  const byPlace = new Map()
  for (const p of products || []) {
    for (const l of p.levels || []) {
      const key = l.hub_id ? `h${l.hub_id}` : 'home'
      const pl = byPlace.get(key) ?? { key, hub: !!l.hub_id, label: l.hub_id ? l.label : 'Chez moi', qty: 0 }
      pl.qty += Number(l.on_hand || 0)
      byPlace.set(key, pl)
    }
  }
  places.value = [...byPlace.values()].sort((a, b) => Number(a.hub) - Number(b.hub))
}
</script>
