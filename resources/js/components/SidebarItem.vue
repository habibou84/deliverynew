<template>
  <a
    href="#"
    class="flex items-center gap-2 p-2 rounded hover:bg-slate-700"
  >
    <span>{{ icon }}</span>
    <span>{{ label }}</span>
  </a>
</template>

<script setup>
defineProps({
  icon: { type: String, default: '' },
  label: { type: String, required: true },
})
</script>
