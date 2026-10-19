<template>
  <div class="m-card overflow-hidden">
    <RouterLink :to="to" class="flex items-center gap-3 p-4 active:bg-slate-50">
      <!-- Où en est la course, en une phrase -->
      <span :class="['h-11 w-11 shrink-0 rounded-xl grid place-items-center text-xl', state.bg]" aria-hidden="true">{{ state.icon }}</span>
      <div class="min-w-0 flex-1">
        <p class="font-semibold truncate">{{ order.recipient.name || localPhone(order.recipient.phone) }}</p>
        <p class="text-sm text-slate-500 truncate">{{ place }}</p>
        <p :class="['text-sm font-medium truncate', state.color]">{{ state.text }}</p>
      </div>
      <div class="shrink-0 text-right">
        <p class="font-semibold">{{ money(order.amounts.cod_amount) }}</p>
        <p class="font-mono text-[11px] text-slate-400">{{ order.tracking_code }}</p>
      </div>
    </RouterLink>
    <!-- Actions directes (ex. appeler le client d'une course à régler) -->
    <div v-if="$slots.actions" class="flex gap-2 px-4 pb-4">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { localPhone, money } from '../../utils/format'
import { merchantStatus } from '../../utils/merchantStatus'

const props = defineProps({
  order: { type: Object, required: true },
  to: { type: String, required: true },
})

const state = computed(() => merchantStatus(props.order))

// « Adjamé, près du marché » plutôt que « Adjamé · Adjamé, près du marché »
const place = computed(() => {
  const { zone_name: zone, address } = props.order.delivery
  if (!address) return zone
  return zone && !address.toLowerCase().startsWith(zone.toLowerCase()) ? `${zone} · ${address}` : address
})
</script>
