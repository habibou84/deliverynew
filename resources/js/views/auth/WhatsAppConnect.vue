<template>
  <div class="min-h-screen bg-gradient-to-b from-emerald-800 to-emerald-600 px-5 py-10">
    <div class="mx-auto w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl space-y-5">
      <div class="text-center space-y-2">
        <p class="text-4xl" aria-hidden="true">💬</p>
        <h1 class="text-xl font-bold text-slate-900">Connecter WhatsApp</h1>
        <p v-if="session" class="text-sm text-slate-600">pour <strong>{{ session.company }}</strong></p>
      </div>

      <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

      <!-- Numéro connecté -->
      <div v-if="result" class="space-y-3 text-center">
        <p class="text-5xl" aria-hidden="true">✅</p>
        <p class="font-semibold text-slate-900">WhatsApp est connecté</p>
        <p class="text-sm text-slate-600">{{ result.verified_name }} · {{ result.display_phone }}</p>
        <p v-if="result.warning" class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ result.warning }}</p>
        <p class="text-sm text-slate-500">Les modèles de messages sont envoyés à Meta pour approbation. Cette fenêtre va se fermer.</p>
        <button type="button" class="btn-secondary w-full" @click="close">Fermer</button>
      </div>

      <!-- Avant la fenêtre de Meta -->
      <div v-else-if="session" class="space-y-4">
        <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
          <li>Connectez-vous avec votre compte Facebook.</li>
          <li>Choisissez ou créez votre entreprise et son compte WhatsApp Business.</li>
          <li>Ajoutez le numéro de l'entreprise et saisissez le code reçu par SMS.</li>
        </ol>
        <p class="text-xs text-slate-500">
          Le numéro ne doit pas être utilisé dans l'application WhatsApp d'un téléphone.
          Les messages sont facturés par Meta sur votre compte : ajoutez-y un moyen de paiement.
        </p>
        <button type="button" class="btn-success w-full py-3" :disabled="working" @click="start">
          {{ working ? 'Connexion en cours…' : 'Continuer avec Facebook' }}
        </button>
      </div>

      <p v-else-if="!error" class="text-center text-sm text-slate-500">Chargement…</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { connectWhatsApp } from '../../composables/useMetaSignup'

// Ticket remis par l'espace de l'entreprise, dans le fragment de l'adresse (jamais envoyé au serveur web)
const ticket = window.location.hash.slice(1)
if (ticket) window.history.replaceState(null, '', window.location.pathname)

const session = ref(null)
const result = ref(null)
const error = ref('')
const working = ref(false)

function notify(connected) {
  if (window.opener && session.value?.origin) {
    window.opener.postMessage({ type: 'jibiat-whatsapp', connected }, session.value.origin)
  }
}

function close() {
  window.close()
}

async function start() {
  error.value = ''
  working.value = true
  try {
    const signup = await connectWhatsApp(session.value.embedded_signup)
    const { data } = await http.post('/whatsapp-signup/complete', { ticket, ...signup })
    result.value = data.data
    notify(true)
    setTimeout(close, 4000)
  } catch (e) {
    error.value = e.isAxiosError ? apiErrorMessage(e, 'Connexion à WhatsApp impossible.') : e.message
  } finally {
    working.value = false
  }
}

onMounted(async () => {
  if (!ticket) {
    error.value = 'Ouvrez cette page depuis Paramètres > WhatsApp de votre espace.'
    return
  }
  try {
    session.value = (await http.post('/whatsapp-signup/session', { ticket })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e, 'Lien de connexion invalide.')
  }
})
</script>
