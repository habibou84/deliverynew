<template>
  <div class="space-y-4">
    <!-- Étape 2 : message WhatsApp à envoyer (ou code SMS en dernier recours) -->
    <PhoneVerificationStep v-if="pending" :token="pending.token" :initial="pending.data" :tone="tone" @update="remember" @done="finish" @restart="restart" />

    <!-- Étape 1 : numéro du compte -->
    <form v-else class="space-y-4" @submit.prevent="start">
      <div class="text-center space-y-1">
        <p class="text-3xl" aria-hidden="true">💬</p>
        <h2 class="text-xl font-bold">Me connecter par WhatsApp</h2>
        <p class="text-sm text-slate-500">Sans mot de passe : vous envoyez un message WhatsApp déjà écrit, et vous êtes connecté.</p>
      </div>
      <div v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2 space-y-2" role="alert">
        <p>{{ error }}</p>
        <RouterLink v-if="otherSpace" :to="otherSpace.to" class="inline-block rounded-lg bg-white px-3 py-1.5 font-semibold text-red-800 ring-1 ring-red-200">{{ otherSpace.label }} →</RouterLink>
      </div>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Numéro WhatsApp de votre compte</span>
        <input v-model.trim="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="07 00 00 00 00"
          class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-[var(--app-color)] focus:border-transparent">
      </label>
      <button type="submit" :disabled="busy" class="w-full rounded-xl bg-[#25D366] hover:bg-[#1ebe5b] py-3 font-semibold text-white shadow-sm disabled:opacity-60">
        {{ busy ? 'Un instant…' : 'Continuer' }}
      </button>
    </form>

    <button type="button" class="block mx-auto text-sm text-slate-600 underline" @click="back">Me connecter avec mon mot de passe</button>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { currentSpace, LOGIN_PAGES, SERVER_SPACES, SPACE_FROM_SERVER } from '../../bootstrap/session'
import { clearPending, readPending, savePending } from '../../composables/usePendingVerification'
import { appLock } from '../../native'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import PhoneVerificationStep from './PhoneVerificationStep.vue'

defineProps({ tone: { type: String, default: 'merchant' } })
const emit = defineEmits(['back', 'done'])

const SPACE_LABELS = { marchand: 'Aller à l\'espace e-commerçant', livreur: 'Aller à l\'espace livreur', admin: 'Aller à l\'espace équipe' }

const auth = useAuthStore()
const toasts = useToastStore()
const pending = ref(readPending('login'))
const phone = ref('')
const busy = ref(false)
const error = ref('')
const otherSpace = ref(null)

async function start() {
  busy.value = true
  error.value = ''
  otherSpace.value = null
  try {
    const { data } = await http.post('/auth/whatsapp', {
      phone: phone.value,
      device_name: navigator.userAgent.slice(0, 100),
      // Seuls les comptes de l'espace de cette page de connexion y entrent (contrôlé par le serveur)
      space: SERVER_SPACES[currentSpace()],
    })
    pending.value = { token: data.token, data: data.data }
    savePending('login', pending.value)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Connexion impossible pour le moment.')
    const space = SPACE_FROM_SERVER[e.response?.data?.space]
    if (space && !appLock()) otherSpace.value = { to: LOGIN_PAGES[space], label: SPACE_LABELS[space] }
  } finally {
    busy.value = false
  }
}

function remember(data) {
  pending.value = { ...pending.value, data }
  savePending('login', pending.value)
}

async function finish(response) {
  clearPending('login')
  if (!response.session) {
    // Session déjà ouverte depuis un autre onglet ou appareil
    restart()
    error.value = 'Cette connexion a déjà été utilisée. Recommencez.'
    return
  }
  try {
    await auth.adoptSession(response.session)
    toasts.success('Vous êtes connecté.')
    emit('done')
  } catch (e) {
    restart()
    error.value = apiErrorMessage(e, 'Connexion impossible.')
  }
}

function restart() {
  clearPending('login')
  pending.value = null
  error.value = ''
}

function back() {
  restart()
  emit('back')
}
</script>
