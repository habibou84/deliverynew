<template>
  <div class="space-y-4">
    <!-- Un seul gros montant : ce qui va revenir au marchand -->
    <section v-if="summary" class="relative overflow-hidden rounded-[24px] bg-brand-grad p-4 text-white">
      <svg class="pointer-events-none absolute -right-8 -top-10 opacity-[.10]" width="170" height="170" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="20" fill="#fff" /></svg>
      <p class="text-[13px] font-medium opacity-90">{{ summary.unpaid_total < 0 ? 'Vous devez à l\'agence' : 'À recevoir' }}</p>
      <p class="text-[34px] font-extrabold tracking-tight leading-tight tabular-nums">{{ money(Math.abs(summary.unpaid_total)) }}</p>
      <p v-if="draft" class="text-[13px] font-semibold opacity-95">Versement de {{ money(draft.net_amount) }} en préparation</p>
      <p v-else-if="lastPaid" class="text-[13px] opacity-90">Dernier versement le {{ date(lastPaid.paid_at) }}</p>
      <!-- Répartition : chez les livreurs, à l'agence -->
      <div v-if="spread" class="mt-3 flex h-2.5 gap-[2px] overflow-hidden rounded-full bg-white/20" aria-hidden="true">
        <span v-if="spread.couriers" class="bg-[#9CC8FF]" :style="{ flex: spread.couriers }" />
        <span v-if="spread.agency" class="bg-[#FFD89A]" :style="{ flex: spread.agency }" />
      </div>
    </section>

    <!-- Comment être payé : rappel tant qu'aucun moyen n'est donné -->
    <RouterLink to="/marchand/moyens-de-paiement" class="tap flex items-center gap-3 rounded-[22px] bg-white p-3.5 shadow-soft active:scale-[.99] transition">
      <ColorIcon name="paiement" :size="44" />
      <span class="min-w-0 flex-1 text-[14.5px] font-bold">Comment être payé
        <span :class="['block text-[12.5px] font-normal', methodsCount === 0 ? 'text-amber-700' : 'text-slate-500']">{{ methodsCount === 0 ? 'Indiquez votre Wave, Orange Money, MTN, Moov ou banque' : `${methodsCount} moyen${methodsCount > 1 ? 's' : ''} de paiement` }}</span>
      </span>
      <AppIcon name="right" class="h-5 w-5 text-slate-400" />
    </RouterLink>

    <!-- Le chemin de l'argent -->
    <template v-if="summary">
      <h2 class="pt-1 text-[16px] font-bold text-slate-900">Où est votre argent</h2>
      <section class="rounded-[22px] bg-white shadow-soft px-4 divide-y divide-slate-100">
        <div v-for="row in flow" :key="row.label" class="flex items-center gap-3 py-3">
          <ColorIcon :name="row.icon" :size="42" />
          <span class="min-w-0 flex-1 text-sm">{{ row.label }}<span class="block text-[12.5px] text-slate-500">{{ row.text }}</span></span>
          <b :class="['text-base whitespace-nowrap', row.amountClass]">{{ money(row.amount) }}</b>
        </div>
      </section>
    </template>

    <!-- Les versements -->
    <h2 class="pt-1 text-[16px] font-bold text-slate-900">Mes versements</h2>
    <section v-if="payouts.length" class="rounded-[22px] bg-white shadow-soft divide-y divide-slate-100">
      <RouterLink v-for="p in payouts" :key="p.id" :to="`/marchand/paiements/${p.id}`" class="tap flex items-center gap-3 px-4 py-3 active:bg-slate-50">
        <span class="h-10 w-10 shrink-0 rounded-xl grid place-items-center text-[11.5px] font-extrabold text-white" :style="{ background: methodLook(p).color }">{{ methodLook(p).short }}</span>
        <span class="min-w-0 flex-1 text-sm">
          <b class="block first-letter:uppercase">{{ p.paid_at ? longDate(p.paid_at) : `Du ${date(p.period_start)} au ${date(p.period_end)}` }}</b>
          <span class="text-[12.5px] text-slate-500">{{ p.status === 'paid' ? (p.method_label || 'Payé') : p.status_label }}</span>
        </span>
        <b :class="['whitespace-nowrap', p.status === 'cancelled' ? 'text-slate-400 line-through' : p.net_amount < 0 ? 'text-red-600' : 'text-emerald-700']">{{ p.net_amount > 0 ? '+ ' : '' }}{{ money(p.net_amount) }}</b>
      </RouterLink>
    </section>
    <EmptyState v-else-if="loaded" art="billets" title="Aucun versement pour le moment" text="Vos versements apparaîtront ici dès que l'agence vous reversera l'argent encaissé." />

    <!-- Le détail, à la demande -->
    <button v-if="entries.length" type="button" class="m-btn-secondary !ring-0 shadow-soft" :aria-expanded="showEntries" @click="showEntries = !showEntries">
      {{ showEntries ? 'Masquer le détail des mouvements' : 'Voir le détail des mouvements' }}
    </button>
    <section v-if="showEntries" class="rounded-[22px] bg-white shadow-soft divide-y divide-slate-100">
      <div v-for="e in entries" :key="e.id" class="px-4 py-3 flex justify-between gap-3">
        <div class="min-w-0">
          <p class="font-medium text-sm">{{ e.type_label }}</p>
          <p class="text-xs text-slate-500 truncate">{{ e.order?.recipient_name || e.order?.tracking_code || e.description }} · {{ dateTime(e.created_at) }}</p>
        </div>
        <p :class="['font-semibold whitespace-nowrap text-sm', signedClass(e.amount)]">{{ e.amount > 0 ? '+' : '' }}{{ money(e.amount) }}</p>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { cachedGet } from '../../composables/useCachedApi'
