<template>
  <div :class="['grid gap-2', columns === 2 ? 'grid-cols-2' : columns === 3 ? 'grid-cols-3' : 'grid-cols-1']" role="radiogroup" :aria-label="label">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="modelValue === option.value"
      :class="[
        'tap rounded-2xl px-4 py-3 text-left ring-1 transition active:scale-[0.98]',
        modelValue === option.value ? 'ring-2 ring-[var(--app-color)] bg-[color-mix(in_srgb,var(--app-color)_8%,white)]' : 'ring-slate-200 bg-white',
      ]"
      @click="$emit('update:modelValue', option.value)"
    >
      <span class="flex items-center gap-2">
        <span v-if="option.icon" class="text-xl">{{ option.icon }}</span>
        <span class="font-medium">{{ option.label }}</span>
      </span>
      <span v-if="option.description" class="block text-sm text-slate-500 mt-0.5">{{ option.description }}</span>
    </button>
  </div>
</template>

<script setup>
defineProps({
  modelValue: { type: [String, Number, Boolean, null], default: null },
  options: { type: Array, required: true }, // { value, label, icon?, description? }
  columns: { type: Number, default: 1 },
  label: { type: String, default: '' },
})
defineEmits(['update:modelValue'])
</script>
