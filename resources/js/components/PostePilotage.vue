<template>
  <!-- Poste de pilotage (équipe du dispatch) : état du jour, ce qu'il faut régler, livreurs à surveiller, parcours du jour -->
  <div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <h1 class="text-2xl font-bold">Poste de pilotage <span class="font-medium text-slate-600">· {{ todayLong }}</span></h1>
      <span class="text-sm text-slate-600">mis à jour à {{ updatedAt }}</span>
    </div>

    <!-- Conseil : rouvrir ou fermer la journée selon les livreurs libres -->
    <DayAdvice :advice="advice" @changed="refresh" />

    <!-- 1. État du jour : une seule ligne -->
    <section class="card flex flex-wrap items-center gap-x-7 gap-y-4 px-5 py-4" aria-label="État du jour">
      <div v-if="deliveryDay?.rules" class="flex min-w-[11rem] items-center gap-2.5">
        <Icone :nom="deliveryDay.closed ? 'jour-ferme' : 'jour-ouvert'" :class="deliveryDay.closed ? 'text-amber-800' : 'text-emerald-700'" />
        <div>
          <p class="font-semibold">{{ deliveryDay.closed ? 'Journée fermée' : 'Journée ouverte' }}</p>
          <p class="text-sm text-slate-600">
            <template v-if="deliveryDay.closed">nouvelles courses pour demain</template>
            <template v-else-if="deliveryDay.cutoff_time">après {{ deliveryDay.cutoff_time }}, les courses partent demain</template>
            <template v-else>validation automatique</template>
          </p>
        </div>
      </div>

      <div class="min-w-[10rem] flex-1">
        <p class="text-sm text-slate-600">Livrées</p>
        <p class="text-2xl font-bold tabular-nums">
          {{ report?.counts.delivered ?? '—' }}
          <span v-if="report?.counts.planned" class="text-base font-medium text-slate-600">/ {{ report.counts.planned }} · {{ rate }} %</span>
        </p>
        <div v-if="report?.counts.planned" class="mt-1.5 h-1.5 rounded-full bg-slate-200" role="progressbar" :aria-valuenow="rate" aria-valuemin="0" aria-valuemax="100" aria-label="Courses livrées">
          <div class="h-1.5 rounded-full bg-emerald-700" :style="{ width: `${Math.min(rate, 100)}%` }" />
        </div>
      </div>

      <div v-if="activity?.totals.estimated_end" class="min-w-[8rem]">
        <p class="text-sm text-slate-600">Fin estimée</p>
        <p class="text-2xl font-bold tabular-nums">{{ hour(activity.totals.estimated_end) }}</p>
        <p v-if="activity.totals.late_end && activity.depot_closes_at" class="text-sm font-medium text-amber-800">
          {{ activity.totals.late_end }} livreur{{ activity.totals.late_end > 1 ? 's' : '' }} après {{ activity.depot_closes_at }}
        </p>
      </div>

      <div v-if="report?.money" class="min-w-[11rem]">
        <p class="text-sm text-slate-600">Encaissé chez les clients</p>
        <p class="text-2xl font-bold tabular-nums">{{ money(report.money.collected) }}</p>
        <p v-if="report.money.with_couriers" class="text-sm font-medium text-amber-800">
          dont {{ money(report.money.with_couriers) }} chez {{ report.money.couriers_holding }} livreur{{ report.money.couriers_holding > 1 ? 's' : '' }}
        </p>
      </div>

      <div v-if="activity" class="min-w-[7rem]">
        <p class="text-sm text-slate-600">Livreurs actifs</p>
        <p class="text-2xl font-bold tabular-nums">{{ activity.totals.active }} <span class="text-base font-medium text-slate-600">/ {{ activity.totals.couriers }}</span></p>
      </div>

      <button v-if="report" type="button" class="ml-auto text-sm font-medium text-blue-700 hover:underline" @click="share">Envoyer le point sur WhatsApp</button>
    </section>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
      <!-- 2. À régler : une seule file, de la plus urgente à la moins urgente -->
      <section class="card overflow-hidden" aria-labelledby="a-regler">
        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-5 py-3.5">
          <h2 id="a-regler" class="text-lg font-semibold">À régler <span v-if="todos.length" class="font-medium text-slate-600">· {{ todos.length }}</span></h2>
          <span class="text-sm text-slate-600">du plus urgent au moins urgent</span>
        </div>
        <p v-if="!todos.length" class="flex items-center gap-2 px-5 py-6 text-emerald-700">
          <Icone nom="livre" /> <span class="font-medium">Tout est réglé pour le moment.</span>
        </p>
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="t in todos" :key="t.key" :class="['flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3', t.level === 'urgent' ? 'bg-red-50/50' : '']">
            <span :class="['inline-flex w-[7.5rem] shrink-0 items-center justify-center gap-1.5 rounded-full py-0.5 text-xs font-semibold ring-1', LEVELS[t.level].class]">
              <Icone :nom="LEVELS[t.level].icon" taille="petite" /> {{ LEVELS[t.level].label }}
            </span>
            <span class="w-9 text-right text-xl font-bold tabular-nums">{{ t.count }}</span>
            <div class="min-w-[12rem] flex-1">
              <p class="font-semibold">{{ t.title }}</p>
              <p v-if="t.sub" class="text-sm text-slate-600">{{ t.sub }}</p>
            </div>
            <RouterLink :to="t.to" class="btn-secondary">{{ t.action }}</RouterLink>
          </li>
        </ul>
        <!-- Date dépassée depuis plus de 2 jours : hors de la file, une mention discrète vers les courses non traitées -->
        <RouterLink
          v-if="overdueOld" to="/admin/courses/en-retard?age=old"
          class="block border-t border-slate-100 px-5 py-3 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
        >
          {{ overdueOld }} course{{ overdueOld > 1 ? 's' : '' }} non traitée{{ overdueOld > 1 ? 's' : '' }} depuis plus de 2 jours
          <span class="font-medium text-blue-700">· Voir</span>
        </RouterLink>
      </section>

      <!-- Livreurs à surveiller -->
      <section class="card overflow-hidden" aria-labelledby="a-surveiller">
        <div class="flex items-baseline justify-between gap-2 border-b border-slate-200 px-5 py-3.5">
          <h2 id="a-surveiller" class="text-lg font-semibold">Livreurs à surveiller</h2>
          <RouterLink to="/admin/activite" class="text-sm font-medium text-blue-700 hover:underline">Activité des livreurs</RouterLink>
        </div>
        <p v-if="!activity" class="px-5 py-6 text-slate-600">Chargement…</p>
        <p v-else-if="!watched.length" class="px-5 py-6 text-slate-600">Rien à signaler sur les livreurs.</p>
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="c in watched.slice(0, 6)" :key="c.id">
            <RouterLink :to="{ path: '/admin/activite', query: { livreur: c.id } }" class="block space-y-1.5 px-5 py-3 hover:bg-slate-50">
              <div class="flex items-baseline justify-between gap-2">
                <span class="font-semibold">{{ c.name }}</span>
                <span v-if="c.deliveries.total" class="text-sm text-slate-600 tabular-nums">{{ c.deliveries.done }} / {{ c.deliveries.total }} livrées</span>
              </div>
              <div v-if="c.deliveries.total" class="h-1.5 rounded-full bg-slate-200">
                <div class="h-1.5 rounded-full bg-amber-600" :style="{ width: `${Math.round(100 * c.deliveries.done / c.deliveries.total)}%` }" />
              </div>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="f in c.flags" :key="f" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200">{{ f }}</span>
              </div>
            </RouterLink>
          </li>
        </ul>
        <p v-if="activity && activity.data.length > watched.length" class="border-t border-slate-100 px-5 py-3 text-sm text-slate-600">
          {{ activity.data.length - watched.length }} autre{{ activity.data.length - watched.length > 1 ? 's' : '' }} livreur{{ activity.data.length - watched.length > 1 ? 's' : '' }} : rien à signaler.
        </p>
      </section>
    </div>

    <!-- 3. Parcours du jour : un clic ouvre la file dans Courses -->
    <section class="card space-y-3 px-5 py-4" aria-labelledby="parcours">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="parcours" class="text-lg font-semibold">Parcours du jour</h2>
        <span class="text-sm text-slate-600">un clic ouvre les courses de l'étape</span>
      </div>
      <!-- Étapes en grille régulière : validation et ramassage, livraison, puis issues du jour (fond gris) -->
      <div class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-2">
        <template v-for="s in steps" :key="s.label">
          <RouterLink :to="s.to" :class="['flex min-h-[4.5rem] flex-col justify-center rounded-xl border border-slate-300 px-3.5 py-2 hover:border-slate-500 hover:bg-slate-50', s.outcome ? 'bg-slate-50' : '']">
            <span class="text-sm font-medium text-slate-600">{{ s.label }}</span>
            <span :class="['text-2xl font-bold tabular-nums', s.tone]">{{ s.count }}</span>
            <span v-if="s.late" class="mt-0.5 self-start rounded-full bg-red-600 px-2 text-xs font-semibold text-white">{{ s.late }} attente longue</span>
          </RouterLink>
        </template>
      </div>
    </section>

    <!-- Point d'une autre période (hier, 7 jours, ce mois…) -->
    <details class="card px-5 py-4" :open="choice !== 'today'">
      <summary class="cursor-pointer font-semibold">Point d'une autre période</summary>
      <div class="mt-3 space-y-3">
        <div class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Période du point">
          <button
            v-for="p in PERIODS" :key="p.value" type="button"
            :class="['min-h-9 rounded-full px-3 ring-1', choice === p.value ? 'bg-[var(--admin-color,#0f172a)] font-semibold text-white ring-transparent' : 'bg-white ring-slate-400 hover:bg-slate-50']"
            :aria-pressed="choice === p.value" @click="choice = p.value"
          >{{ p.label }}</button>
          <template v-if="choice === 'other'">
            <input v-model="custom.from" type="date" class="input w-auto" aria-label="Du">
            <input v-model="custom.to" type="date" class="input w-auto" aria-label="Au">
          </template>
        </div>
        <DayPoint v-if="choice !== 'today'" :from="range.from" :to="range.to" detailed />
      </div>
    </details>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../bootstrap/axios'
