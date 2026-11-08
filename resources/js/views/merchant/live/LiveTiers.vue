<template>
  <!-- Prix d'un live selon ses commandes (paliers réglés dans la console) -->
  <div class="divide-y divide-slate-100 rounded-2xl bg-slate-50 px-3">
    <div v-for="(t, i) in tiers" :key="i" :class="['flex justify-between py-2 text-[14.5px]', current === i + 1 ? 'font-black text-[var(--app-color)]' : '']">
      <span>{{ t.to === null ? `Plus de ${t.from - 1} commandes` : t.from === 0 ? `0 à ${t.to} commandes` : `${t.from} à ${t.to} commandes` }}</span>
      <b :class="t.price ? '' : 'text-emerald-700'">{{ t.price ? money(t.price) : 'Gratuit' }}</b>
    </div>
  </div>
</template>

<script setup>
import { money } from '../../../utils/format'

defineProps({ tiers: { type: Array, required: true }, current: { type: Number, default: 0 } })
</script>
