<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Tableau de bord</h1>
      <div class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Période du point">
        <button
          v-for="p in PERIODS" :key="p.value" type="button"
          :class="['rounded-full px-3 py-1 ring-1', choice === p.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
          :aria-pressed="choice === p.value" @click="choose(p.value)"
        >
          {{ p.label }}
        </button>
        <template v-if="choice === 'other'">
          <input v-model="custom.from" type="date" class="input w-auto py-1" aria-label="Du">
          <input v-model="custom.to" type="date" class="input w-auto py-1" aria-label="Au">
        </template>
      </div>
    </div>

    <!-- À faire maintenant : les courses d'aujourd'hui, un clic ouvre le bon onglet -->
    <section class="card space-y-3 p-3 sm:p-4" aria-label="À faire maintenant">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">⚡ À faire maintenant</h2>
        <span class="text-xs text-gray-500">courses d'aujourd'hui · un clic ouvre l'onglet</span>
      </div>
      <RouterLink
        v-if="cutoff?.passed && cutoff.count" :to="tab('unassigned')"
        class="block rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
      >
        🕒 Il est plus de {{ cutoff.time }} : {{ cutoff.count }} course{{ cutoff.count > 1 ? 's' : '' }} du jour
        {{ cutoff.count > 1 ? 'n\'ont' : 'n\'a' }} toujours pas de livreur.
      </RouterLink>

      <div class="grid gap-2 sm:grid-cols-3">
        <RouterLink :to="tab('unassigned')" :class="['rounded-xl border p-3 hover:shadow', unassigned ? (lateTotal ? 'border-red-300 bg-red-50 text-red-900' : 'border-amber-300 bg-amber-50 text-amber-900') : 'border-slate-200']">
          <p class="text-xs">🛵 Sans livreur</p>
          <p class="text-2xl font-bold">
            {{ unassigned }}
            <span v-if="lateTotal" class="rounded-full bg-red-600 px-2 py-0.5 align-middle text-xs font-semibold text-white">⏰ {{ lateTotal }} en retard</span>
          </p>
          <p class="text-xs">
            <template v-if="unassigned">{{ waiting.pickup?.count || 0 }} ramassage(s) · {{ waiting.delivery?.count || 0 }} livraison(s)<template v-if="oldest"> · la plus ancienne : {{ waitDuration(oldest) }}</template></template>
            <template v-else>✓ Toutes les courses du jour ont un livreur</template>
          </p>
        </RouterLink>
        <RouterLink :to="tab('to_confirm')" :class="['rounded-xl border p-3 hover:shadow', toConfirm ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-slate-200']">
          <p class="text-xs">✅ À valider</p>
          <p class="text-2xl font-bold">{{ toConfirm }}</p>
          <p class="text-xs">{{ toConfirm ? 'nouvelles courses en attente' : 'rien à valider' }}</p>
        </RouterLink>
        <RouterLink to="/admin/courses/non-traitees" :class="['rounded-xl border p-3 hover:shadow', untreated ? 'border-red-300 bg-red-50 text-red-900' : 'border-slate-200']">
          <p class="text-xs">⏳ Courses non traitées</p>
          <p class="text-2xl font-bold">{{ untreated }}</p>
          <p class="text-xs">{{ untreated ? `date dépassée${oldestUntreated ? ` · la plus vieille : ${dayName(oldestUntreated)}` : ''}` : 'tout est à jour' }}</p>
        </RouterLink>
      </div>

      <div class="flex flex-wrap gap-2 text-sm">
        <RouterLink
          v-for="t in chips" :key="t.queue" :to="t.to"
          :class="['rounded-full px-3 py-1 ring-1', t.count ? t.class : 'bg-white text-gray-500 ring-slate-300']"
        >
          {{ t.icon }} {{ t.count }} {{ t.count > 1 ? t.plural : t.label }}
        </RouterLink>
      </div>
    </section>

    <!-- Point du jour ou de la période -->
    <DayPoint :from="range.from" :to="range.to" detailed @delivered="router.push('/admin/courses')" @loaded="report = $event" />

    <div class="grid gap-4 lg:grid-cols-2">
      <!-- Livreurs de la période -->
      <section class="card space-y-2 p-3 sm:p-4">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">🛵 Livreurs {{ range.from === range.to ? 'du jour' : 'de la période' }}</h2>
          <RouterLink to="/admin/activite" class="text-sm text-blue-600">Activité</RouterLink>
        </div>
        <p v-if="!report?.couriers?.length" class="text-sm text-gray-500">Aucune livraison sur la période.</p>
        <ul v-else class="divide-y text-sm">
          <li v-for="c in report.couriers" :key="c.id" class="flex flex-wrap items-center justify-between gap-x-3 py-1.5">
            <span class="font-medium">{{ c.name || 'Livreur' }}</span>
            <span class="text-gray-600">
              {{ c.delivered }} livrée{{ c.delivered > 1 ? 's' : '' }}<template v-if="c.in_hand"> · 🎒 {{ c.in_hand }} en main</template><template v-if="c.collected !== null"> · {{ money(c.collected) }}</template>
            </span>
          </li>
        </ul>
      </section>

      <!-- Dernières courses -->
      <section class="card space-y-2 p-3 sm:p-4">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">🕘 Dernières courses</h2>
          <RouterLink to="/admin/courses" class="text-sm text-blue-600">Tout voir</RouterLink>
        </div>
        <p v-if="!recent.length" class="text-sm text-gray-500">Aucune course pour le moment.</p>
        <ul v-else class="divide-y text-sm">
          <li v-for="o in recent" :key="o.id">
            <RouterLink :to="`/admin/courses/${o.id}`" class="flex items-center gap-3 py-1.5 hover:bg-slate-50">
              <span class="font-mono text-xs">{{ o.tracking_code }}</span>
              <span class="min-w-0 flex-1 truncate">{{ o.recipient.name || o.recipient.phone }} · {{ o.delivery.zone_name }}</span>
              <StatusBadge :status="o.status" :label="o.status_label" />
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import http from '../bootstrap/axios'
import DayPoint from './DayPoint.vue'
import StatusBadge from './StatusBadge.vue'
import { useDispatchCountStore } from '../stores/dispatchCounts'
import { orderChanges } from '../composables/useRealtime'
import { money, waitDuration } from '../utils/format'

