<template>
  <div class="space-y-4">
    <!-- Un seul gros montant : ce qui va revenir au marchand -->
    <section v-if="summary">
      <p class="text-sm font-semibold text-slate-500">{{ summary.unpaid_total < 0 ? 'Vous devez à l\'agence' : 'À recevoir' }}</p>
      <p class="text-[38px] font-extrabold tracking-tight leading-tight">{{ money(Math.abs(summary.unpaid_total)) }}</p>
      <span v-if="draft" class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-sm font-bold text-emerald-800">Versement de {{ money(draft.net_amount) }} en préparation</span>
      <span v-else-if="lastPaid" class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">Dernier versement le {{ date(lastPaid.paid_at) }}</span>
    </section>

    <!-- Le chemin de l'argent -->
    <template v-if="summary">
      <h2 class="text-[13px] font-extrabold uppercase tracking-wider text-slate-500">Où est votre argent</h2>
      <section class="rounded-2xl bg-white ring-1 ring-slate-200 px-4">
        <div v-for="(row, i) in flow" :key="row.label" class="relative flex items-center gap-3 py-3">
          <span v-if="i < flow.length - 1" class="absolute left-[19px] top-[52px] h-4 w-0.5 bg-slate-300" />
          <span :class="['h-10 w-10 shrink-0 rounded-xl grid place-items-center', row.tone]"><AppIcon :name="row.icon" class="h-5 w-5" /></span>
          <span class="min-w-0 flex-1 text-sm">{{ row.label }}<span class="block text-[12.5px] text-slate-500">{{ row.text }}</span></span>
          <b :class="['text-base whitespace-nowrap', row.amountClass]">{{ money(row.amount) }}</b>
        </div>
      </section>
    </template>

    <!-- Les versements -->
    <h2 class="text-[13px] font-extrabold uppercase tracking-wider text-slate-500">Mes versements</h2>
    <section v-if="payouts.length" class="rounded-2xl bg-white ring-1 ring-slate-200 divide-y divide-slate-100">
      <RouterLink v-for="p in payouts" :key="p.id" :to="`/marchand/paiements/${p.id}`" class="tap flex items-center justify-between gap-3 px-4 py-3 active:bg-slate-50">
        <span class="min-w-0 text-sm">
          <b class="block first-letter:uppercase">{{ p.paid_at ? longDate(p.paid_at) : `Du ${date(p.period_start)} au ${date(p.period_end)}` }}</b>
          <span class="text-[12.5px] text-slate-500">{{ p.status === 'paid' ? (p.method_label || 'Payé') : p.status_label }}</span>
        </span>
        <b :class="['whitespace-nowrap', p.status === 'cancelled' ? 'text-slate-400 line-through' : p.net_amount < 0 ? 'text-red-600' : 'text-emerald-700']">{{ p.net_amount > 0 ? '+ ' : '' }}{{ money(p.net_amount) }}</b>
      </RouterLink>
    </section>
    <EmptyState v-else-if="loaded" icon="💸" title="Aucun versement pour le moment" text="Vos versements apparaîtront ici dès que l'agence vous reversera l'argent encaissé." />

    <!-- Le détail, à la demande -->
    <button v-if="entries.length" type="button" class="m-btn-secondary" :aria-expanded="showEntries" @click="showEntries = !showEntries">
      {{ showEntries ? 'Masquer le détail des mouvements' : 'Voir le détail des mouvements' }}
    </button>
    <section v-if="showEntries" class="rounded-2xl bg-white ring-1 ring-slate-200 divide-y divide-slate-100">
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
import EmptyState from '../../components/mobile/EmptyState.vue'
import { useAuthStore } from '../../stores/auth'
import { date, dateTime, money, signedClass } from '../../utils/format'

const auth = useAuthStore()
const summary = ref(null)
const entries = ref([])
const payouts = ref([])
const loaded = ref(false)
const showEntries = ref(false)

const longDate = (value) => new Date(value).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

// Versement préparé par l'agence mais pas encore payé
const draft = computed(() => payouts.value.find((p) => p.status === 'draft'))
const paid = computed(() => payouts.value.filter((p) => p.status === 'paid' && p.paid_at))
const lastPaid = computed(() => paid.value[0])
const thisMonth = computed(() => {
  const month = new Date().toISOString().slice(0, 7)
  return paid.value.filter((p) => String(p.paid_at).slice(0, 7) === month)
})

const flow = computed(() => [
  { icon: 'moto', tone: 'bg-blue-100 text-blue-700', label: 'Chez les livreurs', text: 'encaissé, pas encore rendu à l\'agence', amount: summary.value.pending_cash },
  { icon: 'store', tone: 'bg-slate-100 text-slate-600', label: 'À l\'agence', text: draft.value ? 'en partie dans le versement en préparation' : 'prêt pour le prochain versement', amount: summary.value.available, amountClass: summary.value.available < 0 ? 'text-red-600' : '' },
  {
    icon: 'wallet',
    tone: 'bg-emerald-100 text-emerald-700',
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
  } finally {
    loaded.value = true
  }
})
</script>
