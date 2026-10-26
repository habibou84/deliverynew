<template>
  <SettingsBlock title="Ramassage automatique" :panel="panel">
    <p class="text-sm text-gray-600">
      Les ramassages des courses validées vont au livreur prévu par le
      <RouterLink to="/admin/planning" class="text-blue-600 underline">planning des livreurs</RouterLink>
      (ou à son remplaçant). Sans règle pour la course, rien ne change : l'équipe la confie.
    </p>
    <div :class="['grid gap-2', panel ? '' : 'sm:grid-cols-3']" role="radiogroup" aria-label="Ramassage automatique">
      <label v-for="m in AUTO_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.auto_pickup === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
        <input v-model="form.auto_pickup" type="radio" :value="m.value" class="mr-1">
        <strong>{{ m.label }}</strong>
        <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
      </label>
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import { AUTO_MODES } from '../../../views/admin/settings/options'

defineProps({ form: { type: Object, required: true }, panel: Boolean })
</script>
