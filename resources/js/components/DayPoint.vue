<template>
  <!-- Point du jour de la page Courses : livrées, recette de livraison, encaissé chez les clients, à reverser -->
  <section v-if="report" class="card space-y-3 p-3 sm:p-4" aria-label="Point du jour">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold">📊 Point {{ dayLabel }}</h2>
      <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500">
        <span v-if="detailed && c.created">🆕 {{ c.created }} nouvelle{{ c.created > 1 ? 's' : '' }} course{{ c.created > 1 ? 's' : '' }}<template v-if="c.created_later">, dont {{ c.created_later }} pour plus tard</template></span>
        <span>mis à jour à {{ updatedAt }}</span>
        <button type="button" class="font-medium text-blue-600 hover:underline" @click="share">Envoyer sur WhatsApp</button>
        <button type="button" class="hidden font-medium text-blue-600 hover:underline sm:inline" @click="open = !open">{{ open ? 'Réduire' : 'Afficher' }}</button>
      </div>
    </div>

    <div v-if="open" :class="['grid gap-2 sm:gap-3', m ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-1 sm:grid-cols-2']">
      <button type="button" class="rounded-xl bg-emerald-50 p-3 text-left hover:ring-2 hover:ring-emerald-300" @click="emit('delivered')">
        <p class="text-xs text-emerald-800">✅ Livrées</p>
        <p class="text-2xl font-bold text-emerald-900">{{ c.delivered }} <span v-if="c.planned" class="text-base font-normal">/ {{ c.planned }} prévues</span></p>
        <div v-if="c.planned" class="mt-1 h-2 rounded-full bg-emerald-100" role="progressbar" :aria-valuenow="rate" aria-valuemin="0" aria-valuemax="100">
          <div class="h-2 rounded-full bg-emerald-500" :style="{ width: `${Math.min(rate, 100)}%` }" />
        </div>
        <p class="mt-1 text-xs text-emerald-800">{{ detail }}</p>
      </button>

      <template v-if="m">
        <button type="button" class="rounded-xl bg-blue-50 p-3 text-left hover:ring-2 hover:ring-blue-300" @click="emit('delivered')">
          <p class="text-xs text-blue-800">🧾 Recette livraison</p>
          <p class="text-2xl font-bold text-blue-900">{{ money(m.fees) }}</p>
          <p class="mt-1 text-xs text-blue-800">frais des {{ c.delivered }} livrée(s)<template v-if="m.shipping_fees"> · + {{ money(m.shipping_fees) }} de frais d'expédition</template></p>
        </button>
        <RouterLink to="/admin/caisse?tab=cash" class="block rounded-xl bg-amber-50 p-3 hover:ring-2 hover:ring-amber-300">
          <p class="text-xs text-amber-800">💵 Encaissé chez les clients</p>
          <p class="text-2xl font-bold text-amber-900">{{ money(m.collected) }}</p>
          <p class="mt-1 text-xs text-amber-800">
            {{ money(m.at_desk) }} déjà à la caisse<template v-if="m.with_couriers"> · <b>{{ money(m.with_couriers) }} chez {{ m.couriers_holding }} livreur(s)</b></template>
          </p>
        </RouterLink>
        <RouterLink to="/admin/caisse?tab=merchants" class="block rounded-xl bg-violet-50 p-3 hover:ring-2 hover:ring-violet-300">
          <p class="text-xs text-violet-800">🏦 À reverser aux e-commerçants</p>
          <p class="text-2xl font-bold text-violet-900">{{ money(m.net_to_merchants) }}</p>
          <p class="mt-1 text-xs text-violet-800">encaissé moins les frais · {{ m.merchants }} e-commerçant(s)</p>
        </RouterLink>
      </template>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../bootstrap/axios'
import { money } from '../utils/format'

/**
 * Point du jour (page Courses, équipe) : courses prévues et livrées, recette de livraison, argent encaissé (caisse ou
 * livreurs) et montant à reverser. Les montants ne sont envoyés qu'à qui voit la caisse.
 */
const props = defineProps({
  day: { type: String, default: '' },
  // Période (tableau de bord) : remplace le jour
  from: { type: String, default: '' },
  to: { type: String, default: '' },
  // Détail complet : retournées, annulées, nouvelles courses
  detailed: { type: Boolean, default: false },
  refresh: { type: Number, default: 0 },
})
const emit = defineEmits(['delivered', 'loaded'])

const report = ref(null)
const updatedAt = ref('')
const open = ref(true)
const c = computed(() => report.value.counts)
const m = computed(() => report.value.money)
const rate = computed(() => (c.value.planned ? Math.round((c.value.delivered_planned / c.value.planned) * 100) : 0))
const todayIso = new Date().toLocaleDateString('sv-SE')
const longDay = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
const shortDay = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })
const dayLabel = computed(() => {
  const { from, to } = report.value
  if (from !== to) return `du ${shortDay(from)} au ${shortDay(to)}`
  return from === todayIso ? 'du jour' : `du ${longDay(from)}`
})
const detail = computed(() => {
  const parts = []
  if (c.value.planned) parts.push(`${rate.value} % des prévues`)
  if (c.value.in_progress) parts.push(`${c.value.in_progress} en cours`)
  if (c.value.failed) parts.push(`${c.value.failed} échec(s)`)
  if (c.value.rescheduled) parts.push(`${c.value.rescheduled} reportée(s)`)
  if (props.detailed && c.value.returned) parts.push(`${c.value.returned} retournée(s)`)
  if (props.detailed && c.value.cancelled) parts.push(`${c.value.cancelled} annulée(s)`)
  return parts.join(' · ') || 'Aucune course prévue ce jour'
})

async function load() {
  try {
    const params = props.from ? { from: props.from, to: props.to || props.from } : { day: props.day || todayIso }
    report.value = (await http.get('/reports/day', { params })).data.data
    emit('loaded', report.value)
    updatedAt.value = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  } catch {
    report.value = null
  }
}

function share() {
  const lines = [`Point ${dayLabel.value === 'du jour' ? `du ${new Date().toLocaleDateString('fr-FR')}` : dayLabel.value}`, `Livrées : ${c.value.delivered}${c.value.planned ? ` / ${c.value.planned} prévues` : ''}`]
  if (m.value) {
    lines.push(`Recette livraison : ${money(m.value.fees)}`, `Encaissé chez les clients : ${money(m.value.collected)} (caisse ${money(m.value.at_desk)}, livreurs ${money(m.value.with_couriers)})`, `À reverser : ${money(m.value.net_to_merchants)}`)
  }
  window.open(`https://wa.me/?text=${encodeURIComponent(lines.join('\n'))}`, '_blank', 'noopener')
}

watch(() => [props.day, props.from, props.to, props.refresh], load)
onMounted(load)
</script>
