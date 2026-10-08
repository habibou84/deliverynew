<template>
  <RouterLink :to="to" class="block m-card p-4 active:bg-slate-50">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="font-semibold truncate">{{ order.recipient.name || order.recipient.phone }}</p>
        <p class="text-sm text-slate-500 truncate">{{ order.delivery.zone_name }}<span v-if="order.delivery.address"> · {{ order.delivery.address }}</span></p>
      </div>
      <StatusBadge :status="order.status" :label="order.status_label" />
    </div>
    <div class="flex items-center justify-between mt-3 text-sm">
      <span class="font-mono text-xs text-slate-400">{{ order.tracking_code }}</span>
      <span class="font-semibold">{{ money(order.amounts.cod_amount) }}</span>
    </div>
    <p v-if="order.last_incident && ['delivery_failed', 'rescheduled'].includes(order.status)" class="mt-2 text-sm text-red-600">⚠️ {{ order.last_incident.label }}</p>
  </RouterLink>
</template>

<script setup>
import StatusBadge from '../StatusBadge.vue'
import { money } from '../../utils/format'

defineProps({
  order: { type: Object, required: true },
  to: { type: String, required: true },
})
</script>
