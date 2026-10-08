<template>
  <RouterLink
    :to="to"
    class="flex items-center gap-2 p-2 rounded hover:bg-slate-700"
    exact-active-class="bg-slate-700"
  >
    <span>{{ icon }}</span>
    <span>{{ label }}</span>
  </RouterLink>
</template>

<script setup>
defineProps({
  icon: { type: String, default: '' },
  label: { type: String, required: true },
  to: { type: String, required: true },
})
</script>
