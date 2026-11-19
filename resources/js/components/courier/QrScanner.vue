<template>
  <!-- Lecture d'un QR code à la caméra (plein écran) : BarcodeDetector quand le téléphone l'a, sinon jsQR -->
  <div class="fixed inset-0 z-50 flex flex-col bg-black text-white" role="dialog" aria-modal="true" :aria-label="title">
    <div class="flex items-center justify-between gap-3 p-4">
      <p class="font-semibold">{{ title }}</p>
      <button type="button" class="tap rounded-full bg-white/15 px-4 py-2 text-sm font-semibold" @click="emit('close')">Fermer</button>
    </div>
    <div class="relative flex-1 overflow-hidden">
      <video ref="video" class="h-full w-full object-cover" playsinline muted />
      <!-- Cadre de visée -->
      <div class="pointer-events-none absolute inset-0 grid place-items-center">
        <div class="h-64 w-64 rounded-3xl border-4 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]" />
      </div>
    </div>
    <p class="p-4 text-center text-sm" :class="error ? 'text-red-300' : 'text-white/80'">{{ error || hint }}</p>
  </div>
</template>

<script setup>
import jsQR from 'jsqr'
import { onBeforeUnmount, onMounted, ref } from 'vue'

defineProps({
  title: { type: String, default: 'Scanner le QR code' },
  hint: { type: String, default: 'Visez le QR code affiché sur l\'autre téléphone.' },
})
// read : texte du QR code lu (le parent décide s'il est bon) ; close : fermé sans lecture
const emit = defineEmits(['read', 'close'])
const video = ref(null)
const error = ref('')
let stream = null
let frame = null
let stopped = false

async function start() {
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
  } catch {
    error.value = 'Caméra indisponible : autorisez la caméra pour l\'application, ou saisissez le code à la place.'
    return
  }
  video.value.srcObject = stream
  await video.value.play().catch(() => {})

  const detector = 'BarcodeDetector' in window ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d', { willReadFrequently: true })

  const tick = async () => {
    if (stopped) return
    const v = video.value
    if (v && v.readyState >= 2 && v.videoWidth) {
      let text = null
      try {
        if (detector) {
          text = (await detector.detect(v))[0]?.rawValue ?? null
        } else {
          // Image réduite : lecture plus rapide sur les petits téléphones
          const scale = Math.min(1, 640 / v.videoWidth)
          canvas.width = Math.round(v.videoWidth * scale)
          canvas.height = Math.round(v.videoHeight * scale)
          ctx.drawImage(v, 0, 0, canvas.width, canvas.height)
          const img = ctx.getImageData(0, 0, canvas.width, canvas.height)
          text = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' })?.data ?? null
        }
      } catch {
        text = null
      }
      if (text) {
        emit('read', text)
        return
      }
    }
    frame = setTimeout(tick, 150)
  }
  tick()
}

function stop() {
  stopped = true
  clearTimeout(frame)
  stream?.getTracks().forEach((t) => t.stop())
}

onMounted(start)
onBeforeUnmount(stop)
</script>