import DayAdvice from './DayAdvice.vue'
import DayPoint from './DayPoint.vue'
import Icone from './ui/Icone.vue'
import { useDispatchCountStore } from '../stores/dispatchCounts'
import { useFieldReportStore } from '../stores/fieldReports'
import { useHeldParcelStore } from '../stores/heldParcels'
import { orderChanges } from '../composables/useRealtime'
import { refreshWhenVisible, visible as screenVisible } from '../composables/useVisibleRefresh'
import { money, waitDuration } from '../utils/format'

/**
 * Poste de pilotage de l'équipe (charte Jibiat livraison) : l'administrateur lit la page de haut en bas sans chercher.
 * 1. État du jour en une ligne ; 2. une seule file « À régler », de la plus urgente à la moins urgente, à côté des
 * livreurs à surveiller ; 3. le parcours du jour, étape par étape, chaque étape ouvrant sa file dans Courses.
 */
const dispatchCounts = useDispatchCountStore()
const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()

const iso = (d) => d.toLocaleDateString('sv-SE')
const todayIso = iso(new Date())
const shift = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return iso(d) }
const todayLong = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
const hour = (value) => new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')

const report = ref(null)
const activity = ref(null)
const deliveryDay = ref(null)
const advice = ref(null)
const updatedAt = ref('')

