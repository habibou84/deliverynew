<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex flex-col bg-black text-white" role="dialog" aria-modal="true" :aria-label="title">
      <div class="flex items-center justify-between gap-3 p-4" style="padding-top: max(1rem, env(safe-area-inset-top))">
        <p class="font-semibold">{{ title }}</p>
        <button type="button" class="tap rounded-full bg-white/15 px-4 py-2 text-sm font-semibold" @click="emit('close')">Fermer</button>
      </div>

      <div class="relative flex-1 overflow-hidden">
        <video ref="video" class="absolute inset-0 h-full w-full object-cover" playsinline muted />
        <!-- Cadre de visée -->
        <div v-if="!error" class="pointer-events-none absolute inset-0 flex items-center justify-center">
          <div class="h-64 w-64 rounded-3xl border-4 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]" />
        </div>
        <div v-if="error" class="absolute inset-0 flex items-center justify-center p-6">
          <p class="rounded-2xl bg-white p-4 text-center text-sm text-red-700" role="alert">{{ error }}</p>
        </div>
      </div>

      <p class="p-4 text-center text-sm text-white/80" style="padding-bottom: max(1rem, env(safe-area-inset-bottom))">{{ hint }}</p>
    </div>
  </Teleport>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Scanner de QR code plein écran (caméra arrière). Utilise le détecteur du téléphone quand il existe (Android),
 * sinon jsQR, chargé seulement à l'ouverture. Émet « detected » avec le texte lu, une seule fois.
 */
defineProps({
  title: { type: String, default: 'Scanner le QR code' },
  hint: { type: String, default: 'Placez le QR code dans le cadre.' },
})
const emit = defineEmits(['detected', 'close'])

const video = ref(null)
const error = ref('')
let stream = null
let frame = null
let stopped = false

function stop() {
  stopped = true
  if (frame) cancelAnimationFrame(frame)
  stream?.getTracks().forEach((t) => t.stop())
  stream = null
}

async function detector() {
  if ('BarcodeDetector' in window) {
    try {
      const formats = await window.BarcodeDetector.getSupportedFormats()
      if (formats.includes('qr_code')) {
        const d = new window.BarcodeDetector({ formats: ['qr_code'] })
        return async (el) => (await d.detect(el))[0]?.rawValue || null
      }
    } catch { /* jsQR à la place */ }
  }
  const { default: jsQR } = await import('jsqr')
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  return async (el) => {
    // Image réduite : plus rapide, suffisant pour un QR code tenu devant la caméra
    const scale = Math.min(1, 640 / Math.max(el.videoWidth, el.videoHeight))
    canvas.width = Math.round(el.videoWidth * scale)
    canvas.height = Math.round(el.videoHeight * scale)
    ctx.drawImage(el, 0, 0, canvas.width, canvas.height)
    const img = ctx.getImageData(0, 0, canvas.width, canvas.height)
    return jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' })?.data || null
  }
}

onMounted(async () => {
  if (!navigator.mediaDevices?.getUserMedia) {
    error.value = 'La caméra n\'est pas disponible sur ce téléphone.'
    return
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
  } catch (e) {
    error.value = e?.name === 'NotAllowedError'
      ? 'Autorisez l\'accès à la caméra dans les réglages du téléphone pour scanner le QR code.'
      : 'Impossible d\'ouvrir la caméra.'
    return
  }
  if (stopped) return stop()
  video.value.srcObject = stream
  await video.value.play().catch(() => {})
  const read = await detector()

  let busy = false
  const tick = async () => {
    if (stopped) return
    if (!busy && video.value?.readyState >= 2 && video.value.videoWidth) {
      busy = true
      const text = await read(video.value).catch(() => null)
      busy = false
      if (text && !stopped) {
        stop()
        navigator.vibrate?.(80)
        emit('detected', text)
        return
      }
    }
    frame = requestAnimationFrame(tick)
  }
  tick()
})

onBeforeUnmount(stop)
</script>
