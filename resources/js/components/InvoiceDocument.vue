<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
      <RouterLink :to="back" class="text-sm text-blue-600">← Retour</RouterLink>
      <button class="btn-primary" @click="print">🖨️ Imprimer / PDF</button>
    </div>

    <article class="invoice bg-white rounded shadow p-6 md:p-10 max-w-3xl mx-auto space-y-6 text-sm print:shadow-none print:p-0">
      <header class="flex flex-wrap justify-between gap-6">
        <div class="whitespace-pre-line">
          <p class="text-lg font-bold">{{ issuerName }}</p>
          <p class="text-gray-600">{{ issuerRest }}</p>
        </div>
        <div class="text-right">
          <p class="text-2xl font-bold">Facture</p>
          <p class="font-mono">{{ invoice.number }}</p>
          <p class="text-gray-600">Émise le {{ date(invoice.issued_at) }}</p>
          <p class="text-gray-600">Échéance : {{ date(invoice.due_at) }}</p>
        </div>
      </header>

      <section>
        <p class="text-gray-500">Facturé à</p>
        <p class="font-semibold">{{ invoice.company?.name }}</p>
      </section>

      <table class="w-full">
        <thead class="border-b text-left text-gray-600">
          <tr><th class="py-2">Désignation</th><th class="py-2 text-right">Quantité</th><th class="py-2 text-right">Prix</th><th class="py-2 text-right">Montant</th></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-if="invoice.pricing === 'percent'">
            <td class="py-2">
              Formule « {{ invoice.plan_name }} » — {{ invoice.period_label || monthLabel }}
              <span class="block text-xs text-gray-500">Part des frais de livraison de chaque course prise en charge, du {{ date(invoice.period_start) }} au {{ date(invoice.period_end) }}</span>
              <span v-if="invoice.cap_applied" class="block text-xs text-emerald-700">Plafond de la formule atteint : le montant est limité au plafond.</span>
            </td>
            <td class="py-2 text-right">{{ invoice.orders_count }}</td>
            <td class="py-2 text-right">—</td>
            <td class="py-2 text-right">{{ money(invoice.deliveries_amount) }}</td>
          </tr>
          <tr v-else>
            <td class="py-2">
              Abonnement « {{ invoice.plan_name }} » — {{ invoice.period_label || monthLabel }}
              <span class="block text-xs text-gray-500">Du {{ date(invoice.period_start) }} au {{ date(invoice.period_end) }}</span>
            </td>
            <td class="py-2 text-right">1</td>
            <td class="py-2 text-right">{{ money(invoice.base_amount) }}</td>
            <td class="py-2 text-right">{{ money(invoice.base_amount) }}</td>
          </tr>
          <tr v-if="invoice.overage_orders">
            <td class="py-2">
              Courses au-delà des {{ invoice.included_orders }} incluses
              <span class="block text-xs text-gray-500">{{ invoice.orders_count }} courses sur la période</span>
            </td>
            <td class="py-2 text-right">{{ invoice.overage_orders }}</td>
            <td class="py-2 text-right">{{ money(invoice.overage_per_order) }}</td>
            <td class="py-2 text-right">{{ money(invoice.overage_amount) }}</td>
          </tr>
          <tr v-if="invoice.extra_couriers_amount">
            <td class="py-2">
              Livreurs en plus de la formule
              <span class="block text-xs text-gray-500">{{ money(invoice.extra_courier_price) }} par livreur et par mois, au prorata des jours où il est actif ({{ invoice.extra_courier_days }} jour(s) de livreur)</span>
            </td>
            <td class="py-2 text-right">{{ invoice.extra_courier_days }} j</td>
            <td class="py-2 text-right">—</td>
            <td class="py-2 text-right">{{ money(invoice.extra_couriers_amount) }}</td>
          </tr>
          <tr v-for="(line, i) in (invoice.app_options || [])" :key="`app-${i}`">
            <td class="py-2">
              {{ line.label }}
              <span class="block text-xs text-gray-500">{{ money(line.price) }} par mois, au prorata des jours de l'option ({{ line.days }} jour(s))</span>
            </td>
            <td class="py-2 text-right">{{ line.days }} j</td>
            <td class="py-2 text-right">—</td>
            <td class="py-2 text-right">{{ money(line.amount) }}</td>
          </tr>
          <tr v-if="invoice.premium_cards_amount">
            <td class="py-2">
              Cartes Membre Premium Jibiat Marchand
              <span class="block text-xs text-gray-500">Cartes offertes à vos meilleurs e-commerçants</span>
            </td>
            <td class="py-2 text-right">{{ invoice.premium_cards_count }}</td>
            <td class="py-2 text-right">{{ money(invoice.premium_cards_amount / Math.max(1, invoice.premium_cards_count)) }}</td>
            <td class="py-2 text-right">{{ money(invoice.premium_cards_amount) }}</td>
          </tr>
          <tr v-if="invoice.carried_amount">
            <td class="py-2">
              Montants reportés des factures précédentes
              <span class="block text-xs text-gray-500">Trop petits pour une facture à part, ils sont ajoutés à celle-ci.</span>
            </td>
            <td class="py-2 text-right">—</td>
            <td class="py-2 text-right">—</td>
            <td class="py-2 text-right">{{ money(invoice.carried_amount) }}</td>
          </tr>
        </tbody>
        <tfoot class="border-t">
          <tr><td colspan="3" class="py-2 text-right font-semibold">Total</td><td class="py-2 text-right font-bold">{{ money(invoice.total) }}</td></tr>
          <tr v-if="invoice.amount_paid"><td colspan="3" class="py-1 text-right text-gray-600">Déjà payé</td><td class="py-1 text-right">{{ money(invoice.amount_paid) }}</td></tr>
          <tr v-if="invoice.status !== 'void' && invoice.status !== 'carried'"><td colspan="3" class="py-1 text-right font-semibold">Reste à payer</td><td class="py-1 text-right font-semibold">{{ money(invoice.balance) }}</td></tr>
        </tfoot>
      </table>

      <p v-if="invoice.status === 'paid'" class="rounded bg-emerald-50 p-3 font-medium text-emerald-800">✓ Facture payée{{ invoice.paid_at ? ` le ${date(invoice.paid_at)}` : '' }}.</p>
      <p v-else-if="invoice.status === 'carried'" class="rounded bg-slate-100 p-3 font-medium">Rien à payer maintenant : ce petit montant est reporté sur la facture suivante{{ invoice.carried_into ? ` (${invoice.carried_into})` : '' }}.</p>
      <p v-else-if="invoice.status === 'void'" class="rounded bg-slate-100 p-3 font-medium">Facture annulée{{ invoice.notes ? ` : ${invoice.notes}` : '' }}.</p>

      <section v-if="invoice.payments?.length">
        <p class="font-medium mb-1">Paiements reçus</p>
        <ul class="text-gray-700">
          <li v-for="p in invoice.payments" :key="p.id">{{ date(p.paid_on) }} · {{ p.method_label }}{{ p.reference ? ` (${p.reference})` : '' }} · {{ money(p.amount) }}</li>
        </ul>
      </section>

      <section v-if="invoice.status === 'open' && invoice.payment_instructions" class="rounded border p-3">
        <p class="font-medium mb-1">Comment payer</p>
        <p class="whitespace-pre-line text-gray-700">{{ invoice.payment_instructions }}</p>
        <p class="mt-1 text-gray-500">Indiquez le numéro {{ invoice.number }} avec votre paiement.</p>
      </section>
    </article>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { date, money } from '../utils/format'

const props = defineProps({
  invoice: { type: Object, required: true },
  back: { type: String, required: true },
})

const lines = computed(() => (props.invoice.invoice_issuer || 'Jibiat').split('\n'))
const issuerName = computed(() => lines.value[0])
const issuerRest = computed(() => lines.value.slice(1).join('\n'))
const monthLabel = computed(() => {
  const [y, m] = props.invoice.period.split('-')
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
})

function print() {
  window.print()
}
</script>
