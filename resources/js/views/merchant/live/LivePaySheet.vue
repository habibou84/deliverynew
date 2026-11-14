<template>
  <!-- Au-dessus de tout l'écran (hors des pages qui glissent) -->
  <Teleport to="body">
  <div class="fixed inset-0 z-[60] flex items-end bg-slate-950/60" @click.self="emit('close')">
    <div class="max-h-[92vh] w-full overflow-y-auto rounded-t-[24px] bg-white px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-2.5" role="dialog" aria-modal="true" aria-label="Recharger mon crédit live">
      <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
      <div class="flex items-center gap-2">
        <b class="flex-1 text-[19px]">Payer mes lives</b>
        <button type="button" class="grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-lg font-black" aria-label="Fermer" @click="emit('close')">✕</button>
      </div>
      <p class="mt-1 text-[14.5px] text-slate-600">Vous payez à la fin de chaque live, selon le nombre de commandes :</p>
      <LiveTiers :tiers="offer.tiers" class="mt-2" />

      <p class="mt-4 text-[15px] font-bold">Recharger mon crédit live <span class="font-normal text-slate-600">· en ce moment {{ money(offer.credit) }}</span></p>
      <div class="mt-2 grid grid-cols-3 gap-2">
        <button v-for="a in offer.recharges" :key="a" type="button" :class="['rounded-2xl border-[2.5px] py-3 text-[17px] font-black', choice === a ? 'border-[var(--app-color)] bg-slate-50' : 'border-slate-200']" @click="choice = a">{{ money(a) }}</button>
      </div>
      <button type="button" class="mt-3 block w-full rounded-2xl bg-emerald-700 py-3.5 text-[17px] font-black text-white disabled:opacity-60" :disabled="!!busy" @click="recharge">
        {{ busy === 'credit' ? 'Ouverture du paiement…' : `Recharger ${money(choice)}` }}
      </button>

      <button v-if="Number(offer.prices?.month) > 0" type="button" class="mt-3 block w-full rounded-2xl bg-slate-50 px-4 py-3 text-left disabled:opacity-60" :disabled="!!busy" @click="monthly">
        <b class="block text-[15.5px]">📅 Ou le forfait du mois : {{ money(offer.prices.month) }}</b>
        <span class="text-[13.5px] text-slate-600">{{ busy === 'monthly' ? 'Ouverture du paiement…' : 'Lives sans limite pendant 30 jours, sans compter les commandes ›' }}</span>
      </button>
      <p class="mt-3 text-center text-[12.5px] text-slate-600">Orange Money · MTN · Moov · Wave · carte, sur la page sécurisée PayDunya.</p>
    </div>
  </div>
  </Teleport>
</template>

<script setup>
import { ref } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { money } from '../../../utils/format'
import LiveTiers from './LiveTiers.vue'

/** Crédit live (le prix de chaque live y est pris à la fin, selon ses commandes) ou forfait du mois, payés par PayDunya */
const props = defineProps({ offer: { type: Object, required: true } })
const emit = defineEmits(['close', 'offer'])
const toasts = useToastStore()
const choice = ref(props.offer.recharges?.[1] ?? props.offer.recharges?.[0] ?? 5000)
const busy = ref('')

async function pay(kind, url, body) {
  busy.value = kind
  try {
    const data = (await http.post(url, body)).data.data
    if (data.payment_url) {
      window.location.assign(data.payment_url)
      return
    }
    emit('offer', data)
    emit('close')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = ''
  }
}
const recharge = () => pay('credit', '/live/credit', { amount: choice.value })
const monthly = () => pay('monthly', '/live/subscribe')
</script>
