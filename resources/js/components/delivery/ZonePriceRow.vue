<template>
  <!-- Une ligne de Zones et prix : interrupteur, nom, prix (enregistré à la sortie du champ) -->
  <div :class="['grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-2 px-4 py-2.5 sm:grid-cols-[1fr_8rem_15rem]', zone.parent_id ? 'bg-slate-50/60 pl-10' : '']">
    <div class="flex min-w-0 flex-wrap items-center gap-3">
      <button
        type="button" role="switch" :aria-checked="zone.is_active" :aria-label="`On livre ici : ${zone.name}`" :disabled="saving"
        :class="['relative h-7 w-12 shrink-0 rounded-full', zone.is_active ? 'bg-emerald-700' : 'bg-slate-400']"
        @click="$emit('toggle')"
      >
        <span :class="['absolute top-[3px] h-[22px] w-[22px] rounded-full bg-white transition-all', zone.is_active ? 'left-[23px]' : 'left-[3px]']" />
      </button>
      <span :class="['min-w-0', zone.parent_id ? '' : 'font-semibold', zone.is_active ? 'text-slate-900' : 'text-slate-600 line-through']">{{ zone.name }}</span>
      <span v-if="!zone.place" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200">lieu ajouté</span>
      <button v-if="childrenCount" type="button" class="text-xs font-semibold text-blue-700" :aria-expanded="open" @click="$emit('expand')">
        {{ open ? 'Masquer' : 'Voir' }} les {{ childrenCount }} quartier{{ childrenCount > 1 ? 's' : '' }}
      </button>
      <template v-if="!zone.place">
        <button type="button" class="text-xs text-blue-700" @click="$emit('rename')">Renommer</button>
        <button type="button" class="text-xs text-red-700" @click="$emit('remove')">Supprimer</button>
      </template>
    </div>
    <!-- Ramassage : l'entreprise ramasse-t-elle ici ? (un quartier suit sa commune quand elle est coupée) -->
    <div class="col-span-2 flex items-center gap-2 sm:col-span-1">
      <button
        v-if="!zone.is_shipping" type="button" role="switch" :aria-checked="pickupOn" :aria-label="`On ramasse ici : ${zone.name}`"
        :disabled="saving || !zone.is_active || parentPickup === false"
        :title="parentPickup === false ? 'Pas de ramassage dans la commune' : ''"
        :class="['relative h-6 w-10 shrink-0 rounded-full disabled:opacity-50', pickupOn ? 'bg-blue-800' : 'bg-slate-400']"
        @click="$emit('pickup')"
      >
        <span :class="['absolute top-[3px] h-[18px] w-[18px] rounded-full bg-white transition-all', pickupOn ? 'left-[19px]' : 'left-[3px]']" />
      </button>
      <span class="text-xs text-slate-600 sm:hidden">Ramassage</span>
      <span v-if="!zone.is_shipping" class="hidden text-xs text-slate-600 sm:inline">{{ pickupOn ? 'oui' : 'non' }}</span>
    </div>
    <div class="flex items-center gap-2">
      <input
        :value="zone.price" type="number" min="0" step="100" inputmode="numeric"
        :class="['input w-28 py-1.5 text-right tabular-nums', missing ? 'border-amber-500' : '']"
        :placeholder="placeholder" :aria-label="`Prix de livraison : ${zone.name}`" :disabled="saving"
        @change="$emit('price', $event.target.value)" @keydown.enter="$event.target.blur()"
      >
      <span class="whitespace-nowrap text-sm text-slate-600">F<template v-if="usesParent"> · commune</template></span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  zone: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  isDefault: { type: Boolean, default: true },
  childrenCount: { type: Number, default: 0 },
  open: { type: Boolean, default: false },
  // Prix de la commune, repris par un quartier sans prix propre
  parentPrice: { type: Number, default: null },
  // Ramassage dans la commune (quartier) ; null pour une commune
  parentPickup: { type: Boolean, default: null },
})
defineEmits(['toggle', 'pickup', 'price', 'expand', 'rename', 'remove'])

const pickupOn = computed(() => props.zone.is_active && props.zone.is_pickup && props.parentPickup !== false)

const inherited = computed(() => props.parentPrice ?? props.zone.inherited_price)
// Quartier sans prix propre : il prend celui de sa commune
const usesParent = computed(() => props.zone.parent_id && props.zone.price === null && inherited.value !== null)
const missing = computed(() => props.isDefault && props.zone.is_active && props.zone.price === null && inherited.value === null)
const placeholder = computed(() => {
  if (props.zone.parent_id && inherited.value !== null) return String(inherited.value)
  return props.isDefault ? 'Pas de prix' : 'grille normale'
})
</script>
