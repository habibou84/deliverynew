<template>
  <div class="min-h-screen bg-gradient-to-b from-emerald-800 to-emerald-600 px-5 py-10">
    <div class="mx-auto w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl space-y-5">
      <div class="text-center space-y-2">
        <p class="text-4xl" aria-hidden="true">💬</p>
        <h1 class="text-xl font-bold text-slate-900">Connecter WhatsApp</h1>
        <p v-if="session" class="text-sm text-slate-600">pour <strong>{{ session.company }}</strong></p>
      </div>

      <!-- Retour de Facebook (page entière) : connexion en cours, en grand -->
      <div v-if="finishing" class="flex items-center gap-3 rounded-2xl bg-sky-50 p-4 text-sky-950" role="status">
        <span class="h-7 w-7 shrink-0 animate-spin rounded-full border-[3px] border-sky-200 border-t-sky-700" aria-hidden="true" />
        <span class="text-[16px] font-bold">Connexion de votre WhatsApp en cours…<span class="block text-sm font-normal">Quelques secondes : ne fermez pas la page.</span></span>
      </div>

      <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

      <!-- Navigateur intégré (WhatsApp, Facebook, Instagram, TikTok) : la connexion y échoue souvent -->
      <div v-if="inApp && session && !result && !finishing" class="space-y-2 rounded-2xl bg-amber-50 p-4 text-amber-950 ring-1 ring-amber-200">
        <p class="text-[16px] font-bold">Ouvrez cette page dans Chrome ou Safari</p>
        <p class="text-sm">Dans WhatsApp, Facebook ou Instagram, la connexion n'aboutit souvent pas. Menu ⋮ (ou ⋯) › « Ouvrir dans le navigateur », ou copiez le lien et collez-le dans Chrome.</p>
        <button type="button" class="w-full rounded-xl bg-white py-2.5 text-sm font-bold text-amber-900 ring-1 ring-amber-300" @click="copyLink">{{ copied ? 'Lien copié ✓' : 'Copier le lien' }}</button>
      </div>

      <!-- Numéro connecté : retour automatique dans l'espace de l'entreprise -->
      <div v-if="result" class="space-y-3 text-center">
        <p class="text-5xl" aria-hidden="true">✅</p>
        <p class="font-semibold text-slate-900">WhatsApp est connecté</p>
        <p class="text-sm text-slate-600">{{ result.verified_name }} · {{ result.display_phone }}</p>
        <p v-if="result.warning" class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ result.warning }}</p>
        <p class="text-sm text-slate-500">{{ session.merchant ? 'Les commandes de vos lives arriveront sur ce numéro. Retour au live…' : 'Les modèles de messages sont envoyés à Meta pour approbation. Retour à votre espace…' }}</p>
        <button type="button" class="btn-success w-full py-3" @click="goBack({ whatsapp: 'connecte' })">Revenir à mon espace</button>
      </div>

      <!-- Avant la fenêtre de Meta -->
      <!-- E-commerçant : numéro spécial live, sans compte WhatsApp -->
      <div v-else-if="session?.merchant && !finishing" class="space-y-4">
        <!-- Deux consignes, en grand (comme Jibiat Marchand) -->
        <div class="space-y-3">
          <div class="flex gap-3 rounded-2xl bg-slate-50 p-3.5">
            <span class="text-[26px] leading-none" aria-hidden="true">📱</span>
            <p class="text-[17px] font-bold leading-snug text-slate-900">Un numéro spécial live, connecté à aucun WhatsApp<span class="block text-sm font-normal text-slate-600">Vous recevrez un code par SMS ou par appel.</span></p>
          </div>
          <div class="flex gap-3 rounded-2xl bg-slate-50 p-3.5">
            <span class="text-[26px] leading-none" aria-hidden="true">💳</span>
            <p class="text-[17px] font-bold leading-snug text-slate-900">Pas besoin de carte bancaire<span class="block text-sm font-normal text-slate-600">Si Meta en demande une, touchez « Terminer » : les commandes du live sont gratuites.</span></p>
          </div>
        </div>
        <button type="button" class="btn-success w-full py-3" :disabled="working" @click="start">
          {{ working ? 'Fenêtre Facebook ouverte…' : (error ? 'Recommencer avec Facebook' : 'Continuer avec Facebook') }}
        </button>
        <button type="button" class="btn-secondary w-full" :disabled="working" @click="cancel">Revenir à mon espace</button>
      </div>

      <div v-else-if="session && !finishing" class="space-y-4">
        <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
          <li>Une fenêtre Facebook s'ouvre : connectez-vous avec votre compte.</li>
          <li>Choisissez ou créez votre entreprise et son compte WhatsApp Business.</li>
          <li>Ajoutez le numéro de l'entreprise et saisissez le code reçu par SMS.</li>
        </ol>
        <p class="text-xs text-slate-500">
          Le numéro ne doit pas être utilisé dans l'application WhatsApp d'un téléphone.
          Les messages sont facturés par Meta sur votre compte : ajoutez-y un moyen de paiement.
          Vous revenez ensuite automatiquement dans votre espace.
        </p>
        <button type="button" class="btn-success w-full py-3" :disabled="working" @click="start">
          {{ working ? 'Fenêtre Facebook ouverte…' : (error ? 'Recommencer avec Facebook' : 'Continuer avec Facebook') }}
        </button>
        <button type="button" class="btn-secondary w-full" :disabled="working" @click="cancel">Revenir à mon espace</button>
      </div>

      <p v-else-if="!error && !finishing" class="text-center text-sm text-slate-500">Chargement…</p>
      <!-- Lien invalide ou expiré : on ne connaît pas l'espace, on revient à la page précédente -->
      <button v-else-if="!finishing" type="button" class="btn-secondary w-full" @click="historyBack">Revenir à mon espace</button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { connectWhatsApp, facebookDialogUrl, inAppBrowser, prefersFullPage } from '../../composables/useMetaSignup'

