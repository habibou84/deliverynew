<template>
  <RouterLink
    :to="to"
    class="flex min-h-10 items-center gap-3 rounded-lg px-2.5 py-2 hover:bg-white/15"
    :active-class="prefix ? 'bg-white/20' : ''"
    :exact-active-class="prefix ? '' : 'bg-white/20'"
  >
    <Icone v-if="icon" :nom="icon" />
    <span class="flex-1">{{ label }}</span>
    <span v-if="badge" class="min-w-5 h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold grid place-items-center">{{ badge > 99 ? '99+' : badge }}</span>
  </RouterLink>
</template>

<script setup>
import Icone from './ui/Icone.vue'

defineProps({
  icon: { type: String, default: '' },
  label: { type: String, required: true },
  to: { type: String, required: true },
  badge: { type: Number, default: 0 },
  // Reste allumé sur les pages en dessous (onglets)
  prefix: { type: Boolean, default: false },
})
</script>