/**
 * Tableau de bord de l'équipe (dispatch) : ce qu'il faut faire maintenant sur les courses du jour, le point du jour
 * ou de la période, les livreurs et les dernières courses.
 */
const router = useRouter()
const dispatchCounts = useDispatchCountStore()
const iso = (d) => d.toLocaleDateString('sv-SE')
const todayIso = iso(new Date())
const shift = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return iso(d) }

const PERIODS = [
  { value: 'today', label: 'Aujourd\'hui' },
  { value: 'yesterday', label: 'Hier' },
  { value: 'week', label: '7 jours' },
  { value: 'month', label: 'Ce mois' },
  { value: 'other', label: 'Autre…' },
]
const choice = ref('today')
const custom = reactive({ from: todayIso, to: todayIso })
const range = computed(() => ({
  today: { from: todayIso, to: todayIso },
  yesterday: { from: shift(-1), to: shift(-1) },
  week: { from: shift(-6), to: todayIso },
  month: { from: `${todayIso.slice(0, 8)}01`, to: todayIso },
  other: { from: custom.from, to: custom.to < custom.from ? custom.from : custom.to },
}[choice.value]))
const choose = (value) => { choice.value = value }

const report = ref(null)
const recent = ref([])
const oldestUntreated = ref(null)

// Compteurs du jour (mêmes que la page Courses)
const day = computed(() => (dispatchCounts.dayCounts?.date === todayIso ? dispatchCounts.dayCounts : null))
const counts = computed(() => dispatchCounts.counts)
const unassigned = computed(() => day.value?.unassigned || 0)
const toConfirm = computed(() => day.value?.to_confirm || 0)
const untreated = computed(() => counts.value.exceptions?.untreated || 0)
const waiting = computed(() => counts.value.waiting || {})
const lateTotal = computed(() => Math.min(counts.value.late?.total || 0, unassigned.value))
const oldest = computed(() => Math.max(waiting.value.pickup?.oldest_minutes || 0, waiting.value.delivery?.oldest_minutes || 0))
const cutoff = computed(() => counts.value.cutoff)
const tab = (queue) => ({ path: '/admin/courses', query: { queue } })
const chips = computed(() => [
  { queue: 'to_return', icon: '↩️', label: 'à retourner', plural: 'à retourner', count: day.value?.to_return || 0, class: 'bg-rose-50 text-rose-900 ring-rose-300' },
  { queue: 'failed', icon: '❌', label: 'échec', plural: 'échecs', count: day.value?.failed || 0, class: 'bg-red-50 text-red-900 ring-red-300' },
  { queue: 'change_requests', icon: '✏️', label: 'modification à valider', plural: 'modifications à valider', count: counts.value.change_requests || 0, class: 'bg-amber-50 text-amber-900 ring-amber-300' },
  { queue: 'to_prepare', icon: '📦', label: 'à préparer', plural: 'à préparer', count: day.value?.to_prepare || 0, class: 'bg-indigo-50 text-indigo-900 ring-indigo-300' },
  { queue: 'code_to_verify', icon: '⚠️', label: 'code à vérifier', plural: 'codes à vérifier', count: counts.value.exceptions?.code_to_verify || 0, class: 'bg-red-50 text-red-900 ring-red-300' },
].filter((t) => t.count || t.queue !== 'code_to_verify').map((t) => ({ ...t, to: tab(t.queue) })))
const dayName = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })

async function loadUntreated() {
  try {
    oldestUntreated.value = (await http.get('/orders/untreated-groups', { params: { by: 'date' } })).data.data.groups[0]?.key || null
  } catch {
    oldestUntreated.value = null
  }
}

async function refresh() {
  dispatchCounts.day = todayIso
  dispatchCounts.fetchCounts().catch(() => {})
  loadUntreated()
  try {
    recent.value = (await http.get('/orders', { params: { per_page: 5 } })).data.data
  } catch {
    recent.value = []
  }
}

watch(orderChanges, refresh)
onMounted(refresh)
onUnmounted(() => { dispatchCounts.day = null })
</script>