import AppIcon from '../../components/mobile/AppIcon.vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { useAuthStore } from '../../stores/auth'
import { date, dateTime, money, signedClass } from '../../utils/format'

const auth = useAuthStore()
const summary = ref(null)
const entries = ref([])
const payouts = ref([])
const loaded = ref(false)
const showEntries = ref(false)
// Moyens de paiement donnés à l'agence (null : pas encore chargé)
const methodsCount = ref(null)

const longDate = (value) => new Date(value).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

// Versement préparé par l'agence mais pas encore payé
const draft = computed(() => payouts.value.find((p) => p.status === 'draft'))
const paid = computed(() => payouts.value.filter((p) => p.status === 'paid' && p.paid_at))
const lastPaid = computed(() => paid.value[0])
const thisMonth = computed(() => {
  const month = new Date().toISOString().slice(0, 7)
  return paid.value.filter((p) => String(p.paid_at).slice(0, 7) === month)
})

// Barre de l'en-tête : part chez les livreurs et part à l'agence
const spread = computed(() => {
  const couriers = Math.max(0, summary.value?.pending_cash || 0)
  const agency = Math.max(0, summary.value?.available || 0)
  return couriers + agency > 0 ? { couriers, agency } : null
})

// Pastille du moyen de paiement d'un versement (couleur et lettres, d'après son libellé)
function methodLook(p) {
  const label = (p.method_label || '').toLowerCase()
  if (label.includes('wave')) return { color: '#1DC3F0', short: 'W' }
  if (label.includes('orange')) return { color: '#FF7A00', short: 'OM' }
  if (label.includes('mtn')) return { color: '#F2B705', short: 'MTN' }
  if (label.includes('moov')) return { color: '#0A5CC2', short: 'M' }
  if (label.includes('banque') || label.includes('virement')) return { color: '#5E6AE8', short: 'B' }
  if (label.includes('esp')) return { color: '#22A06B', short: 'F' }
  return { color: '#94A3B8', short: '•' }
}

const flow = computed(() => [
  { icon: 'moto', label: 'Chez les livreurs', text: 'encaissé, pas encore rendu à l\'agence', amount: summary.value.pending_cash },
  { icon: 'boutique', label: 'À l\'agence', text: draft.value ? 'en partie dans le versement en préparation' : 'prêt pour le prochain versement', amount: summary.value.available, amountClass: summary.value.available < 0 ? 'text-red-600' : '' },
  {
    icon: 'billets',
    label: 'Versé à vous',
    text: thisMonth.value.length ? `ce mois-ci, ${thisMonth.value.length} versement${thisMonth.value.length > 1 ? 's' : ''}` : 'aucun versement ce mois-ci',
    amount: thisMonth.value.reduce((sum, p) => sum + p.net_amount, 0),
    amountClass: 'text-emerald-700',
  },
])

onMounted(async () => {
  try {
    const [ledger, list] = await Promise.all([
      cachedGet(`/finance/merchants/${auth.user.merchant_id}/ledger`),
      cachedGet('/finance/payouts'),
    ])
    summary.value = ledger.data.summary
    entries.value = ledger.data.data
    payouts.value = list.data.data
    methodsCount.value = (await cachedGet(`/merchants/${auth.user.merchant_id}/payment-methods`)).data.data.length
  } finally {
    loaded.value = true
  }
})
</script>
