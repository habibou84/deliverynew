<template>
  <!-- Badge d'état de la charte : ton (succès, attention, critique, info, neutre), point de couleur et libellé écrit -->
  <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1', statusClass(status)]">
    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
    {{ label || STATUS_LABELS[status] || status }}
  </span>
</template>

<script setup>
import { statusClass, STATUS_LABELS } from '../utils/format'

defineProps({
  status: { type: String, required: true },
  label: { type: String, default: '' },
})
</script>
