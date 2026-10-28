<template>
  <SettingsBlock title="Modifications des e-commerçants" :panel="panel">
    <div class="space-y-2" role="radiogroup" aria-label="Modifications des e-commerçants">
      <label v-for="m in EDIT_APPROVAL_MODES" :key="m.value" :class="['flex items-start gap-2 rounded-lg border p-3 text-sm cursor-pointer', form.merchant_edit_approval === m.value ? 'border-blue-500 bg-blue-50' : 'border-slate-200']">
        <input v-model="form.merchant_edit_approval" type="radio" :value="m.value" class="mt-1">
        <span><strong>{{ m.label }}</strong><span class="block text-gray-500">{{ m.help }}</span></span>
      </label>
      <p class="text-xs text-gray-500">Les modifications faites par votre équipe s'appliquent toujours tout de suite. Les applications reliées par l'API (Jibiat Marchand, sites) ne passent pas par cette validation.</p>
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import { EDIT_APPROVAL_MODES } from '../../../views/admin/settings/options'

defineProps({ form: { type: Object, required: true }, panel: Boolean })
</script>
