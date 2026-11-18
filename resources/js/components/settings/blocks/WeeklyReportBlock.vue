<template>
  <SettingsBlock title="Point de la semaine" :panel="panel">
    <p class="text-sm text-slate-600">Chaque lundi vers 7 h 30, aux administrateurs, par WhatsApp et dans la cloche : courses, taux de livraison, chiffre d'affaires, e-commerçants à relancer.</p>
    <MessageSettingLink :on="!!form.weekly_report" label="Point de la semaine" />
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import MessageSettingLink from '../MessageSettingLink.vue'

defineProps({ form: { type: Object, required: true }, panel: Boolean })
</script>
