<template>
  <!-- Courses : quatre cartes (Courses, Activité des livreurs, Retours marchands, Courses en retard), celle ouverte allumée -->
  <nav class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Courses">
    <RouterLink
      v-for="c in cards" :key="c.to" :to="c.to"
      :class="['card flex flex-col gap-1.5 p-3 sm:p-4 transition', c.active ? 'border-[var(--admin-color,#0f172a)] ring-2 ring-[var(--admin-color,#0f172a)]' : 'hover:border-slate-400']"
      :aria-current="c.active ? 'page' : undefined"
    >
      <span class="flex items-center gap-2">
        <Icone :nom="c.icon" class="text-slate-700" />
        <span class="flex-1 font-bold leading-tight">{{ c.label }}</span>
        <span v-if="c.badge" class="grid h-6 min-w-6 place-items-center rounded-full bg-red-600 px-1.5 text-xs font-bold text-white">{{ c.badge > 99 ? '99+' : c.badge }}</span>
      </span>
      <!-- Chiffre mis en avant (Courses en retard : à décider) -->
      <span v-if="c.highlight" :class="['rounded-xl px-3 py-2', c.highlight.number ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-700']">
        <span class="block text-2xl font-extrabold tabular-nums">{{ c.highlight.number }} <span class="text-sm font-semibold">{{ c.highlight.unit }}</span></span>
        <span class="block text-xs font-semibold sm:text-sm">{{ c.highlight.label }}</span>
      </span>
      <span v-else :class="['text-2xl font-extrabold tabular-nums sm:text-3xl', c.numberClass]">{{ c.number ?? '—' }} <span class="text-sm font-semibold text-slate-600">{{ c.unit }}</span></span>
      <span v-if="c.progress !== undefined" class="h-2 rounded-full bg-slate-200"><span class="block h-2 rounded-full bg-emerald-600" :style="{ width: `${c.progress}%` }" /></span>
      <span v-for="(l, i) in c.lines" :key="i" class="hidden text-sm text-slate-700 sm:block">{{ l }}</span>
      <span v-if="c.alert" :class="['mt-auto rounded-lg px-2 py-1 text-xs font-semibold sm:text-sm', c.alertClass]">{{ c.alert }}</span>
    </RouterLink>
  </nav>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import http from '../bootstrap/axios'
import { useDispatchCountStore } from '../stores/dispatchCounts'
import { useFieldReportStore } from '../stores/fieldReports'
import { useHeldParcelStore } from '../stores/heldParcels'
import { money } from '../utils/format'
import Icone from './ui/Icone.vue'

/**
 * Cartes de Courses : chacune résume son écran (chiffres du jour pour Courses et Activité, toutes dates pour les
 * Retours et les retards) ; la pastille rouge signale ce qui demande une action.
 */
const route = useRoute()
const counts = useDispatchCountStore()
const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()
const returns = ref(null)
const activity = ref(null)

const todayIso = new Date().toLocaleDateString('sv-SE')
const plural = (n, one, many) => `${n} ${n > 1 ? many : one}`
const hour = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')

const cards = computed(() => {
  const day = counts.dayCounts
  const isToday = !day || day.date === todayIso
  const all = day?.all ?? null
  const delivered = day?.delivered ?? 0
  const toAssign = (day?.pickup_unassigned ?? 0) + (day?.delivery_unassigned ?? 0)
  const toConfirm = day?.to_confirm ?? 0
  const rows = activity.value?.data || []
  const mission = rows.filter((c) => c.state === 'mission').length
  const free = rows.filter((c) => c.state === 'available').length
  const cash = rows.reduce((s, c) => s + (c.cash_in_hand || 0), 0)
  const end = activity.value?.totals?.estimated_end
  const r = returns.value
  const ex = counts.counts.exceptions || {}
  // Courses en retard : seulement les colis jamais ramassés ; les ramassés sont « à décider » dans Courses
  const late = counts.counts.to_reschedule || 0
  const old = counts.counts.overdue?.old?.to_reschedule || 0
  const toDecide = ex.picked_late || 0
  const watch = fieldReports.open.total + heldParcels.counts.overdue

  return [
    {
      to: '/admin/courses', label: 'Courses', icon: 'course', active: route.path === '/admin/courses',
      number: all, unit: isToday ? 'du jour' : `le ${new Date(`${day.date}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}`,
      progress: all ? Math.round((100 * delivered) / all) : 0,
      lines: [`${delivered} livrée${delivered > 1 ? 's' : ''} · ${day?.on_the_way ?? 0} en chemin`],
      alert: [toAssign ? `${toAssign} à assigner` : '', toConfirm ? `${toConfirm} à valider` : ''].filter(Boolean).join(' · '),
      alertClass: 'bg-red-50 text-red-800', badge: toAssign + toConfirm,
    },
    {
      to: '/admin/activite', label: 'Activité des livreurs', icon: 'activite', active: route.path.startsWith('/admin/activite'),
      number: activity.value ? mission : null, unit: 'en mission', numberClass: 'text-blue-700',
      lines: [`${plural(free, 'libre', 'libres')} maintenant${end ? ` · fin estimée ${hour(end)}` : ''}`, `${money(cash)} chez les livreurs`],
      alert: watch ? [fieldReports.open.total ? plural(fieldReports.open.total, 'remontée à traiter', 'remontées à traiter') : '', heldParcels.counts.overdue ? plural(heldParcels.counts.overdue, 'colis gardé trop longtemps', 'colis gardés trop longtemps') : ''].filter(Boolean).join(' · ') : '',
      alertClass: 'bg-amber-50 text-amber-900', badge: watch,
    },
    {
      to: '/admin/retours', label: 'Retours marchands', icon: 'retour', active: route.path === '/admin/retours',
      number: r?.to_return ?? null, unit: 'colis à rendre',
      lines: r ? [`${r.to_assign || 0} à confier · ${r.assigned || 0} confié${(r.assigned || 0) > 1 ? 's' : ''} à un livreur`, `chez ${plural(r.merchants || 0, 'e-commerçant', 'e-commerçants')}`] : [],
      alert: r?.oldest_days ? `Le plus ancien attend depuis ${plural(r.oldest_days, 'jour', 'jours')}` : '',
      alertClass: 'bg-amber-50 text-amber-900', badge: r?.to_assign || 0,
    },
    {
      to: '/admin/courses/en-retard', label: 'Courses en retard', icon: 'retard', active: route.path === '/admin/courses/en-retard',
      highlight: { number: toDecide, unit: 'à décider', label: 'Ramassés, date de livraison dépassée' },
      lines: [`${late} jamais ramassé${late > 1 ? 's' : ''} · à reporter`],
      alert: old ? `${old} jamais ramassé${old > 1 ? 's' : ''} depuis plus de 2 jours` : '', alertClass: 'bg-amber-50 text-amber-900', badge: late + toDecide,
    },
  ]
})

async function load() {
  // Compteurs du jour : ceux de la liste des courses, sinon aujourd'hui
  if (!counts.day) counts.day = todayIso
  const [r, a] = await Promise.allSettled([
    http.get('/return-slips/count'),
    http.get('/couriers/activity'),
    counts.fetchCounts(),
    fieldReports.fetchCounts(),
    heldParcels.fetchCounts(),
  ])
  if (r.status === 'fulfilled') returns.value = r.value.data.data
  if (a.status === 'fulfilled') activity.value = a.value.data
}

let timer
onMounted(() => {
  load()
  timer = setInterval(() => !document.hidden && load(), 60000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
