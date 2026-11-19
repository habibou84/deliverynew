<template>
  <!-- Appeler, écrire sur WhatsApp, aller sur place : trois grands boutons -->
  <div class="grid grid-cols-3 gap-2">
    <a :href="telLink(phone)" :class="btn"><Icone nom="appeler" taille="grande" />Appeler</a>
    <a :href="whatsappLink(phone)" target="_blank" rel="noopener" :class="btn"><Icone nom="ecrire" taille="grande" />WhatsApp</a>
    <a v-if="mapsHref" :href="mapsHref" target="_blank" rel="noopener" :class="btn"><Icone nom="trajet" taille="grande" />{{ mapsLabel }}</a>
    <span v-else :class="[btn, 'opacity-40']"><Icone nom="trajet" taille="grande" />Position ?</span>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import Icone from '../ui/Icone.vue'
import { telLink, whatsappLink } from '../../utils/format'

const props = defineProps({
  phone: { type: String, default: '' },
  mapsHref: { type: String, default: null },
  mapsLabel: { type: String, default: 'Itinéraire' },
  // Fond des boutons : gris (carte blanche) ou blanc (bandeau coloré)
  light: { type: Boolean, default: false },
})

const btn = computed(() => `tap rounded-2xl py-3 flex flex-col items-center gap-1 text-sm font-medium ${props.light ? 'bg-white active:bg-white/80' : 'bg-slate-100 active:bg-slate-200'}`)
</script>