// Ticket remis par l'espace de l'entreprise, dans le fragment de l'adresse (jamais envoyé au serveur web).
// La page s'ouvre dans le même onglet que l'espace et y revient à la fin, avec le résultat.
// Retour de Facebook en page entière (téléphone) : ?code=…&state={ticket}, ou ?error=…&state={ticket}.
const query = new URLSearchParams(window.location.search)
const returned = query.has('state') ? { code: query.get('code'), error: query.get('error'), reason: query.get('error_reason') } : null
const ticket = returned ? query.get('state') : window.location.hash.slice(1)
if (ticket || returned) window.history.replaceState(null, '', window.location.pathname)

const session = ref(null)
const result = ref(null)
const error = ref('')
const working = ref(false)
const finishing = ref(!!returned?.code)
const inApp = inAppBrowser()
const copied = ref(false)

// Page de retour dans l'espace (adresse lue dans le ticket chiffré) : WhatsApp de l'entreprise, ou live de l'e-commerçant
function goBack(params) {
  window.location.assign(`${session.value.origin}${session.value.return || '/admin/whatsapp'}#${new URLSearchParams(params)}`)
}

function historyBack() {
  if (window.history.length > 1) window.history.back()
  else window.location.assign('/admin')
}

function cancel() {
  goBack(error.value ? { whatsapp: 'erreur', message: error.value } : { whatsapp: 'annule' })
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(`${session.value.embedded_signup.return_url}#${ticket}`)
    copied.value = true
  } catch {
    copied.value = false
  }
}

async function finish(signup) {
  const { data } = await http.post('/whatsapp-signup/complete', { ticket, ...signup })
  result.value = data.data
  setTimeout(() => goBack({ whatsapp: 'connecte' }), result.value.warning ? 4000 : 1500)
}

async function start() {
  error.value = ''
  const es = session.value.embedded_signup
  // Téléphone : Facebook en page entière, retour ici avec le code
  if (prefersFullPage()) {
    working.value = true
    window.location.assign(facebookDialogUrl(es, es.return_url, ticket))
    return
  }
  working.value = true
  try {
    await finish(await connectWhatsApp(es, { coexistence: false }))
  } catch (e) {
    error.value = e.isAxiosError ? apiErrorMessage(e, 'Connexion à WhatsApp impossible.') : e.message
  } finally {
    working.value = false
  }
}

onMounted(async () => {
  if (!ticket) {
    error.value = 'Ouvrez cette page depuis Réglages › Messages aux clients de votre espace.'
    return
  }
  try {
    session.value = (await http.post('/whatsapp-signup/session', { ticket })).data.data
  } catch (e) {
    finishing.value = false
    error.value = apiErrorMessage(e, 'Lien de connexion invalide.')
    return
  }
  if (returned?.code) {
    try {
      await finish({ code: returned.code, redirect_uri: session.value.embedded_signup.return_url })
    } catch (e) {
      error.value = apiErrorMessage(e, 'Connexion à WhatsApp impossible.')
    } finally {
      finishing.value = false
    }
  } else if (returned) {
    error.value = returned.reason === 'user_denied' ? 'Connexion à Facebook annulée.' : 'Facebook n\'a pas terminé la connexion. Recommencez quand vous voulez.'
  }
})
</script>
