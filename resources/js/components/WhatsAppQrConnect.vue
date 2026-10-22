<template>
  <section v-if="qr && (qr.available || qr.active)" class="card p-4 space-y-3">
    <div class="flex items-center justify-between gap-2">
      <h2 class="font-semibold">Connexion par QR code</h2>
      <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS[qr.status][1]]">{{ STATUS[qr.status][0] }}</span>
    </div>
    <p class="text-sm text-gray-600">
      En attendant que Meta valide votre numéro, reliez-le comme WhatsApp Web : scannez le QR code depuis votre téléphone.
      Les messages partent alors de ce numéro, et vos e-commerçants peuvent créer leurs courses en vous écrivant.
    </p>
    <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
      Connexion non officielle : WhatsApp peut bloquer un numéro qui envoie beaucoup de messages automatiques. Utilisez de
      préférence un numéro dédié à l'entreprise, et gardez le téléphone allumé avec Internet. Jibiat espace les envois.
    </p>

    <!-- Numéro relié -->
    <div v-if="qr.status === 'connected'" class="space-y-2">
      <p class="text-sm">
        ✅ Relié au numéro <strong>{{ qr.phone }}</strong><span v-if="qr.name"> ({{ qr.name }})</span>
        <span v-if="qr.connected_at" class="text-gray-500"> depuis le {{ dateTime(qr.connected_at) }}</span>.
      </p>
      <button type="button" class="btn-secondary text-red-700" :disabled="busy" @click="disconnect">Déconnecter ce numéro</button>
    </div>

    <!-- QR code à scanner -->
    <div v-else-if="qr.qr" class="flex flex-wrap items-start gap-4">
      <img :src="qr.qr" alt="QR code WhatsApp à scanner" class="h-64 w-64 rounded-lg border bg-white p-2">
      <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
        <li>Ouvrez WhatsApp sur le téléphone du numéro à relier.</li>
        <li>Touchez ⋮ (ou Réglages), puis « Appareils connectés ».</li>
        <li>Touchez « Connecter un appareil » et scannez ce QR code.</li>
        <li>Le QR code change toutes les vingt secondes : il se met à jour tout seul.</li>
      </ol>
    </div>

    <div v-else class="space-y-2">
      <p v-if="qr.last_error" class="text-sm text-amber-700">{{ qr.last_error }}</p>
      <p v-if="qr.status === 'pending' && qr.service" class="text-sm text-gray-500">Préparation du QR code…</p>
      <button type="button" class="btn-success" :disabled="busy || !qr.available" @click="start">
        {{ qr.status === 'disconnected' ? 'Reconnecter par QR code' : 'Afficher le QR code' }}
      </button>
      <p v-if="!qr.available" class="text-xs text-gray-500">Le service WhatsApp n'est pas encore installé sur le serveur.</p>
    </div>
  </section>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'

/**
 * WhatsApp › Connexion par QR code (service whatsapp-bridge), en attendant la validation de Meta. Le QR code et
 * l'état se rafraîchissent toutes les trois secondes tant que le numéro n'est pas relié.
 */
const emit = defineEmits(['changed'])
const toasts = useToastStore()
const qr = ref(null)
const busy = ref(false)
let timer = null

const STATUS = {
  none: ['Non utilisée', 'bg-slate-100 text-slate-700'],
  pending: ['QR code à scanner', 'bg-amber-100 text-amber-800'],
  connected: ['Connecté', 'bg-emerald-100 text-emerald-800'],
  disconnected: ['Déconnecté', 'bg-red-100 text-red-800'],
}

function apply(data) {
  const wasConnected = qr.value?.status === 'connected'
  qr.value = data
  if (data.status === 'connected' && !wasConnected && timer) {
    toasts.success('WhatsApp relié : les messages partent maintenant de ce numéro.')
    emit('changed')
  }
  // Suivi du scan : seulement pendant l'affichage du QR code
  if (data.status === 'pending' && !timer) timer = setInterval(refresh, 3000)
  if (data.status !== 'pending' && timer) {
    clearInterval(timer)
    timer = null
  }
}

async function refresh() {
  try {
    apply((await http.get('/whatsapp/qr')).data.data)
  } catch {
    // réseau passager : nouvel essai au prochain tour
  }
}

async function start() {
  busy.value = true
  try {
    apply((await http.post('/whatsapp/qr')).data.data)
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function disconnect() {
  if (!window.confirm('Déconnecter ce numéro ? Les messages repasseront en simulation jusqu\'à une nouvelle connexion.')) return
  busy.value = true
  try {
    apply((await http.delete('/whatsapp/qr')).data.data)
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(refresh)
onUnmounted(() => timer && clearInterval(timer))
</script>
