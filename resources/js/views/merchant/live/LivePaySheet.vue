<template>
  <!-- Au-dessus de tout l'écran (hors des pages qui glissent) -->
  <Teleport to="body">
  <div class="fixed inset-0 z-[60] flex items-end bg-slate-950/60" @click.self="emit('close')">
    <div class="max-h-[92vh] w-full overflow-y-auto rounded-t-[24px] bg-white px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-2.5" role="dialog" aria-modal="true" aria-label="Recharger mes commandes en live">
      <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
      <div class="flex items-center gap-2">
        <b class="flex-1 text-[19px]">Recharger mes commandes</b>
        <button type="button" class="grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-lg font-black" aria-label="Fermer" @click="emit('close')">✕</button>
      </div>
      <p class="mt-1 text-[14.5px] text-slate-600">Chaque commande reçue en live est retirée de votre solde, à la fin du live. Il vous reste <b :class="offer.orders_left < 0 ? 'text-red-700' : 'text-slate-900'">{{ offer.orders_left }} commande{{ Math.abs(offer.orders_left) > 1 ? 's' : '' }}</b>.</p>

      <LivePacks v-model="choice" :packs="offer.packs" class="mt-3" />
      <button type="button" class="mt-3 block w-full rounded-2xl bg-emerald-700 py-3.5 text-[17px] font-black text-white disabled:opacity-60" :disabled="!!busy || !chosen" @click="buy">
        {{ busy === 'orders' ? 'Ouverture du paiement…' : chosen ? `Acheter ${chosen.orders} commandes · ${money(chosen.price)}` : 'Choisissez un pack' }}
      </button>

      <button v-if="Number(offer.prices?.month) > 0" type="button" class="mt-3 block w-full rounded-2xl bg-slate-50 px-4 py-3 text-left disabled:opacity-60" :disabled="!!busy" @click="monthly">
        <b class="block text-[15.5px]">♾️ Ou l'illimité 30 jours : {{ money(offer.prices.month) }}</b>
        <span class="text-[13.5px] text-slate-600">{{ busy === 'monthly' ? 'Ouverture du paiement…' : `Lives sans compter les commandes pendant 30 jours${offer.prices.cards_per_month ? `, ${offer.prices.cards_per_month} fiches IA incluses` : ''} ›` }}</span>
      </button>
      <p class="mt-3 text-center text-[12.5px] text-slate-600">{{ offer.pay_online ? 'Orange Money · MTN · Moov · Wave · carte, sur la page sécurisée PayDunya.' : 'Retenu sur votre prochain reversement : rien à payer maintenant.' }}</p>
    </div>
  </div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { money } from '../../../utils/format'
import LivePacks from './LivePacks.vue'

/** Comme Jibiat Marchand : un pack de commandes en live (fiches IA incluses) ou l'illimité 30 jours, payés par PayDunya */
const props = defineProps({ offer: { type: Object, required: true } })
const emit = defineEmits(['close', 'offer'])
const toasts = useToastStore()
const choice = ref(props.offer.packs?.find((p) => p.advised)?.index ?? props.offer.packs?.[0]?.index ?? 1)
const chosen = computed(() => props.offer.packs?.find((p) => p.index === choice.value))
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
    toasts.success(kind === 'monthly' ? 'Illimité 30 jours activé.' : 'Commandes ajoutées à votre solde.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = ''
  }
}
const buy = () => pay('orders', '/live/orders', { pack: choice.value })
const monthly = () => pay('monthly', '/live/subscribe')
</script>
