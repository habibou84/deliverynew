<template>
  <div class="space-y-4 px-4 py-6">
    <section class="m-card space-y-3 p-5 text-center">
      <template v-if="!p || p.status === 'pending'">
        <p class="text-[44px]" aria-hidden="true">⏳</p>
        <h1 class="text-[20px] font-extrabold">Paiement en attente</h1>
        <p class="text-[15px] text-slate-600">Validez le paiement sur votre téléphone (Orange Money, MTN, Moov, Wave ou carte). Cette page se met à jour toute seule.</p>
        <p class="flex items-center justify-center gap-2 text-[14px] text-slate-600"><i class="block h-2 w-2 animate-pulse rounded-full bg-emerald-500" /> Vérification auprès de PayDunya…</p>
      </template>
      <template v-else-if="p.status === 'paid'">
        <p class="text-[44px]" aria-hidden="true">✅</p>
        <h1 class="text-[20px] font-extrabold">Paiement reçu : {{ money(p.amount) }}</h1>
        <p class="text-[15px] text-slate-600">{{ done }}</p>
        <p v-if="p.error" class="rounded-xl bg-amber-50 px-3 py-2 text-[14px] text-amber-900">{{ p.error }} Contactez Jibiat.</p>
      </template>
      <template v-else>
        <p class="text-[44px]" aria-hidden="true">😕</p>
        <h1 class="text-[20px] font-extrabold">{{ p.status === 'cancelled' ? 'Paiement annulé' : 'Paiement non abouti' }}</h1>
        <p class="text-[15px] text-slate-600">Rien n'a été prélevé. Vous pouvez recommencer quand vous voulez.</p>
      </template>
    </section>
    <button type="button" class="m-btn-primary w-full" @click="next">{{ nextLabel }}</button>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { money } from '../../../utils/format'

/** Retour de la page de paiement de PayDunya : état relu chez PayDunya jusqu'au paiement (ou à l'abandon) */
const route = useRoute()
const router = useRouter()
const toasts = useToastStore()
const p = ref(null)
let timer = null
let tries = 0

const done = computed(() => ({
  live: 'Votre live est ouvert. Bon live !',
  credit: 'Votre crédit live est rechargé. Vous pouvez démarrer votre live.',
  monthly: 'Votre forfait du mois est activé : lives sans limite pendant 30 jours.',
  pack: 'Vos fiches produit IA sont ajoutées.',
})[p.value?.kind] || 'Merci !')
const goesToLive = computed(() => p.value?.status === 'paid' && p.value.kind === 'live' && p.value.live_status === 'live')
const nextLabel = computed(() => (goesToLive.value ? 'Aller à mon live' : 'Revenir à mon live'))

async function load() {
  try {
    p.value = (await http.get(`/live/payments/${route.params.id}`)).data.data
  } catch (e) {
    clearInterval(timer)
    toasts.error(apiErrorMessage(e))
    return
  }
  tries += 1
  // PayDunya confirme en quelques secondes ; au-delà de 5 minutes, on arrête de vérifier
  if (p.value.status !== 'pending' || tries > 100) clearInterval(timer)
  if (goesToLive.value) router.replace(`/marchand/live/${p.value.live_id}`)
}

function next() {
  router.push(goesToLive.value ? `/marchand/live/${p.value.live_id}` : '/marchand/live/preparer')
}

onMounted(() => {
  load()
  timer = setInterval(load, 3000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
