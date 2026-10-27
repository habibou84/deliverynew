<template>
  <SettingsBlock title="Validation des nouvelles courses" :panel="panel">
    <div class="space-y-2" role="radiogroup" aria-label="Validation des nouvelles courses">
      <label v-for="m in VALIDATION_MODES" :key="m.value" :class="['flex items-start gap-2 rounded-lg border p-3 text-sm cursor-pointer', form.validation_mode === m.value ? 'border-blue-500 bg-blue-50' : 'border-slate-200']">
        <input v-model="form.validation_mode" type="radio" :value="m.value" class="mt-1">
        <span><strong>{{ m.label }}</strong><span class="block text-gray-500">{{ m.help }}</span></span>
      </label>
      <p v-if="form.validation_mode === 'rules' && 'daily_cutoff_time' in form && !form.daily_cutoff_time" class="text-xs text-amber-700">
        Choisissez l'heure limite du jour ci-dessous : sans elle, seule la fermeture de la journée par le dispatch décale les courses.
      </p>
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import { VALIDATION_MODES } from '../../../views/admin/settings/options'

defineProps({ form: { type: Object, required: true }, panel: Boolean })
</script>
