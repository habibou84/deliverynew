<template>
  <div>
    <label class="label" :for="id">{{ label }}</label>
    <input :id="id" v-bind="$attrs" :value="modelValue" :class="['input', isChanged && 'ring-2 ring-amber-400 bg-amber-50']" @input="update($event.target.value)">
    <p v-if="isChanged && before !== '' && before !== null && before !== undefined" class="text-xs text-gray-400 line-through">{{ before }}</p>
  </div>
</template>

<script setup>
import { computed, useId } from 'vue'

/** Champ de « Modifier la course » : surligné quand il change, ancienne valeur barrée en dessous */
defineOptions({ inheritAttrs: false })
const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  label: { type: String, required: true },
  before: { type: [String, Number], default: '' },
  modelModifiers: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
const id = useId()
const norm = (v) => (v === '' || v === null || v === undefined ? '' : String(v))
const isChanged = computed(() => norm(props.modelValue) !== norm(props.before))

function update(value) {
  emit('update:modelValue', props.modelModifiers.number && value !== '' ? Number(value) : value)
}
</script>
