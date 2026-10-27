<template>
  <SettingsBlock title="Relance des remontées terrain" :panel="panel">
    <div>
      <label class="label" :for="`reminder${uid}`">Relancer un problème signalé par un livreur et resté sans suite après (minutes)</label>
      <input :id="`reminder${uid}`" v-model.number="form.field_alert_reminder_minutes" type="number" min="0" max="240" class="input w-24">
      <p class="text-xs text-gray-500 mt-1">Relance au dispatch, puis aux administrateurs après trois fois ce délai. Répondre au livreur arrête les relances. 0 = pas de relance.</p>
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
