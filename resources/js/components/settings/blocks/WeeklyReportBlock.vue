<template>
  <SettingsBlock title="Point de la semaine" :panel="panel">
    <label class="flex items-start gap-2 text-sm">
      <input v-model="form.weekly_report" type="checkbox" class="mt-1">
      <span>Envoyer le point de la semaine aux administrateurs<span class="block text-gray-500">Chaque lundi vers 7 h 30, par WhatsApp et dans la cloche : courses, taux de livraison, chiffre d'affaires, e-commerçants à relancer.</span></span>
    </label>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'

defineProps({ form: { type: Object, required: true }, panel: Boolean })
</script>
