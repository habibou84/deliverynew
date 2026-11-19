<template>
  <!-- Les 3 packs de commandes en live (réglés dans la console), comme Jibiat Marchand ; le pack 2 est conseillé -->
  <div class="space-y-2">
    <component
      :is="selectable ? 'button' : 'div'"
      v-for="p in packs"
      :key="p.index"
      :type="selectable ? 'button' : undefined"
      :class="['relative flex w-full items-center gap-3 rounded-2xl border-[2.5px] px-3.5 py-3 text-left', selectable && modelValue === p.index ? 'border-[var(--app-color)] bg-slate-50' : 'border-slate-200']"
      :aria-pressed="selectable ? modelValue === p.index : undefined"
      @click="selectable && $emit('update:modelValue', p.index)"
    >
      <span v-if="p.advised" class="absolute -top-2.5 right-3 rounded-full bg-amber-400 px-2 py-0.5 text-[11.5px] font-black text-slate-900">Conseillé</span>
      <span class="min-w-0 flex-1">
        <b class="block text-[17px] leading-tight">{{ p.orders }} commandes</b>
        <span class="text-[13px] text-slate-600">{{ money(p.unit) }} la commande<template v-if="p.cards"> · {{ p.cards }} fiches IA incluses</template></span>
      </span>
      <b class="shrink-0 text-[17px]">{{ money(p.price) }}</b>
    </component>
  </div>
</template>

<script setup>
import { money } from '../../../utils/format'

defineProps({ packs: { type: Array, required: true }, modelValue: { type: Number, default: null }, selectable: { type: Boolean, default: true } })
defineEmits(['update:modelValue'])
</script>