const counts = computed(() => dispatchCounts.counts)
const day = computed(() => (dispatchCounts.dayCounts?.date === todayIso ? dispatchCounts.dayCounts : {}))
const exc = computed(() => counts.value.exceptions || {})
const rate = computed(() => (report.value?.counts.planned ? Math.round((report.value.counts.delivered_planned / report.value.counts.planned) * 100) : 0))
// Courses non traitées depuis plus de 2 jours : hors de la file « À régler »
const overdueOld = computed(() => counts.value.overdue?.old?.untreated || 0)
const watched = computed(() => (activity.value?.data || []).filter((c) => c.flags?.length))
const queue = (q) => ({ path: '/admin/courses', query: { queue: q } })

const LEVELS = {
  urgent: { label: 'Urgent', icon: 'urgent', class: 'bg-red-50 text-red-700 ring-red-200' },
  faire: { label: 'À faire', icon: 'attente', class: 'bg-amber-50 text-amber-800 ring-amber-200' },
  voir: { label: 'À surveiller', icon: 'info', class: 'bg-blue-50 text-blue-800 ring-blue-200' },
}

// La file « À régler » : seulement ce qui a un nombre, dans l'ordre de gravité
const todos = computed(() => {
  const c = counts.value
  const pickupWait = c.waiting?.pickup?.oldest_minutes || 0
  const deliveryWait = c.waiting?.delivery?.oldest_minutes || 0
  const cutoffCount = c.cutoff?.passed ? c.cutoff.count : 0
  return [
    // Date dépassée (hier, avant-hier) : reporter ou annuler passe avant toute assignation
    { key: 'reschedule', level: 'urgent', count: c.overdue?.recent?.untreated || 0, title: 'Courses à reporter ou annuler', sub: 'date de livraison dépassée depuis hier ou avant-hier', action: 'Reporter / annuler', to: '/admin/courses/en-retard?age=recent' },
    { key: 'late-pickup', level: 'urgent', count: c.late?.pickup || 0, title: 'Courses sans ramasseur depuis trop longtemps', sub: pickupWait ? `la plus ancienne attend depuis ${waitDuration(pickupWait)}` : '', action: 'Assigner', to: queue('pickup_unassigned') },
    { key: 'late-delivery', level: 'urgent', count: c.late?.delivery || 0, title: 'Colis sans livreur depuis trop longtemps', sub: deliveryWait ? `le plus ancien attend depuis ${waitDuration(deliveryWait)}` : '', action: 'Assigner', to: queue('delivery_unassigned') },
    { key: 'cutoff', level: 'urgent', count: cutoffCount, title: `Courses du jour toujours sans ramasseur ou livreur après ${c.cutoff?.time || ''}`, sub: 'risque de ne pas être livrées aujourd\'hui', action: 'Assigner', to: queue('unassigned') },
    { key: 'code', level: 'urgent', count: exc.value.code_to_verify || 0, title: 'Codes à vérifier', sub: 'livrées hors connexion avec un code faux', action: 'Vérifier', to: queue('code_to_verify') },
    { key: 'confirm', level: 'faire', count: day.value.to_confirm || 0, title: 'Nouvelles courses à valider', sub: 'reçues des e-commerçants', action: 'Valider', to: queue('to_confirm') },
    { key: 'cancel', level: 'faire', count: c.cancellation_requests || 0, title: 'Annulations demandées', sub: 'à accepter ou refuser', action: 'Décider', to: '/admin/courses/annulations' },
    { key: 'change', level: 'faire', count: c.change_requests || 0, title: 'Modifications à valider', sub: 'prix ou livraison changés par l\'e-commerçant', action: 'Voir', to: queue('change_requests') },
    { key: 'decide', level: 'faire', count: exc.value.to_decide || 0, title: 'Échecs de livraison sans suite', sub: 'relivrer, retourner ou remettre en stock', action: 'Décider', to: queue('to_decide') },
    { key: 'return', level: 'faire', count: day.value.to_return || 0, title: 'Colis à retourner', sub: 'refusés, tentatives épuisées ou retour demandé', action: 'Organiser', to: '/admin/retours' },
    { key: 'held', level: 'voir', count: heldParcels.counts.overdue || 0, title: 'Colis gardés trop longtemps par un livreur', sub: 'à faire rapporter ou livrer', action: 'Voir', to: '/admin/activite/colis' },
    { key: 'reports', level: 'voir', count: fieldReports.open.total || 0, title: 'Remontées terrain', sub: 'signalées par les livreurs, pas encore traitées', action: 'Ouvrir', to: '/admin/activite/remontees' },
  ].filter((t) => t.count > 0)
})

