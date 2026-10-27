<template>
  <div class="space-y-3.5">
    <!-- Ma journée : une barre de couleurs montre où en sont les courses du jour -->
    <section class="relative overflow-hidden rounded-[24px] bg-brand-grad p-4 text-white">
      <svg class="pointer-events-none absolute -right-6 -top-8 opacity-[.12]" width="170" height="170" viewBox="0 0 48 48" aria-hidden="true"><path d="M24 5.5 40.5 14v20L24 42.5 7.5 34V14z" fill="#fff" /></svg>
      <p class="text-[13px] font-medium opacity-90">Ma journée</p>
      <p class="text-[30px] font-extrabold leading-tight tabular-nums">{{ total }} course{{ total > 1 ? 's' : '' }}</p>
      <template v-if="total">
        <div class="mt-3 flex h-2.5 gap-[2px] overflow-hidden rounded-full bg-white/20" aria-hidden="true">
          <span v-for="part in parts" :key="part.key" :style="{ flex: part.count, background: part.light }" />
        </div>
        <div class="mt-2.5 flex flex-wrap gap-x-3 gap-y-1.5">
          <button v-for="part in parts" :key="part.key" type="button" :class="['tap inline-flex items-center gap-1.5 rounded-full px-1 text-[12.5px] font-semibold', filter === part.filter ? 'bg-white/20 px-2' : '']" @click="filter = filter === part.filter ? 'all' : part.filter">
            <i class="block h-2 w-2 rounded-full" :style="{ background: part.light }" />{{ part.count }} {{ part.label }}
          </button>
        </div>
      </template>
      <p v-else-if="loaded" class="mt-1 text-sm opacity-90">Aucune course à livrer aujourd'hui.</p>
    </section>

    <!-- Actions rapides : une seule icône (le colis) pour les courses -->
    <section v-if="auth.can('orders.create')" class="grid grid-cols-4 gap-2">
      <RouterLink v-for="a in quickActions" :key="a.label" :to="a.to" class="tap flex flex-col items-center gap-1.5 rounded-[20px] bg-white py-3 px-1 text-center shadow-soft active:scale-95 transition">
        <ColorIcon :name="a.icon" :size="46" />
        <span class="text-[12px] font-semibold leading-tight text-slate-700">{{ a.label }}</span>
      </RouterLink>
    </section>

    <!-- Colis qui attendent une décision (toutes dates) : le choix le plus courant en un toucher -->
    <section v-if="alerts.length" class="rounded-[22px] bg-[#FFF7EB] p-4 ring-1 ring-[#FFD89A]">
      <div class="flex items-center gap-3">
        <ColorIcon name="aregler" :size="44" />
        <div class="min-w-0 text-sm">
          <p class="text-[15px] font-bold">{{ alertsTotal > 1 ? `${alertsTotal} colis attendent votre décision` : 'Un colis attend votre décision' }}</p>
          <p class="truncate text-slate-600">{{ alertLine(alerts[0]) }}</p>
        </div>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <RouterLink :to="`/marchand/courses/${alerts[0].id}?action=decider&choix=demain`" class="m-btn-primary !py-3 !text-[15px]">Relivrer demain</RouterLink>
        <RouterLink :to="alertsTotal > 1 ? '/marchand/courses?filtre=problems&periode=all' : `/marchand/courses/${alerts[0].id}?action=decider`" class="m-btn-secondary !py-3 !text-[15px] !ring-0 shadow-soft">{{ alertsTotal > 1 ? 'Tout voir' : 'Autre choix' }}</RouterLink>
      </div>
    </section>

    <!-- L'argent : ce qui va revenir au marchand -->
    <RouterLink v-if="balance" to="/marchand/paiements" class="tap flex items-center gap-3.5 rounded-[22px] bg-white p-4 shadow-soft active:scale-[.99] transition">
      <ColorIcon name="argent" :size="52" />
      <div class="min-w-0 flex-1">
        <p class="text-[13px] font-medium text-slate-500">{{ balance.unpaid_total < 0 ? 'Vous devez à l\'agence' : 'À recevoir' }}</p>
        <p class="text-[24px] font-extrabold leading-tight tabular-nums">{{ money(Math.abs(balance.unpaid_total)) }}</p>
        <p v-if="balance.pending_cash > 0" class="text-[12.5px] text-slate-500">dont {{ money(balance.pending_cash) }} encore chez les livreurs</p>
      </div>
      <AppIcon name="right" class="h-5 w-5 text-slate-400" />
    </RouterLink>

    <!-- Carte Membre Premium Jibiat Marchand : gagnée (ou offerte), ou ce qu'il reste pour la gagner -->
    <section v-if="premium?.card" class="relative overflow-hidden rounded-[22px] bg-gradient-to-br from-[#14161F] to-[#2B2F3D] p-4 text-white">
      <p class="text-[12px] font-bold uppercase tracking-[.14em] text-[#F2C45A]">Membre Premium</p>
      <p class="mt-1 text-[17px] font-extrabold leading-snug">Vous êtes Membre Premium Jibiat Marchand</p>
      <p class="mt-0.5 text-[13px] text-white/75">
        {{ premium.card.label }} · offerte par {{ premium.company }}<template v-if="premium.card.free_until && premium.card.state === 'free'">, gratuite jusqu'au {{ date(premium.card.free_until) }}</template>
      </p>
      <a :href="premium.card.store_url || premium.card.url" target="_blank" rel="noopener" class="tap mt-3 flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-[#F2C45A] to-[#E3A72F] py-3 text-[15px] font-extrabold text-[#2B1D00] active:scale-[.99] transition">
        {{ premium.card.store_url ? 'Ouvrir mon espace Jibiat Marchand' : 'Activer ma carte' }}
      </a>
    </section>
    <section v-else-if="premium?.progress" class="rounded-[22px] bg-white p-4 shadow-soft">
      <div class="flex items-center gap-3">
        <ColorIcon name="boutique" :size="46" />
        <div class="min-w-0">
          <p class="text-[15px] font-bold leading-snug">{{ premiumTitle }}</p>
          <p class="text-[12.5px] text-slate-500">Carte Membre Premium Jibiat Marchand offerte par {{ premium.company }} : votre boutique en ligne</p>
        </div>
      </div>
      <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="premium.progress.delivered" aria-valuemin="0" :aria-valuemax="premium.progress.target">
        <span class="block h-full rounded-full bg-gradient-to-r from-[#F2C45A] to-[#E3A72F]" :style="{ width: `${Math.min(100, Math.round(premium.progress.delivered / premium.progress.target * 100))}%` }" />
      </div>
      <div class="mt-2 flex flex-wrap justify-between gap-x-3 gap-y-1 text-[12.5px] text-slate-600">
        <span class="tabular-nums"><strong class="text-slate-900">{{ Math.min(premium.progress.delivered, premium.progress.target) }}/{{ premium.progress.target }}</strong> livrées en {{ premium.progress.window_days }} jours</span>
        <span v-if="premium.progress.success_rate !== null" :class="premium.progress.checks.success_rate ? '' : 'font-semibold text-amber-700'">Taux {{ Math.round(premium.progress.success_rate * 100) }} % (il faut {{ Math.round(premium.progress.min_success_rate * 100) }} %)</span>
      </div>
      <p v-if="premium.progress.late_debt" class="mt-1.5 text-[12.5px] font-semibold text-amber-700">Réglez ce que vous devez à l'agence pour gagner la carte.</p>
    </section>

    <!-- Lieu de ramassage à indiquer -->
    <RouterLink v-if="needsPickupLocation" to="/marchand/lieu-de-ramassage" class="tap flex items-center gap-3 rounded-[20px] border border-[#FFC1CB] bg-[#FFF6F7] p-3.5 active:scale-[.99] transition">
      <ColorIcon name="lieu" :size="44" />
      <span class="min-w-0 flex-1"><strong class="block text-[14.5px]">Votre lieu de ramassage</strong><span class="text-[12.5px] text-slate-600">Un geste : les livreurs vous trouvent plus vite.</span></span>
      <span class="shrink-0 rounded-full bg-[#E54A6E] px-3 py-1.5 text-[12.5px] font-bold text-white">Indiquer</span>
    </RouterLink>

    <!-- Toutes les courses du jour, la plus urgente en premier -->
    <section class="space-y-3">
      <div class="flex items-center justify-between">
        <h2 class="text-[16px] font-bold text-slate-900">Mes courses du jour<span v-if="total" class="text-slate-400"> · {{ total }}</span></h2>
        <RouterLink to="/marchand/courses?periode=week" class="text-sm font-bold text-[var(--app-color)]">Autres dates ›</RouterLink>
      </div>
      <div v-if="total" class="-mx-4 px-4 flex gap-1.5 overflow-x-auto no-scrollbar" role="radiogroup" aria-label="Filtrer les courses du jour">
        <button
          v-for="f in chips"
          :key="f.value"
          type="button"
          role="radio"
          :aria-checked="filter === f.value"
          :class="['tap shrink-0 inline-flex items-center gap-1.5 rounded-full py-1.5 pl-3.5 pr-1.5 text-[13.5px] font-semibold', filter === f.value ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 shadow-soft']"
          @click="filter = f.value"
        >
          {{ f.label }}
          <span class="min-w-[22px] h-[22px] px-1.5 rounded-full grid place-items-center text-[12px] font-bold tabular-nums" :style="filter === f.value ? { background: 'rgba(255,255,255,.18)', color: '#fff' } : { background: f.soft, color: f.deep }">{{ f.count }}</span>
        </button>
      </div>

      <OrderCard v-for="o in visible" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
      <button v-if="hasMore" type="button" class="m-btn-secondary" @click="loadOrders(page + 1)">Voir plus</button>

      <div v-if="loaded && !orders.length" class="rounded-[24px] bg-white shadow-soft">
        <EmptyState art="livraison" title="Aucune course aujourd'hui" text="Un livreur passe chercher le colis chez vous et vous rapporte l'argent.">
          <template v-if="auth.can('orders.create')">
            <RouterLink to="/marchand/courses/nouvelle" class="m-btn-primary">Créer une course</RouterLink>
            <RouterLink to="/marchand/courses/nouvelle?coller=1" class="mt-3 block text-[13.5px] font-bold text-[var(--app-color)]">ou collez un message WhatsApp</RouterLink>
          </template>
        </EmptyState>
      </div>

      <RouterLink v-if="laterCount" to="/marchand/courses?periode=later" class="tap flex items-center gap-3 rounded-[20px] bg-white p-3.5 shadow-soft text-sm active:scale-[.99] transition">
        <ColorIcon name="calendrier" :size="42" />
        <span class="flex-1"><strong class="block">Prévues plus tard · {{ laterCount }}</strong><span class="text-slate-500">À livrer les prochains jours</span></span>
        <AppIcon name="right" class="h-5 w-5 text-slate-400" />
      </RouterLink>
    </section>

    <!-- Pour aller plus loin -->
    <section class="space-y-2">
      <RouterLink to="/marchand/statistiques" class="tap flex items-center gap-3 rounded-[20px] bg-white p-3.5 shadow-soft text-sm active:scale-[.99] transition">
        <ColorIcon name="stats" :size="42" />
        <span class="flex-1"><strong class="block">Mes statistiques</strong><span class="text-slate-500">Taux de livraison, motifs d'échec</span></span>
        <AppIcon name="right" class="h-5 w-5 text-slate-400" />
      </RouterLink>
      <RouterLink v-if="stock.total" :to="stock.low ? '/marchand/stock?filtre=bas' : '/marchand/stock'" :class="['tap flex items-center gap-3 rounded-[20px] p-3.5 text-sm active:scale-[.99] transition', stock.low ? 'bg-[#FFF7EB] ring-1 ring-[#FFD89A]' : 'bg-white shadow-soft']">
        <ColorIcon name="stock" :size="42" />
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
const filter = ref('all')

