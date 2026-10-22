<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <p class="text-sm text-gray-500">Formule</p>
        <p class="text-lg font-semibold">{{ summary.plan ? summary.plan.name : 'Sans formule (aucune limite)' }}</p>
        <p v-if="summary.plan && isPercent" class="text-sm text-gray-600">
          {{ rate(summary.plan.percent_rate) }} des frais de livraison de chaque course prise en charge{{ limits }}
        </p>
        <p v-else-if="summary.plan" class="text-sm text-gray-600">{{ weekly ? `${money(summary.plan.price_weekly)} / semaine` : `${money(summary.plan.price_monthly)} / mois` }}</p>
      </div>
      <span v-if="summary.on_trial" class="rounded-full bg-sky-100 px-3 py-1 text-sm font-medium text-sky-800">
        🎁 Essai gratuit jusqu'au {{ date(summary.trial_ends_at) }}
      </span>
    </div>

    <template v-if="summary.plan">
      <div class="space-y-1">
        <div class="flex justify-between text-sm">
          <span>Courses prises en charge {{ weekly ? 'cette semaine' : 'ce mois' }}</span>
          <span class="font-medium">{{ summary.usage.orders }}{{ isPercent ? '' : summary.usage.included_orders !== null ? ` / ${summary.usage.included_orders} incluses` : ' (illimitées)' }}</span>
        </div>
        <div v-if="summary.usage.included_orders" class="h-2 rounded bg-slate-200 overflow-hidden">
          <div :class="['h-full', summary.usage.overage_orders ? 'bg-amber-500' : 'bg-emerald-500']" :style="{ width: `${percent(summary.usage.orders, summary.usage.included_orders)}%` }" />
        </div>
        <p v-if="summary.usage.overage_orders" class="text-xs text-amber-700">
          {{ summary.usage.overage_orders }} course{{ summary.usage.overage_orders > 1 ? 's' : '' }} en plus × {{ money(summary.plan.overage_per_order) }}
        </p>
      </div>

      <div class="space-y-1">
        <div class="flex justify-between text-sm">
          <span>Livreurs actifs</span>
          <span class="font-medium">{{ summary.usage.couriers }}{{ summary.usage.max_couriers !== null ? ` / ${summary.usage.max_couriers}` : ' (illimités)' }}</span>
        </div>
        <div v-if="summary.usage.max_couriers" class="h-2 rounded bg-slate-200 overflow-hidden">
          <div :class="['h-full', summary.usage.couriers >= summary.usage.max_couriers && summary.plan.extra_courier_price === null ? 'bg-red-500' : 'bg-emerald-500']" :style="{ width: `${percent(summary.usage.couriers, summary.usage.max_couriers)}%` }" />
        </div>
        <p v-if="summary.plan?.extra_courier_price !== null && summary.usage.max_couriers" class="text-xs text-gray-500">
          Au-delà de {{ summary.usage.max_couriers }} livreurs : {{ money(summary.plan.extra_courier_price) }} par mois par livreur en plus, au prorata des jours où il est actif.
          <span v-if="summary.usage.extra_couriers" class="text-amber-700">Vous avez {{ summary.usage.extra_couriers }} livreur{{ summary.usage.extra_couriers > 1 ? 's' : '' }} en plus aujourd'hui.</span>
        </p>
      </div>

      <div class="rounded-lg bg-slate-50 p-3 text-sm space-y-1">
        <p class="font-medium">Estimation pour {{ periodLabel }}</p>
        <template v-if="isPercent">
          <div class="flex justify-between"><span>Part des {{ summary.usage.orders }} course{{ summary.usage.orders > 1 ? 's' : '' }} prises en charge</span><span>{{ money(summary.estimate.deliveries) }}</span></div>
          <p v-if="summary.estimate.cap_applied" class="text-xs text-emerald-700">Plafond atteint : vous ne paierez pas plus pour cette période.</p>
          <p v-if="summary.plan.volume_threshold && summary.plan.volume_rate" class="text-xs text-gray-500">
            Au-delà de {{ summary.plan.volume_threshold }} courses par mois : {{ rate(summary.plan.volume_rate) }} seulement.
          </p>
        </template>
        <template v-else>
          <div class="flex justify-between"><span>Forfait</span><span>{{ money(summary.estimate.base) }}</span></div>
          <div class="flex justify-between"><span>Courses en plus</span><span>{{ money(summary.estimate.overage) }}</span></div>
        </template>
        <div v-if="summary.estimate.extra_couriers" class="flex justify-between"><span>Livreurs en plus ({{ summary.usage.extra_courier_days }} jour{{ summary.usage.extra_courier_days > 1 ? 's' : '' }} de livreur)</span><span>{{ money(summary.estimate.extra_couriers) }}</span></div>
        <div class="flex justify-between border-t pt-1 font-semibold">
          <span>Total</span><span>{{ summary.on_trial ? 'Offert (essai)' : money(summary.estimate.total) }}</span>
        </div>
      </div>
    </template>

    <div>
      <p class="text-sm font-medium mb-1">Modules</p>
      <ul class="text-sm space-y-1">
        <li v-for="f in summary.features" :key="f.key" :class="f.included ? 'text-gray-800' : 'text-gray-400'">
          {{ f.included ? '✓' : '✗' }} {{ f.label }}
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { date, money, rate } from '../utils/format'

const props = defineProps({
  summary: { type: Object, required: true },
})

// Formule à la livraison : pourcentage des frais de chaque course, avec minimum, maximum et plafond
const isPercent = computed(() => props.summary.plan?.pricing === 'percent')
const limits = computed(() => {
  const p = props.summary.plan
  const parts = []
  if (p.percent_min) parts.push(`au moins ${money(p.percent_min)}`)
  if (p.percent_max !== null) parts.push(`au plus ${money(p.percent_max)}`)
  let text = parts.length ? ` (${parts.join(', ')} par course)` : ''
  const cap = weekly.value ? p.cap_amount_weekly : p.cap_amount
  if (cap !== null) text += `, plafonné à ${money(cap)} ${weekly.value ? 'par semaine' : 'par mois'}`
  return text
})

// Facturation à la semaine ou au mois (période en cours)
const weekly = computed(() => props.summary.billing_period?.cycle === 'weekly')
const periodLabel = computed(() => {
  if (weekly.value) return `la semaine du ${date(props.summary.billing_period.period_start)} au ${date(props.summary.billing_period.period_end)}`
  const [y, m] = props.summary.month.split('-')
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
})

function percent(value, max) {
  return Math.min(100, Math.round((value / max) * 100))
}
</script>
