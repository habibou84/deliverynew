<template>
  <div v-if="wallet" class="space-y-4">
    <p v-if="stale" class="rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">Hors ligne : montants de la dernière connexion.</p>
    <section class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-80">{{ wallet.cash_in_hand >= 0 ? 'À verser à la caisse' : 'La caisse vous doit' }}</p>
      <p class="text-4xl font-bold mt-1">{{ money(Math.abs(wallet.cash_in_hand)) }}</p>
      <p class="text-sm opacity-80">{{ wallet.collections.length }} course(s) à régler<template v-if="advanced"> · {{ money(advanced) }} de frais avancés déduits</template></p>
    </section>

    <section class="grid grid-cols-2 gap-2">
      <div class="m-card p-4">
        <p class="text-sm text-slate-500">Gagné aujourd'hui</p>
        <p class="text-2xl font-bold text-emerald-700">{{ money(wallet.earned_today) }}</p>
      </div>
      <div class="m-card p-4">
        <p class="text-sm text-slate-500">Gains à recevoir</p>
        <p :class="['text-2xl font-bold', signedClass(wallet.unpaid)]">{{ money(wallet.unpaid) }}</p>
      </div>
    </section>

    <section v-if="wallet.collections.length" class="space-y-2">
      <h2 class="font-semibold">À régler avec la caisse</h2>
      <div class="m-card divide-y">
        <div v-for="c in wallet.collections" :key="c.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ c.recipient_name || 'Client' }}</p>
            <p class="text-xs text-slate-500">{{ c.tracking_code }} · {{ c.method_label }} · {{ dateTime(c.collected_at) }}</p>
            <p v-if="c.courier_expense" class="text-xs text-indigo-700">🚌 Expédition payée : − {{ money(c.courier_expense) }}</p>
          </div>
          <p :class="['font-semibold whitespace-nowrap', c.amount_due < 0 ? 'text-emerald-700' : '']">{{ c.amount_due < 0 ? '+ ' + money(-c.amount_due) : money(c.amount_due) }}</p>
        </div>
      </div>
      <p class="text-sm text-slate-500 text-center">Remettez cet argent à la caisse de l'agence en fin de journée.</p>
    </section>

    <section v-if="wallet.recent_payouts.length" class="space-y-2">
      <h2 class="font-semibold">Mes derniers paiements</h2>
      <div class="m-card divide-y">
        <div v-for="p in wallet.recent_payouts" :key="p.reference" class="p-4 flex justify-between">
          <span class="text-sm">{{ date(p.paid_at) }} <span class="text-xs text-slate-400 font-mono">{{ p.reference }}</span></span>
          <span class="font-semibold">{{ money(p.amount) }}</span>
        </div>
      </div>
    </section>

    <EmptyState v-if="!wallet.collections.length && !wallet.recent_payouts.length" icon="💵" title="Rien à verser" text="L'argent encaissé lors de vos livraisons apparaîtra ici." />
  </div>
  <div v-else-if="error" class="text-center py-10 space-y-3">
    <p class="text-slate-600">{{ error }}</p>
    <button class="m-btn m-btn-secondary" @click="load">Réessayer</button>
  </div>
  <p v-else class="text-center text-slate-400 py-10">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { apiErrorMessage } from '../../bootstrap/axios'
import { cachedGet } from '../../composables/useCachedApi'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { date, dateTime, money, signedClass } from '../../utils/format'

const wallet = ref(null)
const advanced = computed(() => (wallet.value?.collections || []).reduce((sum, c) => sum + (c.courier_expense || 0), 0))
const stale = ref(false)
const error = ref('')

async function load() {
  error.value = ''
  try {
    const { data, stale: offline } = await cachedGet('/courier/wallet')
    wallet.value = data.data
    stale.value = offline
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger votre caisse.')
  }
}

onMounted(load)
</script>
