<template>
  <!-- Un bloc de réglages : carte dans la page Réglages, section simple dans le panneau d'une page -->
  <section :class="panel ? 'space-y-3 border-b border-slate-100 pb-4 last:border-0' : 'card p-4 space-y-3'">
    <component :is="panel ? 'h3' : 'h2'" v-if="title" class="font-semibold">{{ title }}</component>
    <slot />
  </section>
</template>

<script setup>
defineProps({ title: { type: String, default: '' }, panel: Boolean })
</script>
