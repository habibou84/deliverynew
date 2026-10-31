<template>
  <form class="space-y-4" @submit.prevent="submit">
    <div>
      <p class="font-semibold text-slate-800">🔐 Code de connexion</p>
      <p v-if="challenge.channel === 'server'" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        Le code n'a pas pu être envoyé (e-mail et WhatsApp indisponibles). Pour le moment, la connexion reste bloquée :
        la personne qui a accès au serveur obtient le code avec la commande <b>php artisan console:code</b> suivie de votre identifiant.
      </p>
      <p v-else class="text-sm text-slate-600">
        {{ challenge.channel === 'whatsapp' ? 'Un code à 6 chiffres vient d\'être envoyé par WhatsApp au' : 'Un code à 6 chiffres vient d\'être envoyé à' }}
        <b>{{ challenge.to }}</b>. Il est valable 10 minutes.
      </p>
      <p v-if="challenge.channel === 'mail'" class="text-xs text-slate-500">Regardez aussi dans les spams.</p>
    </div>

    <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <label class="block">
      <span class="text-sm font-medium text-slate-700">Code reçu</span>
      <input
        ref="input"
        v-model.trim="code"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="7"
        placeholder="123456"
        required
        class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-center font-mono text-2xl tracking-[0.4em] focus:border-transparent focus:outline-none focus:ring-2 focus:ring-[var(--app-color)]"
      >
    </label>

    <label class="flex items-start gap-2 text-sm text-slate-700">
      <input v-model="remember" type="checkbox" class="mt-1">
      <span>Retenir cet appareil 30 jours <span class="block text-xs text-slate-500">Seulement sur votre propre ordinateur ou téléphone.</span></span>
    </label>

    <button type="submit" :disabled="loading || code.replace(/\D/g, '').length < 6" class="w-full rounded-xl bg-[var(--app-color)] py-3 font-semibold text-white shadow-sm transition hover:brightness-110 disabled:opacity-60">
      {{ loading ? 'Vérification…' : 'Valider le code' }}
    </button>
    <button type="button" class="w-full text-sm text-slate-600 hover:underline" @click="$emit('back')">← Revenir (renvoyer un code)</button>
  </form>
</template>

<script setup>
import { nextTick, onMounted, ref } from 'vue'
import { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'

/**
 * Deuxième étape de la connexion à la console : le code envoyé par e-mail (ou WhatsApp en secours).
 */
const props = defineProps({
  // { challenge, channel, to, expires_in } renvoyé par la connexion
  challenge: { type: Object, required: true },
})
const emit = defineEmits(['back', 'done'])

const auth = useAuthStore()
const code = ref('')
const remember = ref(false)
const error = ref('')
const loading = ref(false)
const input = ref(null)

onMounted(() => nextTick(() => input.value?.focus()))

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.confirmTwoFactor({ challenge: props.challenge.challenge, code: code.value, remember: remember.value })
    emit('done')
  } catch (e) {
    error.value = apiErrorMessage(e, 'Vérification impossible. Vérifiez votre réseau.')
    code.value = ''
  } finally {
    loading.value = false
  }
}
</script>
