<template>
  <div class="space-y-4">
    <div>
      <p class="text-[28px] font-extrabold tracking-tight leading-tight">Bonjour {{ firstName }}</p>
      <p class="text-slate-500 first-letter:uppercase">{{ todayLabel }}</p>
    </div>

    <!-- Ma journée : une barre de couleurs montre où en sont les courses du jour -->
    <section class="rounded-2xl bg-white ring-1 ring-slate-200 p-4">
      <div class="flex items-end justify-between gap-3">
        <div>
          <p class="text-sm font-semibold text-slate-500">Ma journée</p>
          <p class="text-[32px] font-extrabold leading-none mt-0.5">{{ total }} course{{ total > 1 ? 's' : '' }}</p>
        </div>
        <span v-if="total" class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">{{ countOf('delivered') }} livrée{{ countOf('delivered') > 1 ? 's' : '' }} sur {{ total }}</span>
      </div>
      <template v-if="total">
        <div class="mt-3.5 flex h-3 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
          <span v-for="part in parts" :key="part.key" :class="part.bar" :style="{ flex: part.count }" />
        </div>
        <div class="mt-3 flex flex-wrap gap-1.5">
          <button v-for="part in parts" :key="part.key" type="button" :class="['rounded-full px-2.5 py-1 text-xs font-bold', part.chip]" @click="filter = part.filter">
            {{ part.count }} {{ part.label }}
          </button>
        </div>
      </template>
      <p v-else-if="loaded" class="mt-2 text-sm text-slate-500">Aucune course à livrer aujourd'hui.</p>
    </section>

    <!-- Lieu de ramassage à indiquer -->
    <RouterLink v-if="needsPickupLocation" to="/marchand/lieu-de-ramassage" class="tap flex items-center gap-3 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 active:bg-amber-100">
      <AppIcon name="pin" class="h-6 w-6 shrink-0 text-amber-700" />
      <span class="flex-1 text-sm"><strong class="block">Indiquez où récupérer vos colis</strong>En un geste, depuis votre boutique : vos ramassages seront plus rapides.</span>
      <AppIcon name="right" class="h-5 w-5 text-slate-400" />
    </RouterLink>

    <!-- Colis qui attendent une décision (toutes dates) : le choix le plus courant en un toucher -->
    <section v-if="alerts.length" class="rounded-2xl bg-orange-50 p-4 ring-1 ring-orange-200">
      <div class="flex gap-2.5">
        <AppIcon name="alert" class="h-[22px] w-[22px] shrink-0 text-orange-700" />
        <div class="min-w-0 text-sm">
          <p class="text-[15px] font-bold">{{ alertsTotal > 1 ? `${alertsTotal} colis attendent votre décision` : 'Un colis attend votre décision' }}</p>
          <p class="text-slate-600">{{ alertLine(alerts[0]) }}</p>
        </div>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <RouterLink :to="`/marchand/courses/${alerts[0].id}?action=decider&choix=demain`" class="m-btn-primary !py-3 !text-[15px]">Relivrer demain</RouterLink>
        <RouterLink :to="alertsTotal > 1 ? '/marchand/courses?filtre=problems&periode=all' : `/marchand/courses/${alerts[0].id}?action=decider`" class="m-btn-secondary !py-3 !text-[15px]">{{ alertsTotal > 1 ? 'Tout voir' : 'Autre choix' }}</RouterLink>
      </div>
    </section>

    <!-- L'argent : ce qui va revenir au marchand -->
    <RouterLink v-if="balance" to="/marchand/paiements" class="tap flex items-center gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 active:bg-slate-50">
      <div class="flex-1">
        <p class="text-sm font-semibold text-slate-500">{{ balance.unpaid_total < 0 ? 'Vous devez à l\'agence' : 'À recevoir' }}</p>
        <p class="text-2xl font-extrabold">{{ money(Math.abs(balance.unpaid_total)) }}</p>
        <p v-if="balance.pending_cash > 0" class="text-sm text-slate-500">dont {{ money(balance.pending_cash) }} encore chez les livreurs</p>
      </div>
      <AppIcon name="right" class="h-5 w-5 text-slate-400" />
    </RouterLink>

    <!-- Toutes les courses du jour, la plus urgente en premier -->
    <section class="space-y-3">
      <div class="flex items-center justify-between">
        <h2 class="text-[13px] font-extrabold uppercase tracking-wider text-slate-500">Mes courses du jour · {{ total }}</h2>
        <RouterLink to="/marchand/courses?periode=week" class="text-sm font-bold text-[var(--app-color)]">Autres dates ›</RouterLink>
      </div>
      <div v-if="total" class="-mx-4 px-4 flex gap-1.5 overflow-x-auto no-scrollbar" role="radiogroup" aria-label="Filtrer les courses du jour">
        <button
          v-for="f in chips"
          :key="f.value"
          type="button"
          role="radio"
          :aria-checked="filter === f.value"
          :class="['tap shrink-0 rounded-full px-3 py-1.5 text-[13.5px] font-bold ring-1', filter === f.value ? 'bg-slate-900 text-white ring-transparent' : 'bg-white text-slate-700 ring-slate-300']"
          @click="filter = f.value"
        >{{ f.label }} {{ f.count }}</button>
      </div>

      <OrderCard v-for="o in visible" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
      <button v-if="hasMore" type="button" class="m-btn-secondary" @click="loadOrders(page + 1)">Voir plus</button>

      <EmptyState v-if="loaded && !orders.length" icon="📦" title="Aucune course aujourd'hui" text="Créez une course en un instant avec le bouton en bas.">
        <RouterLink v-if="auth.can('orders.create')" to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
      </EmptyState>

      <RouterLink v-if="laterCount" to="/marchand/courses?periode=later" class="tap flex items-center gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 text-sm active:bg-slate-50">
        <AppIcon name="calendar" class="h-[22px] w-[22px] text-slate-600" />
        <span class="flex-1"><strong class="block">Prévues plus tard · {{ laterCount }}</strong><span class="text-slate-500">À livrer les prochains jours</span></span>
        <AppIcon name="right" class="h-5 w-5 text-slate-400" />
      </RouterLink>
    </section>

    <!-- Pour aller plus loin -->
    <section class="space-y-2">
      <RouterLink to="/marchand/statistiques" class="tap flex items-center gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 text-sm active:bg-slate-50">
        <AppIcon name="chart" class="h-[22px] w-[22px] text-slate-600" />
        <span class="flex-1"><strong class="block">Mes statistiques</strong><span class="text-slate-500">Taux de livraison, motifs d'échec</span></span>
        <AppIcon name="right" class="h-5 w-5 text-slate-400" />
      </RouterLink>
      <RouterLink v-if="stock.total" :to="stock.low ? '/marchand/stock?filtre=bas' : '/marchand/stock'" :class="['tap flex items-center gap-3 rounded-2xl p-4 text-sm ring-1', stock.low ? 'bg-amber-50 ring-amber-300' : 'bg-white ring-slate-200']">
        <AppIcon name="store" class="h-[22px] w-[22px] text-slate-600" />
        <span class="flex-1"><strong class="block">Mon stock · {{ stock.total }} produit{{ stock.total > 1 ? 's' : '' }}</strong><span :class="stock.low ? 'font-semibold text-amber-700' : 'text-slate-500'">{{ stock.low ? `${stock.low} en stock bas` : 'Voir mes produits' }}</span></span>
        <AppIcon name="right" class="h-5 w-5 text-slate-400" />
      </RouterLink>
    </section>

    <!-- Installation et notifications : proposées une fois l'application utilisée -->
    <template v-if="orders.length">
      <InstallBanner app="marchand" />
      <PushInvite />
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { cachedGet } from '../../composables/useCachedApi'
import AppIcon from '../../components/mobile/AppIcon.vue'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import PushInvite from '../../components/mobile/PushInvite.vue'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { localPhone, money } from '../../utils/format'
import { GROUPS, orderStage } from '../../utils/orderStage'
import { ORDER_FILTERS, filterParams } from '../../utils/orderFilters'
import { periodFromQuery } from '../../utils/periods'