// Le parcours du colis, mêmes étapes que les onglets de Courses : validation, ramassage, livraison, puis issues du jour
const steps = computed(() => {
  const d = day.value
  const late = counts.value.late || {}
  const list = [
    { label: 'À valider', count: d.to_confirm || 0, to: queue('to_confirm'), arrow: true },
    { label: 'Sans ramasseur', count: d.pickup_unassigned || 0, late: Math.min(late.pickup || 0, d.pickup_unassigned || 0), to: queue('pickup_unassigned'), arrow: true },
    { label: 'Ramassage en cours', count: d.to_pickup || 0, to: queue('to_pickup'), arrow: true },
  ]
  if (d.to_prepare) list.push({ label: 'À préparer', count: d.to_prepare, to: queue('to_prepare'), arrow: true })
  list.push(
    { label: 'Sans livreur', count: d.delivery_unassigned || 0, late: Math.min(late.delivery || 0, d.delivery_unassigned || 0), to: queue('delivery_unassigned'), arrow: true },
    { label: 'Livraison assignée', count: d.delivery_assigned || 0, to: queue('delivery_assigned'), arrow: true },
    { label: 'En chemin', count: d.on_the_way || 0, to: queue('on_the_way') },
    { label: 'Livrées', count: d.delivered || 0, tone: 'text-emerald-700', to: queue('delivered'), outcome: true },
    { label: 'Échecs', count: d.failed || 0, tone: (d.failed ? 'text-red-700' : ''), to: queue('failed'), outcome: true },
    { label: 'À retourner', count: d.to_return || 0, to: '/admin/retours', outcome: true },
  )
  return list
})

