<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
      <p v-if="!form" class="text-sm text-gray-500">Chargement…</p>
      <template v-else>
        <component :is="BLOCKS[key].component" v-for="key in blocks" :key="key" :form="form" panel />
      </template>
    </div>
    <div class="border-t bg-slate-50 px-5 py-3">
      <p v-if="error" class="field-error mb-2">{{ error }}</p>
      <div class="flex items-center justify-between gap-2">
        <RouterLink :to="more" class="text-sm text-blue-600 underline" @click="$emit('close')">Tous les réglages →</RouterLink>
        <span class="flex gap-2">
          <button v-if="dirty" type="button" class="btn-secondary" :disabled="saving" @click="reset">Annuler</button>
          <button type="button" class="btn-primary" :disabled="saving || !dirty" @click="submit">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { watch } from 'vue'
import { useCompanySettings } from '../../composables/useCompanySettings'
import { BLOCKS, fieldsOf } from './blocks'

const props = defineProps({ blocks: { type: Array, required: true }, more: { type: String, default: '/admin/reglages' } })
const emit = defineEmits(['saved', 'dirty', 'close'])

const { form, dirty, saving, error, save, reset } = useCompanySettings(fieldsOf(props.blocks))
watch(dirty, (v) => emit('dirty', v))

async function submit() {
  await save()
  if (!error.value) emit('saved', { ...form.value })
}
</script>
