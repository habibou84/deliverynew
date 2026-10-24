<template>
  <div :class="['inline-flex', readonly ? 'gap-0.5' : 'gap-1.5']" :role="readonly ? 'img' : 'radiogroup'" :aria-label="readonly ? `${display} sur 5` : label">
    <component
      :is="readonly ? 'span' : 'button'"
      v-for="n in 5"
      :key="n"
      :type="readonly ? undefined : 'button'"
      :role="readonly ? undefined : 'radio'"
      :aria-checked="readonly ? undefined : modelValue === n"
      :aria-label="readonly ? undefined : `${n} sur 5 : ${WORDS[n]}`"
      :class="readonly ? '' : 'tap rounded-lg transition active:scale-90'"
      @click="readonly || $emit('update:modelValue', n)"
    >
      <svg :class="sizeClass" viewBox="0 0 24 24" aria-hidden="true">
        <path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" :fill="fill(n)" :stroke="fill(n)" stroke-width="1.5" stroke-linejoin="round" />
      </svg>
    </component>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { SCORE_WORDS as WORDS } from './ratingWords'

/**
 * Étoiles de 1 à 5 : à toucher pour noter, ou en lecture (note décimale arrondie).
 */
const props = defineProps({
  modelValue: { type: Number, default: 0 },
  readonly: { type: Boolean, default: false },
  size: { type: String, default: 'lg' },
  label: { type: String, default: 'Note' },
})
defineEmits(['update:modelValue'])

const display = computed(() => String(props.modelValue ?? 0).replace('.', ','))
const sizeClass = computed(() => ({ sm: 'h-4 w-4', md: 'h-6 w-6', lg: 'h-10 w-10' })[props.size])
const fill = (n) => (n <= Math.round(props.modelValue || 0) ? '#f59e0b' : '#e2e8f0')
</script>
