<template>
  <!-- Vidéo « Comment ça marche » en grand, par-dessus la page : ✕ pour revenir -->
  <Teleport to="body">
    <div class="fixed inset-0 z-[60] flex flex-col bg-black" role="dialog" aria-modal="true" aria-label="Comment ça marche, en vidéo" @keydown.esc="$emit('close')">
      <header class="flex shrink-0 items-center gap-2 px-4 pb-3 pt-[max(.75rem,env(safe-area-inset-top))] text-white">
        <h2 class="min-w-0 flex-1 truncate text-[17px] font-black">🎬 Comment ça marche</h2>
        <button ref="closeBtn" type="button" class="grid h-10 w-10 place-items-center rounded-full bg-white/15 text-[17px] font-bold" aria-label="Fermer la vidéo" @click="$emit('close')">✕</button>
      </header>
      <div class="flex min-h-0 flex-1 items-center justify-center px-2 pb-[max(.75rem,env(safe-area-inset-bottom))]">
        <video class="max-h-full w-full max-w-[560px] rounded-2xl bg-black" controls autoplay playsinline preload="auto" :poster="`${VIDEO}.jpg`" :src="`${VIDEO}.mp4`" />
      </div>
      <p class="shrink-0 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-center text-[13.5px] text-white/70">1 min 20 · avec le son, ou en lisant les sous-titres</p>
    </div>
  </Teleport>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/** Vidéo « Comment ça marche » (tools/motion, scène live-comment-ca-marche), voix off et sous-titres, en plein écran */
defineEmits(['close'])
const VIDEO = '/videos/live/live-comment-ca-marche-v'
const closeBtn = ref(null)

// La page derrière ne défile pas tant que la vidéo est ouverte
let overflow = ''
onMounted(() => {
  overflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  closeBtn.value?.focus()
})
onBeforeUnmount(() => { document.body.style.overflow = overflow })
</script>
