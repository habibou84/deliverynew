<template>
  <div class="max-w-3xl space-y-4">
    <h1 class="text-xl font-bold">Mon abonnement</h1>

    <template v-if="summary">
      <!-- À payer -->
      <div v-if="summary.billing.amount_due" :class="['card p-4 space-y-2', summary.billing.state === 'ok' ? '' : 'ring-2', ringClass]">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-semibold">Reste à payer : {{ money(summary.billing.amount_due) }}</p>
          <p v-if="summary.billing.state === 'restricted'" class="text-sm font-medium text-red-700">Création de courses suspendue jusqu'au paiement</p>
          <p v-else-if="summary.billing.days_late" class="text-sm font-medium text-amber-700">En retard de {{ summary.billing.days_late }} jour{{ summary.billing.days_late > 1 ? 's' : '' }}</p>
        </div>
        <div v-if="summary.payment_instructions" class="rounded bg-slate-50 p-3 text-sm">
          <p class="font-medium mb-1">Comment payer</p>
          <p class="whitespace-pre-line text-gray-700">{{ summary.payment_instructions }}</p>
          <p class="mt-1 text-gray-500">Indiquez le numéro de la facture avec votre paiement.</p>
        </div>
      </div>

      <div class="card p-4">
        <SubscriptionSummary :summary="summary" />
      </div>

      <div class="card overflow-x-auto">
        <h2 class="font-semibold p-4 pb-2">Factures</h2>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Facture</th><th class="p-2">Mois</th><th class="p-2 text-right">Total</th><th class="p-2 text-right">Reste</th><th class="p-2">Échéance</th><th class="p-2">Statut</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="i in summary.invoices" :key="i.id">
              <td class="p-2 font-mono"><RouterLink :to="`/admin/abonnement/factures/${i.id}`" class="text-blue-600">{{ i.number }}</RouterLink></td>
              <td class="p-2">{{ i.period }}</td>
              <td class="p-2 text-right">{{ money(i.total) }}</td>
              <td class="p-2 text-right">{{ money(i.balance) }}</td>
              <td class="p-2">{{ date(i.due_at) }}</td>
              <td class="p-2"><InvoiceStatus :invoice="i" /></td>
            </tr>
            <tr v-if="!summary.invoices.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucune facture pour l'instant.</td></tr>
          </tbody>
        </table>
      </div>
    </template>
    <p v-else class="text-gray-500">Chargement…</p>

    <p class="text-sm text-gray-500">
      Pour changer de formule, ajouter des livreurs au-delà de la limite ou activer un module, contactez-nous.
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import InvoiceStatus from '../../components/InvoiceStatus.vue'
import SubscriptionSummary from '../../components/SubscriptionSummary.vue'
import { date, money } from '../../utils/format'

const summary = ref(null)

const ringClass = computed(() => ({
  due: 'ring-amber-300', warning: 'ring-amber-500', restricted: 'ring-red-500',
})[summary.value?.billing.state] ?? '')

onMounted(async () => {
  summary.value = (await http.get('/subscription')).data.data
})
</script>
