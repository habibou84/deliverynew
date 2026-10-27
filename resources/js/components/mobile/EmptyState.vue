<template>
  <div class="text-center py-10 px-6">
    <!-- « livraison » : le colis et le livreur ; sinon une icône en couleur, ou à défaut un emoji -->
    <svg v-if="art === 'livraison'" width="230" height="140" viewBox="0 0 230 140" class="mx-auto mb-2" aria-hidden="true">
      <ellipse cx="115" cy="124" rx="96" ry="9" fill="#EEF1F5" />
      <path d="M20 124 C 60 102, 170 102, 210 124" stroke="#D2C4FF" stroke-width="3" stroke-dasharray="2 8" stroke-linecap="round" fill="none" />
      <circle cx="196" cy="24" r="5" fill="#A8E6C9" /><circle cx="30" cy="20" r="3.5" fill="#FFC1CB" /><circle cx="104" cy="12" r="3" fill="#B9D4FF" />
      <ColorIcon name="colis" x="26" y="38" :size="80" :tile="false" />
      <ColorIcon name="moto" x="118" y="44" :size="80" :tile="false" />
    </svg>
    <div v-else-if="art" class="mb-3 flex justify-center"><ColorIcon :name="art" :size="72" /></div>
    <div v-else class="text-5xl mb-3">{{ icon }}</div>
    <p class="text-[17px] font-bold text-slate-800">{{ title }}</p>
    <p v-if="text" class="text-sm text-slate-500 mt-1">{{ text }}</p>
    <div v-if="$slots.default" class="mt-5"><slot /></div>
  </div>
</template>

<script setup>
import ColorIcon from './ColorIcon.vue'

defineProps({
  icon: { type: String, default: '📭' },
  art: { type: String, default: '' },
  title: { type: String, required: true },
  text: { type: String, default: '' },
})
</script>
