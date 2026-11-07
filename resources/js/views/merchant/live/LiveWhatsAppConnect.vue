<template>
  <!-- Relié : rien d'autre à faire -->
  <section v-if="state.auto" class="space-y-3">
    <div class="rounded-[22px] bg-emerald-50 p-5 text-center">
      <p class="text-[40px] leading-none" aria-hidden="true">✅</p>
      <p class="mt-2 text-[18px] font-extrabold">Votre WhatsApp est relié</p>
      <p class="mt-1 text-[22px] font-black tracking-wide tabular-nums">{{ dottedPhone(state.mode === 'official' ? state.official.phone : state.phone) }}</p>
      <p class="mt-1.5 text-[15px] text-emerald-800">Pendant le live, les commandes arrivent toutes seules. Vous n'avez rien à faire.</p>
    </div>
    <button v-if="!compact" type="button" class="m-btn-secondary w-full !ring-0 shadow-soft" @click="$emit('next')">Étape suivante : vos produits ›</button>
    <button v-if="!compact" type="button" class="block w-full text-center text-[14px] font-semibold text-slate-500" @click="logout">Changer de numéro</button>
  </section>

  <!-- À relier : 3 étapes courtes, un seul bouton (connexion officielle de WhatsApp, par Facebook) -->
  <section v-else-if="state.official?.available" class="space-y-3">
    <ol class="m-card divide-y divide-slate-100 px-4">
      <li v-for="(st, i) in STEPS" :key="st.title" class="flex items-start gap-3 py-3">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[var(--app-color)] text-[15px] font-black text-white">{{ i + 1 }}</span>
        <span class="min-w-0"><b class="block text-[15.5px]">{{ st.title }}</b><span class="text-[14px] text-slate-500">{{ st.text }}</span></span>
      </li>
    </ol>
    <button type="button" class="m-btn-primary w-full !bg-none !bg-[#25d366]" :disabled="busy" @click="connectOfficial">{{ busy ? 'Un instant…' : 'Relier mon numéro spécial' }}</button>
  </section>
</template>

<script setup>
import { reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { dottedPhone } from './liveCard'
import { confirmDialog } from '../../../composables/useDialog'

/**
 * Commandes du live reçues et enregistrées sans l'e-commerçante : son numéro spécial live relié par la connexion
 * officielle de WhatsApp (Facebook). Un numéro déjà relié autrement (code d'association) reste affiché et peut être délié.
 */
const props = defineProps({
  initial: { type: Object, default: () => ({ mode: 'none', auto: false, official: { available: false }, available: false, connected: false, phone: null }) },
  defaultPhone: { type: String, default: '' },
  compact: Boolean,
})
const emit = defineEmits(['connected', 'next'])
const toasts = useToastStore()
const state = reactive({ ...props.initial })
const busy = ref(false)
// Les 3 étapes, en bref (comme Jibiat Marchand)
const STEPS = [
  { title: '📱 Un numéro spécial live', text: 'Une puce sans compte WhatsApp. Vous recevrez un code par SMS.' },
  { title: 'Touchez « Relier » ci-dessous', text: 'Suivez les étapes de Facebook avec ce numéro.' },
  { title: '💳 Meta demande une carte ?', text: 'Touchez « Terminer » ou « Plus tard ». Pas besoin de carte : les messages des commandes sont gratuits.' },
]

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

</script>
