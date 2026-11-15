<template>
  <!-- Première configuration réussie : codes de secours montrés une seule fois -->
  <div v-if="recoveryCodes" class="space-y-4">
    <RecoveryCodes :codes="recoveryCodes" />
    <button type="button" class="w-full rounded-xl bg-[var(--app-color)] py-3 font-semibold text-white shadow-sm transition hover:brightness-110 disabled:opacity-60" :disabled="loading" @click="finish">
      J'ai noté mes codes, entrer dans la console
    </button>
  </div>

  <form v-else class="space-y-4" @submit.prevent="submit">
    <div v-if="setup" class="space-y-3">
      <p class="font-semibold text-slate-800">🔐 Configurez la connexion en deux étapes</p>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600">
        <li>Installez une application d'authentification sur votre téléphone : <b>Google Authenticator</b>, <b>Microsoft Authenticator</b>, Authy…</li>
        <li>Dans l'application, ajoutez un compte et scannez ce QR code.</li>
        <li>Saisissez le code à 6 chiffres qu'elle affiche.</li>
      </ol>
      <div class="flex justify-center">
        <img v-if="qr" :src="qr" alt="QR code à scanner avec l'application d'authentification" class="h-48 w-48 rounded-lg border border-slate-200 bg-white p-1">
      </div>
      <details class="text-sm text-slate-600">
        <summary class="cursor-pointer">Impossible de scanner ? Saisir la clé</summary>
        <p class="mt-1">Compte : <b>{{ challenge.account }}</b> · type : basé sur le temps</p>
        <p class="mt-1 select-all break-all rounded-lg bg-slate-100 px-3 py-2 text-center font-mono text-base tracking-wider text-slate-800">{{ challenge.secret }}</p>
      </details>
    </div>
    <div v-else>
      <p class="font-semibold text-slate-800">🔐 Connexion en deux étapes</p>
      <p class="text-sm text-slate-600">
        {{ recovery ? 'Saisissez un de vos codes de secours (chacun ne sert qu\'une fois).' : 'Ouvrez votre application d\'authentification et saisissez le code à 6 chiffres de « Jibiat Console ».' }}
      </p>
    </div>

    <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <label class="block">
      <span class="text-sm font-medium text-slate-700">{{ recovery ? 'Code de secours' : 'Code de l\'application' }}</span>
      <input
        ref="input"
        v-model.trim="code"
        :inputmode="recovery ? 'text' : 'numeric'"
        autocomplete="one-time-code"
        autocapitalize="characters"
        :maxlength="recovery ? 11 : 7"
        :placeholder="recovery ? 'ABCDE-12345' : '123456'"
        required
        class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-center font-mono text-2xl tracking-[0.3em] focus:border-transparent focus:outline-none focus:ring-2 focus:ring-[var(--app-color)]"
      >
    </label>

    <label class="flex items-start gap-2 text-sm text-slate-700">
      <input v-model="remember" type="checkbox" class="mt-1">
      <span>Retenir cet appareil 30 jours <span class="block text-xs text-slate-600">Seulement sur votre propre ordinateur ou téléphone.</span></span>
    </label>

    <button type="submit" :disabled="loading || !complete" class="w-full rounded-xl bg-[var(--app-color)] py-3 font-semibold text-white shadow-sm transition hover:brightness-110 disabled:opacity-60">
      {{ loading ? 'Vérification…' : (setup ? 'Activer et me connecter' : 'Valider le code') }}
    </button>
    <button v-if="!setup" type="button" class="w-full text-sm text-slate-600 hover:underline" @click="toggleRecovery">
      {{ recovery ? 'Utiliser le code de l\'application' : 'Téléphone perdu ? Utiliser un code de secours' }}
    </button>
    <button type="button" class="w-full text-sm text-slate-600 hover:underline" @click="$emit('back')">← Revenir</button>
  </form>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import QRCode from 'qrcode'
import { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import RecoveryCodes from './RecoveryCodes.vue'

/**
 * Deuxième étape de la connexion à la console : code de l'application d'authentification (ou code de secours).
 * Première connexion : QR code à scanner, puis codes de secours à noter.
 */
const props = defineProps({
  // { challenge, method: 'totp' | 'setup', expires_in, secret?, uri?, account? } renvoyé par la connexion
  challenge: { type: Object, required: true },
})
const emit = defineEmits(['back', 'done'])

const auth = useAuthStore()
const toasts = useToastStore()
const code = ref('')
const remember = ref(false)
const recovery = ref(false)
const error = ref('')
const loading = ref(false)
const input = ref(null)
const qr = ref('')
const recoveryCodes = ref(null)
let finishSession = null

const setup = computed(() => props.challenge.method === 'setup')
const complete = computed(() => (recovery.value
  ? code.value.replace(/[^A-Za-z0-9]/g, '').length === 10
  : code.value.replace(/\D/g, '').length === 6))

onMounted(async () => {
  nextTick(() => input.value?.focus())
  if (setup.value) qr.value = await QRCode.toDataURL(props.challenge.uri, { margin: 1, width: 384 }).catch(() => '')
})

function toggleRecovery() {
  recovery.value = !recovery.value
  code.value = ''
  error.value = ''
  nextTick(() => input.value?.focus())
}

async function submit() {
  error.value = ''
  loading.value = true
  try {
    const result = await auth.confirmTwoFactor({ challenge: props.challenge.challenge, code: code.value, remember: remember.value })
    if (result?.recoveryCodes) {
      recoveryCodes.value = result.recoveryCodes
      finishSession = result.finish
      return
    }
    if (result?.recoveryCodesLeft != null) {
      toasts.success(`Code de secours utilisé : il vous en reste ${result.recoveryCodesLeft}. Pensez à en créer de nouveaux dans Mon compte.`)
    }
    emit('done')
  } catch (e) {
    error.value = apiErrorMessage(e, 'Vérification impossible. Vérifiez votre réseau.')
    code.value = ''
  } finally {
    loading.value = false
  }
}

async function finish() {
  loading.value = true
  try {
    await finishSession?.()
    emit('done')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}
</script>