// Point d'une autre période
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

function share() {
  const c = report.value.counts
  const m = report.value.money
  const lines = [`Point du ${new Date().toLocaleDateString('fr-FR')}`, `Livrées : ${c.delivered}${c.planned ? ` / ${c.planned} courses` : ''}`]
  if (m) lines.push(`Recette livraison : ${money(m.fees)}`, `Encaissé chez les clients : ${money(m.collected)} (caisse ${money(m.at_desk)}, livreurs ${money(m.with_couriers)})`, `À reverser : ${money(m.net_to_merchants)}`)
  window.open(`https://wa.me/?text=${encodeURIComponent(lines.join('\n'))}`, '_blank', 'noopener')
}

async function refresh() {
  dispatchCounts.day = todayIso
  dispatchCounts.fetchCounts().catch(() => {})
  // Colis gardés et remontées terrain : déjà chargés par le menu (AdminLayout), chaque minute
  const [r, a, d, adv] = await Promise.allSettled([
    http.get('/reports/day', { params: { day: todayIso } }),
    http.get('/couriers/activity'),
    http.get('/delivery-day'),
    http.get('/delivery-day/advice'),
  ])
  if (r.status === 'fulfilled') report.value = r.value.data.data
  if (a.status === 'fulfilled') activity.value = a.value.data
  if (d.status === 'fulfilled') deliveryDay.value = d.value.data.data
  advice.value = adv.status === 'fulfilled' ? adv.value.data.data : null
  updatedAt.value = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

let timer
watch(orderChanges, refresh)
onMounted(() => {
  refresh()
  timer = setInterval(() => screenVisible() && refresh(), 60000)
})
onUnmounted(() => {
  clearInterval(timer)
  dispatchCounts.day = null
})
refreshWhenVisible(refresh)
</script>