const auth = useAuthStore()
const firstName = computed(() => (auth.user?.name || '').split(' ')[0])
const todayLabel = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

const orders = ref([])
const total = ref(0)
const page = ref(1)
const hasMore = ref(false)
const loaded = ref(false)
const alerts = ref([])
const alertsTotal = ref(0)
const laterCount = ref(0)
const balance = ref(null)
const filter = ref('all')

const needsPickupLocation = computed(() => !!auth.user?.merchant && !auth.user.merchant.has_pickup_location)

// Courses du jour rangées du plus urgent au plus avancé
const ORDER = Object.fromEntries(GROUPS.map((g, i) => [g.key, i]))
const staged = computed(() => orders.value
  .map((o) => ({ order: o, stage: orderStage(o) }))
  .sort((a, b) => ORDER[a.stage.group] - ORDER[b.stage.group]))
const countOf = (...groups) => staged.value.filter((s) => groups.includes(s.stage.group)).length

// Morceaux de la barre et filtres de la liste
const PARTS = [
  { key: 'delivered', groups: ['delivered'], label: 'livrée', plural: 'livrées', bar: 'bg-emerald-700', chip: 'bg-emerald-100 text-emerald-800', filter: 'delivered' },
  { key: 'moving', groups: ['route', 'picked'], label: 'en route', plural: 'en route', bar: 'bg-blue-600', chip: 'bg-blue-100 text-blue-800', filter: 'moving' },
  { key: 'pickup', groups: ['pickup'], label: 'à ramasser', plural: 'à ramasser', bar: 'bg-slate-300', chip: 'bg-slate-100 text-slate-700', filter: 'pickup' },
  { key: 'decide', groups: ['decide'], label: 'à régler', plural: 'à régler', bar: 'bg-orange-500', chip: 'bg-orange-100 text-orange-800', filter: 'decide' },
  { key: 'returns', groups: ['returns', 'closed'], label: 'retour ou annulée', plural: 'retours ou annulées', bar: 'bg-violet-500', chip: 'bg-violet-100 text-violet-800', filter: 'returns' },
]
const parts = computed(() => PARTS
  .map((p) => {
    const count = countOf(...p.groups)
    return { ...p, count, label: count > 1 ? p.plural : p.label }
  })
  .filter((p) => p.count))
