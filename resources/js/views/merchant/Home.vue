<template>
  <div class="space-y-3.5">
    <!-- Ma journée (courses à livrer aujourd'hui) : nombre, argent encaissé et à encaisser, où en sont les courses -->
    <section class="relative overflow-hidden rounded-[24px] bg-brand-grad p-4 text-white">
      <svg class="pointer-events-none absolute -right-6 -top-8 opacity-[.12]" width="170" height="170" viewBox="0 0 48 48" aria-hidden="true"><path d="M24 5.5 40.5 14v20L24 42.5 7.5 34V14z" fill="#fff" /></svg>
      <p class="text-[13px] font-medium opacity-90">Ma journée</p>
      <p class="text-[30px] font-extrabold leading-tight tabular-nums">{{ total }} course{{ total > 1 ? 's' : '' }}</p>
      <template v-if="total">
        <div class="mt-3 grid grid-cols-2 gap-2">
          <div class="rounded-2xl bg-white/15 px-3 py-2.5">
            <p class="text-[12px] font-semibold opacity-90">Encaissé · {{ day.delivered }} livrée{{ day.delivered > 1 ? 's' : '' }}</p>
            <p class="text-[18px] font-extrabold tabular-nums">{{ money(day.collected) }}</p>
          </div>
          <div class="rounded-2xl bg-white/15 px-3 py-2.5">
            <p class="text-[12px] font-semibold opacity-90">À encaisser · {{ day.pending }} en cours</p>
            <p class="text-[18px] font-extrabold tabular-nums">{{ money(day.toCollect) }}</p>
          </div>
        </div>
        <div class="mt-3 flex h-2.5 gap-[2px] overflow-hidden rounded-full bg-white/20" aria-hidden="true">
          <span v-for="part in parts" :key="part.key" :style="{ flex: part.count, background: part.light }" />
        </div>
        <div class="mt-2.5 flex flex-wrap gap-x-3 gap-y-1.5">
          <span v-for="part in parts" :key="part.key" class="inline-flex items-center gap-1.5 px-1 text-[12.5px] font-semibold">
            <i class="block h-2 w-2 rounded-full" :style="{ background: part.light }" />{{ part.count }} {{ part.label }}
          </span>
        </div>
      </template>
      <p v-else-if="loaded" class="mt-1 text-sm opacity-90">Aucune course à livrer aujourd'hui.</p>
    </section>

    <!-- Actions rapides : une seule icône (le colis) pour les courses -->
    <section v-if="auth.can('orders.create')" :class="['grid gap-2', quickActions.length > 2 ? 'grid-cols-3' : 'grid-cols-2']">
      <RouterLink v-for="a in quickActions" :key="a.label" :to="a.to" class="tap flex flex-col items-center gap-1.5 rounded-[20px] bg-white py-3 px-1 text-center shadow-soft active:scale-95 transition">
        <ColorIcon :name="a.icon" :size="46" />
        <span class="text-[12px] font-semibold leading-tight text-slate-700">{{ a.label }}</span>
      </RouterLink>
    </section>

    <!-- Outil live : préparer la carte des produits, prendre les commandes du direct -->
    <RouterLink v-if="auth.can('orders.create') && !isPhone" to="/marchand/live/preparer" class="tap flex items-center gap-3 rounded-[22px] bg-slate-900 p-4 text-white shadow-soft">
      <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-rose-600 text-[13px] font-extrabold" aria-hidden="true">LIVE</span>
      <span class="min-w-0 flex-1">
        <span class="block text-[16px] font-bold">Préparer mon live</span>
        <span class="block text-[14px] text-slate-300">Carte des produits, commandes en direct</span>
      </span>
      <span class="text-xl" aria-hidden="true">›</span>
    </RouterLink>

    <!-- Colis qui attendent une décision (toutes dates) : le choix le plus courant en un toucher -->
    <section v-if="alerts.length" class="rounded-[22px] bg-[#FFF7EB] p-4 ring-1 ring-[#FFD89A]">
      <div class="flex items-center gap-3">
        <ColorIcon name="aregler" :size="44" />
        <div class="min-w-0 text-sm">
          <p class="text-[15px] font-bold">{{ alertsTotal > 1 ? `${alertsTotal} courses à régler` : 'Une course à régler' }}</p>
          <p class="truncate text-slate-600">{{ alertLine(alerts[0]) }}</p>
        </div>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <RouterLink :to="`/marchand/courses/${alerts[0].id}?action=decider&choix=demain`" class="m-btn-primary !py-3 !text-[15px]">Relivrer demain</RouterLink>
        <RouterLink :to="alertsTotal > 1 ? '/marchand/courses?filtre=problems&periode=all' : `/marchand/courses/${alerts[0].id}?action=decider`" class="m-btn-secondary !py-3 !text-[15px] !ring-0 shadow-soft">{{ alertsTotal > 1 ? `Voir les ${alertsTotal}` : 'Autre choix' }}</RouterLink>
      </div>
    </section>

    <!-- Pas ramassés à leur date : nouvelle date à choisir -->
    <section v-if="toReschedule.length" class="rounded-[22px] bg-[#FFF7EB] p-4 ring-1 ring-[#FFD89A]">
      <div class="flex items-center gap-3">
        <ColorIcon name="aregler" :size="44" />
        <div class="min-w-0 text-sm">
          <p class="text-[15px] font-bold">📅 {{ toRescheduleTotal > 1 ? `${toRescheduleTotal} colis à reporter` : 'Un colis à reporter' }}</p>
          <p class="text-slate-600">Pas ramassé{{ toRescheduleTotal > 1 ? 's' : '' }} à la date prévue : choisissez une nouvelle date.</p>
        </div>
      </div>
      <div class="mt-3 space-y-2">
        <RouterLink
          v-for="o in toReschedule.slice(0, 3)" :key="o.id" :to="`/marchand/courses/${o.id}`"
          class="tap flex items-center justify-between gap-2 rounded-2xl bg-white px-3 py-2.5 text-sm shadow-soft"
        >
          <span class="min-w-0 truncate"><b>{{ o.recipient.name || o.tracking_code }}</b> · {{ o.delivery.zone_name }}</span>
          <span class="shrink-0 font-bold text-[var(--app-color)]">Choisir ›</span>
        </RouterLink>
      </div>
      <button v-if="toReschedule.length" class="m-btn-primary mt-3 !py-3 !text-[15px]" :disabled="rescheduling" @click="allToday">
        {{ toRescheduleTotal > 1 ? 'Tout livrer aujourd\'hui' : 'Livrer aujourd\'hui' }}
      </button>
    </section>

    <!-- L'argent : ce qui va revenir au marchand -->
    <RouterLink v-if="balance && !isPhone" to="/marchand/paiements" class="tap flex items-center gap-3.5 rounded-[22px] bg-white p-4 shadow-soft active:scale-[.99] transition">
      <ColorIcon name="argent" :size="52" />
      <div class="min-w-0 flex-1">
        <p class="text-[13px] font-medium text-slate-500">{{ balance.unpaid_total < 0 ? 'Vous devez à l\'agence' : 'À recevoir' }}</p>
        <p class="text-[24px] font-extrabold leading-tight tabular-nums">{{ money(Math.abs(balance.unpaid_total)) }}</p>
        <p v-if="balance.pending_cash > 0" class="text-[12.5px] text-slate-500">dont {{ money(balance.pending_cash) }} encore chez les livreurs</p>
      </div>
      <AppIcon name="right" class="h-5 w-5 text-slate-400" />
    </RouterLink>

    <!-- Lieu de ramassage à indiquer -->
    <RouterLink v-if="needsPickupLocation" to="/marchand/lieu-de-ramassage" class="tap flex items-center gap-3 rounded-[20px] border border-[#FFC1CB] bg-[#FFF6F7] p-3.5 active:scale-[.99] transition">
      <ColorIcon name="lieu" :size="44" />
      <span class="min-w-0 flex-1"><strong class="block text-[14.5px]">Votre lieu de ramassage</strong><span class="text-[12.5px] text-slate-600">Un geste : les livreurs vous trouvent plus vite.</span></span>
      <span class="shrink-0 rounded-full bg-[#E54A6E] px-3 py-1.5 text-[12.5px] font-bold text-white">Indiquer</span>
    </RouterLink>

    <!-- Les 10 dernières courses du jour, la plus récente en haut ; toutes avec « Voir + » (plein écran) -->
    <section class="space-y-3">
      <div class="flex items-center justify-between">
        <h2 class="text-[16px] font-bold text-slate-900">Courses du jour<span v-if="total" class="text-slate-400"> · {{ total }}</span></h2>
        <RouterLink to="/marchand/courses?periode=today" class="tap rounded-full bg-[color-mix(in_srgb,var(--app-color)_12%,white)] px-3 py-1 text-sm font-bold text-[var(--app-color)]">Voir +</RouterLink>
      </div>

      <OrderCard v-for="o in latest" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
      <RouterLink v-if="total > latest.length" to="/marchand/courses?periode=today" class="m-btn-secondary">Voir les {{ total }} courses du jour</RouterLink>

      <div v-if="loaded && !orders.length" class="rounded-[24px] bg-white shadow-soft">
        <EmptyState art="livraison" title="Aucune course aujourd'hui" text="Un livreur passe chercher le colis chez vous et vous rapporte l'argent." />
      </div>

      <RouterLink v-if="laterCount" to="/marchand/courses?periode=later" class="tap flex items-center gap-3 rounded-[20px] bg-white p-3.5 shadow-soft text-sm active:scale-[.99] transition">
        <ColorIcon name="calendrier" :size="42" />
        <span class="flex-1"><strong class="block">Prévues plus tard · {{ laterCount }}</strong><span class="text-slate-500">À livrer les prochains jours</span></span>
        <AppIcon name="right" class="h-5 w-5 text-slate-400" />
      </RouterLink>
    </section>

    <!-- Installation (fenêtre qui s'ouvre toute seule) ; notifications une fois l'application utilisée -->
    <InstallBanner app="marchand" popup />
    <PushInvite v-if="orders.length" />
  </div>
</template>

<script setup>
import { isPhone } from '../../composables/usePhone'
import { computed, onMounted, ref, watch } from 'vue'
import { cachedGet } from '../../composables/useCachedApi'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import AppIcon from '../../components/mobile/AppIcon.vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import PushInvite from '../../components/mobile/PushInvite.vue'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { date, localPhone, money } from '../../utils/format'
import { GROUPS, orderStage } from '../../utils/orderStage'
import { ORDER_FILTERS, filterParams } from '../../utils/orderFilters'
import { periodFromQuery } from '../../utils/periods'

const auth = useAuthStore()

const orders = ref([])
const total = ref(0)
const page = ref(1)
const hasMore = ref(false)
const loaded = ref(false)
const alerts = ref([])
const alertsTotal = ref(0)
const laterCount = ref(0)
const balance = ref(null)

const needsPickupLocation = computed(() => !!auth.user?.merchant && !auth.user.merchant.has_pickup_location)

// Courses du jour rangées du plus urgent au plus avancé
const ORDER = Object.fromEntries(GROUPS.map((g, i) => [g.key, i]))
const staged = computed(() => orders.value
  .map((o) => ({ order: o, stage: orderStage(o) }))
  .sort((a, b) => ORDER[a.stage.group] - ORDER[b.stage.group]))
const countOf = (...groups) => staged.value.filter((s) => groups.includes(s.stage.group)).length

// Morceaux de la barre et filtres de la liste
const PARTS = [
  { key: 'delivered', light: '#A8F0CB', groups: ['delivered'], label: 'livrée', plural: 'livrées', soft: '#E3F7EE', deep: '#127A50', filter: 'delivered' },
  { key: 'moving', light: '#9CC8FF', groups: ['route', 'picked'], label: 'en route', plural: 'en route', soft: '#E8F1FF', deep: '#1D5BC7', filter: 'moving' },
  { key: 'pickup', light: '#E2E8F0', groups: ['pickup'], label: 'à ramasser', plural: 'à ramasser', soft: '#EEF1F5', deep: '#3B4859', filter: 'pickup' },
  { key: 'decide', light: '#FFD89A', groups: ['decide'], label: 'à régler', plural: 'à régler', soft: '#FFF4E0', deep: '#B76E00', filter: 'decide' },
  { key: 'returns', light: '#D2C4FF', groups: ['returns', 'closed'], label: 'retour ou annulée', plural: 'retours ou annulées', soft: '#F1ECFF', deep: '#5B3FC4', filter: 'returns' },
]
const parts = computed(() => PARTS
  .map((p) => {
    const count = countOf(...p.groups)
    return { ...p, count, label: count > 1 ? p.plural : p.label }
  })
  .filter((p) => p.count))
// Les 10 dernières courses du jour (la plus récente en haut)
const latest = computed(() => [...orders.value].sort((x, y) => y.id - x.id).slice(0, 10))

// Argent du jour : encaissé (livrées) et à encaisser (courses encore en route ou à ramasser)
const day = computed(() => {
  const cod = (s) => Number(s.order.amounts?.cod_amount || 0)
  const done = staged.value.filter((s) => s.stage.group === 'delivered')
  const going = staged.value.filter((s) => ['route', 'picked', 'pickup'].includes(s.stage.group))
  return {
    delivered: done.length,
    collected: done.reduce((n, s) => n + cod(s), 0),
    pending: going.length,
    toCollect: going.reduce((n, s) => n + cod(s), 0),
  }
})

// Actions rapides de l'accueil : nouvelle course, plusieurs courses, et « Étiquettes » quand des colis du jour attendent le livreur
const BEFORE_PICKUP = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress']
const toLabel = computed(() => orders.value.filter((o) => BEFORE_PICKUP.includes(o.status)).map((o) => o.id).slice(0, 100))
const quickActions = computed(() => [
  { icon: 'colis', label: 'Nouvelle course', to: '/marchand/courses/nouvelle' },
  { icon: 'colis2', label: 'Plusieurs courses', to: '/marchand/courses/plusieurs' },
  ...(toLabel.value.length ? [{ icon: 'etiquette', label: `${toLabel.value.length} étiquette${toLabel.value.length > 1 ? 's' : ''}`, to: { path: '/etiquettes', query: { ids: toLabel.value.join(',') } } }] : []),
])

const alertLine = (o) => [o.recipient.name || localPhone(o.recipient.phone), o.delivery.zone_name, o.last_incident?.label?.toLowerCase()].filter(Boolean).join(' · ')

const todayPeriod = () => periodFromQuery({}, 'today')

async function loadOrders(p = 1) {
  const { data } = await cachedGet('/orders', { params: { ...filterParams(ORDER_FILTERS[0], todayPeriod()), per_page: 100, page: p } })
  orders.value = p === 1 ? data.data : [...orders.value, ...data.data]
  page.value = p
  total.value = data.meta?.total ?? orders.value.length
  hasMore.value = data.meta ? data.meta.current_page < data.meta.last_page : false
  // Toutes les courses du jour (jusqu'à 500) pour que les montants de « Ma journée » soient justes
  if (hasMore.value && p < 5) await loadOrders(p + 1)
}

// Colis pas ramassés à leur date
const toasts = useToastStore()
const toReschedule = ref([])
const toRescheduleTotal = ref(0)
const rescheduling = ref(false)
async function allToday() {
  rescheduling.value = true
  const d = new Date()
  const today = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
  try {
    for (const o of toReschedule.value) await http.post(`/orders/${o.id}/reschedule-date`, { date: today })
    toasts.success('C\'est noté : livraison aujourd\'hui.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    rescheduling.value = false
    load()
  }
}

async function load() {
  try {
    const [a, later, late] = await Promise.all([
      cachedGet('/orders', { params: { queue: 'to_decide', per_page: 5 } }),
      cachedGet('/orders', { params: { per_page: 1, ...filterParams(ORDER_FILTERS[0], periodFromQuery({ periode: 'later' })) } }),
      cachedGet('/orders', { params: { queue: 'to_reschedule', per_page: 50 } }),
      loadOrders(1),
    ])
    toReschedule.value = late.data.data
    toRescheduleTotal.value = late.data.meta?.total ?? late.data.data.length
    alerts.value = a.data.data
    alertsTotal.value = a.data.meta?.total ?? a.data.data.length
    laterCount.value = later.data.meta?.total ?? later.data.data.length
  } catch {
    // Hors ligne sans données en cache : l'accueil reste vide, le bandeau hors ligne s'affiche
  } finally {
    loaded.value = true
  }
}

async function loadBalance() {
  if (!auth.can('finance.view') || !auth.user?.merchant_id) return
  try {
    balance.value = (await cachedGet(`/finance/merchants/${auth.user.merchant_id}/ledger`, { params: { per_page: 1 } })).data.summary
  } catch {
    // Facultatif : l'accueil reste utilisable sans le solde
  }
}

watch(orderChanges, () => {
  load()
  loadBalance()
})
onMounted(() => {
  load()
  loadBalance()
})
</script>
