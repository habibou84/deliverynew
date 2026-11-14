<template>
  <!-- Chiffre clé de la charte : intitulé, chiffre, précision ; ni fond coloré ni emoji -->
  <div class="card p-4">
    <p class="text-sm text-slate-600">{{ title }}</p>
    <p class="text-2xl font-bold tabular-nums">{{ value }}</p>
    <p v-if="subtitle" class="text-sm text-slate-600">{{ subtitle }}</p>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  // Ancien emoji : plus affiché (charte : pas d'icône sur un chiffre clé), gardé pour ne pas casser les appels
  icon: { type: String, default: '' },
  subtitle: { type: String, default: '' },
})
</script>
