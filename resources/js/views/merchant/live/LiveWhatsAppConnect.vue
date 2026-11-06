<template>
  <section v-if="state.auto || state.official?.available || state.available" :class="['m-card space-y-3 p-4', state.auto ? 'border-2 border-emerald-600' : 'border-2 border-[#25d366]']">
    <!-- Relié (connexion officielle, ou code d'association / QR code) -->
    <template v-if="state.auto">
      <p class="flex items-center gap-2 text-[17px] font-extrabold text-emerald-800">
        <span class="grid h-9 w-9 place-items-center rounded-full bg-[#25d366] text-white" aria-hidden="true">✓</span>
        Commandes WhatsApp automatiques
      </p>
      <p class="text-[15px] text-slate-700">Votre WhatsApp <b>{{ dottedPhone(state.mode === 'official' ? state.official.phone : state.phone) }}</b> est relié{{ state.mode === 'official' ? ' (connexion officielle de WhatsApp)' : '' }}. Pendant le live, les clientes envoient le code : la réponse part toute seule et votre agence reçoit la commande. <b>Vous n'avez rien à faire.</b></p>
      <button v-if="!compact" type="button" class="text-[14px] font-semibold text-slate-500 underline" @click="logout">Délier ce WhatsApp</button>
    </template>

    <!-- À relier -->
    <template v-else>
      <p class="text-[17px] font-extrabold">💬 Les commandes arrivent toutes seules</p>
      <p class="text-[15px] text-slate-700">Reliez votre numéro spécial live une fois : les clientes envoient le code, la réponse part toute seule et votre agence reçoit la commande.</p>

      <!-- Par défaut : connexion officielle de WhatsApp (fenêtre Facebook), l'application reste sur le téléphone -->
      <template v-if="state.official?.available">
        <button type="button" class="m-btn-primary w-full !bg-none !bg-[#25d366]" :disabled="busy" @click="connectOfficial">{{ busy ? 'Un instant…' : 'Relier mon numéro spécial live' }}</button>
        <p class="text-[13px] text-slate-500">Connexion officielle de WhatsApp, avec votre compte Facebook.</p>
      </template>

      <!-- Option de la console : code d'association ou QR code (non officiel), à côté de la connexion officielle -->
      <component :is="state.official?.available ? 'details' : 'div'" v-if="state.available && !compact" class="space-y-3" :class="state.official?.available ? 'rounded-2xl bg-slate-50 p-3' : ''">
        <summary v-if="state.official?.available" class="cursor-pointer text-[15px] font-semibold text-slate-700">Ou relier par code d'association</summary>
        <p class="rounded-xl bg-amber-50 px-3 py-2 text-[13px] text-amber-900">⚠️ Connexion non officielle : WhatsApp peut suspendre un numéro qui répond automatiquement.<template v-if="state.official?.available"> La connexion officielle est plus sûre.</template></p>

        <button v-if="!open" type="button" class="m-btn-primary w-full !bg-none !bg-[#25d366]" @click="open = true">Relier par code</button>

        <div v-else class="space-y-3">
          <template v-if="!code && !qr">
            <label class="block text-[14px] font-semibold text-slate-600">Le numéro WhatsApp de votre boutique
              <input v-model="phone" type="tel" inputmode="tel" class="m-input mt-1 w-full" placeholder="07 00 00 00 00">
            </label>
            <button type="button" class="m-btn-primary w-full !bg-none !bg-[#25d366]" :disabled="busy || !phone" @click="connect">{{ busy ? 'Un instant…' : 'Recevoir mon code' }}</button>
          </template>

          <!-- Code d'association : tout se fait sur le même téléphone -->
          <div v-if="code" class="rounded-2xl bg-emerald-50 p-4">
            <p class="text-[14px] font-bold text-emerald-800">VOTRE CODE</p>
            <p class="my-1 select-all text-center font-mono text-[34px] font-extrabold tracking-[.2em] text-emerald-950">{{ code }}</p>
            <ol class="list-decimal space-y-1 pl-5 text-[15px] text-slate-800">
              <li>Ouvrez <b>WhatsApp</b>.</li>
              <li>Touchez <b>⋮</b> (ou <b>Réglages</b>), puis <b>Appareils connectés</b>.</li>
              <li>Touchez <b>Connecter un appareil</b>, puis <b>Associer avec un numéro de téléphone</b>.</li>
              <li>Tapez ce code.</li>
            </ol>
            <button type="button" class="mt-2 w-full rounded-xl bg-white py-2 text-[15px] font-bold text-emerald-800 ring-1 ring-emerald-300" @click="copy">Copier le code</button>
          </div>

          <!-- QR code : depuis un autre écran -->
          <details v-if="qr" :open="!code" class="rounded-2xl bg-slate-50 p-3">
            <summary class="cursor-pointer text-[15px] font-semibold">Vous avez un autre écran ? Scannez le QR code</summary>
            <img :src="qr" alt="QR code à scanner avec WhatsApp" class="mx-auto mt-2 w-56 rounded-xl bg-white p-2">
            <p class="mt-1 text-center text-[13px] text-slate-500">WhatsApp › Appareils connectés › Connecter un appareil</p>
          </details>

          <p v-if="code || qr" class="flex items-center justify-center gap-2 text-[14px] text-slate-500"><i class="block h-2 w-2 animate-pulse rounded-full bg-[#25d366]" /> En attente de WhatsApp…</p>
          <p class="text-[13px] text-slate-500">Vous gardez votre WhatsApp comme d'habitude. Seuls les codes du live reçoivent une réponse automatique, pendant le live.</p>
        </div>
      </component>
    </template>
  </section>
</template>

<script setup>
import { onBeforeUnmount, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { dottedPhone } from './liveCard'
import { confirmDialog } from '../../../composables/useDialog'

/**
 * Commandes du live reçues et enregistrées sans l'e-commerçante : son numéro WhatsApp Business relié par la
 * connexion officielle de WhatsApp (par défaut), ou, si la console le propose, par code d'association / QR code.
 */
const props = defineProps({
  initial: { type: Object, default: () => ({ mode: 'none', auto: false, official: { available: false }, available: false, connected: false, phone: null }) },
  defaultPhone: { type: String, default: '' },
  compact: Boolean,
})
const emit = defineEmits(['connected'])
const toasts = useToastStore()
const state = reactive({ ...props.initial })
const open = ref(false)
const phone = ref((props.defaultPhone || '').replace(/^\+225/, ''))
const code = ref(null)
const qr = ref(null)
const busy = ref(false)
let timer = null

async function connect() {
  busy.value = true
  try {
    const data = (await http.post('/live/whatsapp/connect', { phone: phone.value })).data.data
    code.value = data.pairing_code
    qr.value = data.qr
    if (data.connected) return done(data)
    clearInterval(timer)
    timer = setInterval(poll, 3000)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

/** Connexion officielle : fenêtre de Meta ouverte depuis l'adresse de la plateforme, retour ici à la fin */
async function connectOfficial() {
  busy.value = true
  try {
    window.location.assign((await http.post('/live/whatsapp/official')).data.data.url)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    busy.value = false
  }
}

async function poll() {
  try {
    const data = (await http.get('/live/whatsapp')).data.data
    if (data.qr) qr.value = data.qr
    if (data.connected) done(data)
  } catch {
    // on réessaie au prochain tour
  }
}

function done(data) {
  clearInterval(timer)
  Object.assign(state, data)
  open.value = false
  code.value = null
  qr.value = null
  toasts.success('WhatsApp relié : les commandes arriveront toutes seules.')
  emit('connected', data)
}

async function logout() {
  if (!await confirmDialog('Délier ce WhatsApp ? Les commandes du live ne seront plus automatiques.')) return
  try {
    const data = state.mode === 'official'
      ? (await http.delete('/live/whatsapp/official')).data.data
      : (await http.post('/live/whatsapp/logout')).data.data
    Object.assign(state, data)
    emit('connected', state)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(code.value)
    toasts.success('Code copié.')
  } catch {
    // copie impossible : le code reste affiché
  }
}

// Retour de la fenêtre de Meta (#whatsapp=connecte, annule ou erreur)
const back = new URLSearchParams(window.location.hash.slice(1))
if (back.has('whatsapp') && !props.compact) {
  window.history.replaceState(null, '', window.location.pathname + window.location.search)
  if (back.get('whatsapp') === 'connecte') {
    toasts.success('WhatsApp relié : les commandes arriveront toutes seules.')
    emit('connected', state)
  } else if (back.get('whatsapp') === 'erreur') {
    toasts.error(back.get('message') || 'WhatsApp n\'a pas pu être relié.')
  }
}

onBeforeUnmount(() => clearInterval(timer))
</script>
