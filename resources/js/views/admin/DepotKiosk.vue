<template>
  <!-- Borne du dépôt : à laisser ouverte sur une tablette ou un téléphone fixé près du bac « Retours livreurs » -->
  <div class="mx-auto max-w-xl space-y-5 text-center">
    <div class="space-y-1">
      <h1 class="text-2xl font-bold">Borne de dépôt des colis</h1>
      <p class="text-slate-600">Livreur : dans votre application, ouvrez <strong>Ma caisse → Déposer mes colis</strong> et scannez ce QR code.</p>
    </div>

    <div class="card p-6 space-y-3">
      <img v-if="qr" :src="qr" alt="QR code de la borne de dépôt" class="mx-auto w-full max-w-sm">
      <p v-else-if="error" class="field-error">{{ error }}</p>
      <p v-else class="py-24 text-slate-600">Chargement…</p>
      <div class="h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
        <div class="h-full bg-emerald-500 transition-[width] duration-1000 ease-linear" :style="{ width: `${(left / WINDOW) * 100}%` }" />
      </div>
      <p class="text-sm text-slate-600">Nouveau code dans {{ left }} s : une photo du code ne sert à rien, il faut être devant la borne.</p>
    </div>

    <div class="grid grid-cols-2 gap-3">
      <div class="card p-4"><p class="text-3xl font-bold">{{ stats.today }}</p><p class="text-sm text-slate-600">dépôt{{ stats.today > 1 ? 's' : '' }} aujourd'hui</p></div>
      <RouterLink to="/admin/activite/colis?vue=depots" class="card p-4 hover:bg-slate-50"><p class="text-3xl font-bold" :class="stats.pending ? 'text-amber-700' : ''">{{ stats.pending }}</p><p class="text-sm text-slate-600">à contrôler ›</p></RouterLink>
    </div>
    <p class="text-xs text-slate-500">Laissez cette page ouverte : elle se met à jour toute seule. Pensez à désactiver la mise en veille de l'appareil.</p>
  </div>
</template>

<script setup>
import QRCode from 'qrcode'
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'

const WINDOW = 30
const qr = ref('')
const error = ref('')
const left = ref(WINDOW)
const stats = reactive({ today: 0, pending: 0 })
let tick = null
let wakeLock = null

async function refresh() {
  try {
    const { data } = await http.get('/parcel-drops/kiosk')
    qr.value = await QRCode.toDataURL(data.data.code, { margin: 1, width: 480 })
    left.value = data.data.expires_in
    Object.assign(stats, { today: data.data.today, pending: data.data.pending })
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e)
    left.value = 5
  }
}

onMounted(async () => {
  await refresh()
  tick = setInterval(() => {
    left.value -= 1
    if (left.value <= 0) refresh()
  }, 1000)
  // Écran allumé tant que la borne est ouverte (si le navigateur le permet)
  try { wakeLock = await navigator.wakeLock?.request('screen') } catch { wakeLock = null }
})
onBeforeUnmount(() => {
  clearInterval(tick)
  wakeLock?.release?.()
})
</script>
