<template>
  <div v-if="wallet" class="space-y-4">
    <p v-if="stale" class="flex items-center gap-2 rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3"><Icone nom="hors-ligne" />Hors ligne : montants de la dernière connexion.</p>

    <section class="grid grid-cols-2 gap-2">
      <div class="m-card p-4">
        <p class="text-sm text-slate-600">Gagné aujourd'hui</p>
        <p class="text-2xl font-bold text-emerald-700">{{ money(wallet.earned_today) }}</p>
      </div>
      <div class="m-card p-4">
        <p class="text-sm text-slate-600">Cette semaine</p>
        <p class="text-2xl font-bold">{{ money(wallet.earned_week || 0) }}</p>
      </div>
    </section>

    <!-- Quand cette paie arrive : au prochain versement, ou sur la fiche de paie -->
    <p v-if="wallet.unpaid" class="flex items-start gap-2 rounded-2xl bg-blue-50 p-4 text-sm text-blue-900">
      <Icone nom="gain" class="mt-0.5 shrink-0" />
      <span v-if="wallet.settles_on_remittance">
        <strong>{{ money(wallet.pay_deduction) }}</strong> seront déduits à votre prochain versement à la caisse<template v-if="wallet.unpaid > wallet.pay_deduction"> ; le reste ({{ money(wallet.unpaid - wallet.pay_deduction) }}) au versement suivant ou sur votre fiche de paie</template>.
      </span>
      <span v-else>
        <strong>{{ money(wallet.unpaid) }}</strong> à recevoir sur votre fiche de paie<template v-if="wallet.pay?.next_payslip"> du {{ date(wallet.pay.next_payslip) }}</template>.
      </span>
    </p>

    <div v-for="p in wallet.pending_payslips || []" :key="p.reference" class="m-card p-4 flex justify-between gap-3 bg-emerald-50">
      <div class="min-w-0">
        <p class="flex items-center gap-2 font-medium"><Icone nom="recette" />Fiche de paie prête</p>
        <p class="text-xs text-slate-600">{{ date(p.period_start) }} → {{ date(p.period_end) }} · {{ p.reference }} · en attente de paiement</p>
      </div>
      <p :class="['font-bold whitespace-nowrap', signedClass(p.amount)]">{{ money(p.amount) }}</p>
    </div>

    <!-- Détail des gains pas encore payés, course par course -->
    <section v-if="wallet.earnings?.length" class="space-y-2">
      <h2 class="font-semibold">Gains à recevoir, course par course</h2>
      <div class="m-card divide-y">
        <div v-for="e in shownEarnings" :key="e.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ e.description }}</p>
            <p v-if="e.detail" class="text-xs text-slate-600">{{ e.detail }}</p>
            <p class="text-xs text-slate-600">{{ dateTime(e.created_at) }}</p>
          </div>
          <p :class="['font-semibold whitespace-nowrap', signedClass(e.amount)]">{{ e.amount > 0 ? '+ ' : '' }}{{ money(e.amount) }}</p>
        </div>
        <button v-if="wallet.earnings.length > shownEarnings.length" type="button" class="tap w-full p-3 text-center text-sm font-semibold text-[var(--app-color)]" @click="showAll = true">
          Voir les {{ wallet.earnings.length - shownEarnings.length }} autres
        </button>
      </div>
    </section>

    <section v-if="wallet.recent_payouts?.length" class="space-y-2">
      <h2 class="font-semibold">Mes fiches de paie</h2>
      <div class="m-card divide-y">
        <div v-for="p in wallet.recent_payouts" :key="p.reference" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium">{{ p.settled_on_remittance ? `Réglée sur le versement du ${date(p.paid_at)}` : `Payée le ${date(p.paid_at)}` }}</p>
            <p class="text-xs text-slate-600"><span class="font-mono">{{ p.reference }}</span><template v-if="p.method_label"> · {{ p.method_label }}</template></p>
          </div>
          <p class="font-semibold whitespace-nowrap">{{ money(p.amount) }}</p>
        </div>
      </div>
    </section>

    <p v-if="wallet.pay" class="m-card p-4 text-sm text-slate-600">
      <template v-if="wallet.pay.base_salary">Salaire de base : <strong>{{ money(wallet.pay.base_salary) }}</strong> · </template>
      <template v-if="wallet.pay.period_label">Paie : {{ wallet.pay.period_label.toLowerCase() }}<template v-if="wallet.pay.next_payslip"> · prochaine fiche le <strong>{{ date(wallet.pay.next_payslip) }}</strong></template></template>
    </p>

    <section v-if="wallet.pay?.objectives?.length" class="m-card p-4 space-y-3">
      <h2 class="flex items-center gap-2 font-semibold"><Icone nom="objectif" />Mes objectifs de la période</h2>
      <div v-for="o in wallet.pay.objectives" :key="o.metric" class="space-y-1">
        <div class="flex justify-between text-sm">
          <span>{{ o.label }}</span>
          <strong>{{ o.value }}{{ o.metric === 'success_rate' ? ' %' : '' }}</strong>
        </div>
        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, (o.value / o.tiers[o.tiers.length - 1].threshold) * 100)}%` }" />
        </div>
        <p class="text-xs text-slate-600">
          <span v-for="(t, i) in o.tiers" :key="t.threshold">
            <span :class="t.reached ? 'text-emerald-700 font-semibold' : ''"><Icone v-if="t.reached" nom="valider" taille="petite" class="inline align-[-3px]" />{{ t.threshold }}{{ o.metric === 'success_rate' ? ' %' : '' }} → {{ money(t.amount) }}</span><span v-if="i < o.tiers.length - 1"> · </span>
          </span>
        </p>
        <p v-if="o.metric === 'success_rate' && o.min_count && o.attempts < o.min_count" class="text-xs text-slate-600">
          Compte à partir de {{ o.min_count }} livraisons tentées ({{ o.attempts }} pour l'instant).
        </p>
      </div>
      <p class="text-xs text-slate-600">Seul le palier le plus haut atteint est payé, sur la fiche de fin de période.</p>
    </section>

    <EmptyState v-if="!wallet.earnings?.length && !wallet.recent_payouts?.length && !wallet.earned_today" art="argent" title="Pas encore de gains" text="Vos gains apparaîtront ici à chaque course faite." />
  </div>
  <div v-else-if="error" class="text-center py-10 space-y-3">
    <p class="text-slate-600">{{ error }}</p>
    <button class="m-btn m-btn-secondary" @click="load">Réessayer</button>
  </div>
  <p v-else class="text-center text-slate-600 py-10">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { apiErrorMessage } from '../../bootstrap/axios'
import { cachedGet } from '../../composables/useCachedApi'
import EmptyState from '../../components/mobile/EmptyState.vue'
import Icone from '../../components/ui/Icone.vue'
import { date, dateTime, money, signedClass } from '../../utils/format'

/**
 * Mes gains : ce que le livreur a gagné (jour, semaine), course par course, ses fiches de paie (dont celles
 * réglées sur un versement) et ses objectifs. La caisse ne montre que ce qu'il doit verser.
 */
const wallet = ref(null)
const stale = ref(false)
const error = ref('')
const showAll = ref(false)
const shownEarnings = computed(() => (showAll.value ? wallet.value.earnings : wallet.value.earnings.slice(0, 10)))

async function load() {
  error.value = ''
  try {
    const { data, stale: offline } = await cachedGet('/courier/wallet')
    wallet.value = data.data
    stale.value = offline
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger vos gains.')
  }
}

onMounted(load)
</script>
