<template>
  <SettingsBlock title="Colis resté chez un livreur" :panel="panel">
    <div>
      <label class="label" :for="`hold-alert${uid}`">Colis non livré resté chez un livreur depuis plus de (heures)</label>
      <input :id="`hold-alert${uid}`" v-model.number="form.parcel_hold_alert_hours" type="number" min="0" max="168" class="input w-24">
      <p class="text-xs text-gray-500 mt-1">Alerte sonore au dispatch, une fois par colis, et colis en rouge dans « Colis chez les livreurs ». 0 = pas d'alerte.</p>
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
