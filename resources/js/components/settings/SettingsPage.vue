<template>
  <div class="max-w-2xl space-y-4">
    <div>
      <RouterLink :to="back" class="text-sm text-blue-600">← {{ backLabel }}</RouterLink>
      <h1 class="text-xl font-bold mt-1">{{ icon }} {{ title }}</h1>
      <p v-if="intro" class="text-sm text-gray-600">{{ intro }}</p>
    </div>
    <slot />
  </div>
</template>

<script setup>
defineProps({ title: { type: String, required: true }, back: { type: String, default: '/admin/reglages' }, backLabel: { type: String, default: 'Réglages' }, icon: { type: String, default: '' }, intro: { type: String, default: '' } })
</script>
