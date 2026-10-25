<template>
  <!-- Vignette cliquable : la vidéo ne se charge qu'au clic -->
  <button type="button" :class="['card w-full overflow-hidden text-left flex items-stretch hover:ring-2 hover:ring-blue-300', compact ? '' : 'flex-col sm:flex-row']" @click="open = true">
    <span :class="['relative shrink-0 bg-slate-900', compact ? 'w-28' : 'sm:w-56 aspect-video sm:aspect-auto']">
      <img :src="`${BASE}/${name}-w.jpg`" alt="" class="h-full w-full object-cover opacity-80" loading="lazy">
      <span class="absolute inset-0 grid place-items-center">
        <span class="grid h-11 w-11 place-items-center rounded-full bg-white/90 text-lg text-slate-900 shadow">▶</span>
      </span>
    </span>
    <span class="min-w-0 flex-1 p-3">
      <span class="block font-semibold">🎬 {{ title }}</span>
      <span class="block text-sm text-gray-600">{{ text }}</span>
      <span class="mt-1 block text-sm font-medium text-blue-600">Regarder · {{ duration }}</span>
    </span>
  </button>

  <Modal :open="open" :title="title" @close="open = false">
    <!-- Téléphone : version verticale ; ordinateur : version 16:9 -->
    <video
      v-if="open" :key="format" controls autoplay playsinline preload="metadata"
      :poster="`${BASE}/${name}-${format}.jpg`" class="mx-auto max-h-[75vh] w-full rounded-lg bg-black"
    ><source :src="`${BASE}/${name}-${format}.mp4`" type="video/mp4"></video>
  </Modal>
</template>

<script setup>
import { computed, ref } from 'vue'
import { nativeApp, siteOrigin } from '../native'
import Modal from './Modal.vue'

/**
 * Vidéo d'aide (public/marketing/videos/<name>-w.mp4 et <name>-v.mp4) : vignette, puis lecture dans une
 * fenêtre, au format du téléphone ou de l'ordinateur.
 */
defineProps({
  name: { type: String, required: true },
  title: { type: String, required: true },
  text: { type: String, default: '' },
  duration: { type: String, default: '' },
  compact: { type: Boolean, default: false },
})

// Application native : les vidéos restent sur le site
const BASE = `${nativeApp ? siteOrigin() : ''}/marketing/videos`
const open = ref(false)
const format = computed(() => (window.matchMedia?.('(max-width: 767px)').matches ? 'v' : 'w'))
</script>