const chips = computed(() => [
  { value: 'all', label: 'Toutes', count: orders.value.length },
  ...parts.value.map((p) => ({ value: p.filter, label: p.key === 'delivered' ? 'Livrées' : p.key === 'moving' ? 'En route' : p.key === 'pickup' ? 'À ramasser' : p.key === 'decide' ? 'À régler' : 'Retours', count: p.count })),
])
const visible = computed(() => {
  if (filter.value === 'all') return staged.value.map((s) => s.order)
  const part = PARTS.find((p) => p.filter === filter.value)
  return staged.value.filter((s) => part.groups.includes(s.stage.group)).map((s) => s.order)
})

const alertLine = (o) => [o.recipient.name || localPhone(o.recipient.phone), o.delivery.zone_name, o.last_incident?.label?.toLowerCase()].filter(Boolean).join(' · ')

const todayPeriod = () => periodFromQuery({}, 'today')

async function loadOrders(p = 1) {
  const { data } = await cachedGet('/orders', { params: { ...filterParams(ORDER_FILTERS[0], todayPeriod()), per_page: 100, page: p } })
  orders.value = p === 1 ? data.data : [...orders.value, ...data.data]
  page.value = p
  total.value = data.meta?.total ?? orders.value.length
  hasMore.value = data.meta ? data.meta.current_page < data.meta.last_page : false
}

async function load() {
  try {
    const [a, later] = await Promise.all([
      cachedGet('/orders', { params: { queue: 'to_decide', per_page: 5 } }),
      cachedGet('/orders', { params: { per_page: 1, ...filterParams(ORDER_FILTERS[0], periodFromQuery({ periode: 'later' })) } }),
      loadOrders(1),
    ])
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

// Résumé du stock (seulement si la formule l'inclut et que le marchand a des produits)
const stock = reactive({ total: 0, low: 0 })
async function loadStock() {
  if (!auth.hasFeature('stock')) return
  try {
    const [all, low] = await Promise.all([
      cachedGet('/products', { params: { per_page: 1 } }),
      cachedGet('/products', { params: { per_page: 1, low: 1 } }),
    ])
    stock.total = all.data.meta?.total || 0
    stock.low = low.data.meta?.total || 0
  } catch {
    // Facultatif
  }
}

watch(orderChanges, () => {
  load()
  loadBalance()
})
onMounted(() => {
  load()
  loadStock()
  loadBalance()
})
</script>