// Carte Membre Premium (rien quand l'entreprise ne la propose pas)
const premium = ref(null)
const premiumTitle = computed(() => {
  const p = premium.value?.progress
  if (!p) return ''
  if (p.missing > 0) return `Plus que ${p.missing} course${p.missing > 1 ? 's' : ''} livrée${p.missing > 1 ? 's' : ''} pour gagner votre carte`
  if (!p.checks.success_rate) return 'Encore un peu moins de retours pour gagner votre carte'
  if (!p.checks.client_since) return 'Votre carte arrive bientôt : il faut être client depuis quelques mois'
  return p.late_debt ? 'Presque : votre carte vous attend' : 'Bravo ! Votre carte arrive dans la journée'
})
async function loadPremium() {
  if (!auth.user?.merchant_id) return
  try {
    premium.value = (await cachedGet('/merchant/premium')).data.data
  } catch {
    // Facultatif
  }
}

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
const chips = computed(() => [
  { value: 'all', label: 'Toutes', count: orders.value.length, soft: '#EEF1F5', deep: '#3B4859' },
  ...parts.value.map((p) => ({ value: p.filter, label: p.key === 'delivered' ? 'Livrées' : p.key === 'moving' ? 'En route' : p.key === 'pickup' ? 'À ramasser' : p.key === 'decide' ? 'À régler' : 'Retours', count: p.count, soft: p.soft, deep: p.deep })),
])
const visible = computed(() => {
  if (filter.value === 'all') return staged.value.map((s) => s.order)
  const part = PARTS.find((p) => p.filter === filter.value)
  return staged.value.filter((s) => part.groups.includes(s.stage.group)).map((s) => s.order)
})

// Actions rapides de l'accueil ; « Étiquettes » quand des colis du jour attendent le livreur, sinon les statistiques
const BEFORE_PICKUP = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress']
const toLabel = computed(() => orders.value.filter((o) => BEFORE_PICKUP.includes(o.status)).map((o) => o.id).slice(0, 100))
const quickActions = computed(() => [
  { icon: 'colis', label: 'Nouvelle course', to: '/marchand/courses/nouvelle' },
  { icon: 'colis', label: 'Plusieurs courses', to: '/marchand/courses/plusieurs' },
  { icon: 'coller', label: 'Coller WhatsApp', to: '/marchand/courses/nouvelle?coller=1' },
  toLabel.value.length
    ? { icon: 'etiquette', label: 'Étiquettes', to: { path: '/etiquettes', query: { ids: toLabel.value.join(',') } } }
    : { icon: 'stats', label: 'Statistiques', to: '/marchand/statistiques' },
])

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
  loadPremium()
  loadStock()
  loadBalance()
})
</script>
