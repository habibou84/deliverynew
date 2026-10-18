<template>
  <div class="space-y-5">
    <div>
      <p class="text-2xl font-bold">Bonjour {{ firstName }} 👋</p>
      <p class="text-slate-500 first-letter:uppercase">{{ todayLabel }}<span v-if="todayCount !== null"> · {{ todayCount ? `${todayCount} course${todayCount > 1 ? 's' : ''} aujourd'hui` : 'aucune course aujourd\'hui' }}</span></p>
    </div>

    <!-- L'action principale : créer une course -->
    <div v-if="auth.can('orders.create')" class="flex gap-2">
      <RouterLink to="/marchand/courses/nouvelle" class="tap flex-1 rounded-2xl p-4 text-white flex items-center gap-3 active:opacity-90" :style="{ backgroundColor: 'var(--app-color)' }">
        <span class="h-10 w-10 shrink-0 rounded-xl bg-white/20 grid place-items-center text-2xl leading-none">+</span>
        <span><strong class="block text-base">Nouvelle course</strong><span class="text-xs opacity-85">En quelques secondes</span></span>
      </RouterLink>
      <RouterLink to="/marchand/courses/nouvelle?coller=1" class="tap w-28 shrink-0 m-card p-3 grid place-items-center text-center text-xs font-semibold text-[var(--app-color)] active:bg-slate-50">
        <span class="text-2xl leading-none">💬</span>Coller un message WhatsApp
      </RouterLink>
    </div>

    <!-- Ce qui attend une décision de l'e-commerçant (quelle que soit la date) -->
    <section v-if="todo" class="space-y-2">
      <h2 class="text-xs font-bold uppercase tracking-wide text-slate-500">À faire maintenant</h2>
      <RouterLink
        v-if="needsPickupLocation"
        to="/marchand/lieu-de-ramassage"
        class="tap m-card p-4 flex items-center gap-3 border-l-4 border-amber-400 active:bg-slate-50"
      >
        <span class="h-10 w-10 shrink-0 rounded-xl bg-amber-50 grid place-items-center text-xl">📍</span>
        <span class="flex-1 text-sm"><strong class="block">Indiquez où récupérer vos colis</strong>En un geste, depuis votre boutique : vos ramassages seront plus rapides.</span>
        <span class="text-slate-400">›</span>
      </RouterLink>
      <RouterLink
        v-for="o in visibleAlerts"
        :key="o.id"
        :to="`/marchand/courses/${o.id}`"
        class="tap m-card p-4 flex items-center gap-3 border-l-4 border-amber-400 active:bg-slate-50"
      >
        <span class="h-10 w-10 shrink-0 rounded-xl bg-amber-50 grid place-items-center text-xl">⚠️</span>
        <span class="min-w-0 flex-1 text-sm">
          <strong class="block truncate">{{ o.last_incident?.label || o.status_label }}</strong>
          <span class="block truncate text-slate-500">{{ o.recipient.name || o.recipient.phone }} · {{ o.delivery.zone_name }}</span>
        </span>
        <span class="shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }">Décider</span>
      </RouterLink>
      <button v-if="alerts.length > visibleAlerts.length" type="button" class="w-full text-sm font-medium text-amber-700 py-1" @click="showAllAlerts = true">
        Voir les {{ alerts.length - visibleAlerts.length }} autres →
      </button>
    </section>

    <!-- L'argent : un seul montant, en mots simples -->
    <RouterLink v-if="balance" to="/marchand/paiements" class="tap block rounded-2xl p-5 text-white active:opacity-90" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-85">{{ balance.available < 0 ? 'Vous devez à l\'agence' : 'On vous doit' }}</p>
      <p class="text-3xl font-bold mt-0.5">{{ money(Math.abs(balance.available)) }}</p>
      <p class="text-sm opacity-85">{{ balance.available < 0 ? 'À régler lors de votre prochain point avec l\'agence' : 'Versé lors de votre prochain paiement' }}</p>
      <div v-if="balance.pending_cash" class="mt-3 pt-3 border-t border-white/20 flex justify-between text-sm">
        <span class="opacity-85">Encore chez les livreurs</span><strong>{{ money(balance.pending_cash) }}</strong>
      </div>
      <p class="mt-2 text-xs font-semibold opacity-90">Voir mes paiements ›</p>
    </RouterLink>

    <!-- Mes courses : période, compteurs, liste -->
    <section class="space-y-3">
      <div class="flex justify-between items-center">
        <h2 class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ isToday ? 'Mes courses du jour' : 'Mes courses' }}</h2>
        <RouterLink to="/marchand/courses" class="text-sm font-semibold text-[var(--app-color)]">Tout voir</RouterLink>
      </div>

      <div class="-mx-4 px-4 flex gap-2 overflow-x-auto no-scrollbar" role="radiogroup" aria-label="Période">
        <button
          v-for="p in presets"
          :key="p.key"
          type="button"
          role="radio"
          :aria-checked="period.key === p.key"
          :class="['tap shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium ring-1', period.key === p.key ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
          :style="period.key === p.key ? { backgroundColor: 'var(--app-color)' } : {}"
          @click="choose(p)"
        >
          {{ p.label }}
        </button>
        <button
          type="button"
          role="radio"
          :aria-checked="period.key === 'custom'"
          :class="['tap shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium ring-1', period.key === 'custom' ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
          :style="period.key === 'custom' ? { backgroundColor: 'var(--app-color)' } : {}"
          @click="openPicker"
        >
          📅 {{ period.key === 'custom' ? period.label : 'Autre date' }}
        </button>
      </div>

      <div v-if="summary" class="grid grid-cols-3 gap-2">
        <RouterLink to="/marchand/courses?filtre=active" class="tap m-card p-3 text-center active:bg-slate-50">
          <p class="text-xl font-bold text-blue-700">{{ summary.counts.in_progress }}</p><p class="text-xs text-slate-500">En cours</p>
        </RouterLink>
        <RouterLink to="/marchand/courses?filtre=delivered" class="tap m-card p-3 text-center active:bg-slate-50">
          <p class="text-xl font-bold text-emerald-700">{{ summary.counts.delivered }}</p><p class="text-xs text-slate-500">Livrées</p>
        </RouterLink>
        <RouterLink to="/marchand/courses?filtre=problems" :class="['tap m-card p-3 text-center active:bg-slate-50', problems ? 'ring-2 ring-amber-300' : '']">
          <p :class="['text-xl font-bold', problems ? 'text-amber-700' : 'text-slate-400']">{{ problems }}</p><p class="text-xs text-slate-500">À régler</p>
        </RouterLink>
      </div>

      <!-- Argent de la période, en une phrase -->
      <p v-if="summary && summary.amounts.collected" class="m-card px-4 py-3 text-sm text-slate-600">
        Les livreurs ont encaissé <strong class="text-slate-900">{{ money(summary.amounts.collected) }}</strong>,
        moins <strong class="text-slate-900">{{ money(fees) }}</strong> de frais :
        <strong :class="summary.amounts.net_to_merchant < 0 ? 'text-red-600' : 'text-[var(--app-color)]'">{{ money(summary.amounts.net_to_merchant) }} pour vous</strong>.
      </p>

      <OrderCard v-for="o in orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
      <button v-if="hasMore" type="button" class="m-btn-secondary" :disabled="loadingMore" @click="loadOrders(page + 1)">Voir plus</button>
      <EmptyState
        v-if="loaded && !orders.length"
        icon="📦"
        :title="isToday ? 'Aucune course aujourd\'hui' : 'Aucune course sur cette période'"
        :text="isToday ? 'Créez une course en un instant.' : 'Choisissez d\'autres dates.'"
      >
        <RouterLink v-if="isToday && auth.can('orders.create')" to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
      </EmptyState>
    </section>

    <!-- Pour aller plus loin -->
    <section class="space-y-2">
      <button v-if="month && period.key !== 'month'" type="button" class="tap w-full m-card p-4 flex items-center justify-between text-sm active:bg-slate-50" @click="choose(presets.find((p) => p.key === 'month'))">
        <span class="text-slate-500">Ce mois-ci · {{ month.counts.total }} courses</span>
        <span class="font-semibold">{{ money(month.amounts.net_to_merchant) }} →</span>
      </button>
      <RouterLink to="/marchand/statistiques" class="tap m-card p-4 flex items-center gap-3 text-sm active:bg-slate-50">
        <span class="text-2xl">📊</span>
        <span class="flex-1"><strong class="block">Mes statistiques</strong><span class="text-slate-500">Taux de livraison, motifs d'échec, communes</span></span>
        <span class="text-slate-400">›</span>
      </RouterLink>
      <RouterLink
        v-if="stock.total"
        :to="stock.low ? '/marchand/stock?filtre=bas' : '/marchand/stock'"
        :class="['tap block m-card p-4 flex items-center justify-between text-sm active:bg-slate-50', stock.low ? 'ring-2 ring-amber-300' : '']"
      >
        <span>📦 Mon stock · {{ stock.total }} produit{{ stock.total > 1 ? 's' : '' }}</span>
        <span :class="stock.low ? 'font-semibold text-amber-700' : 'text-slate-500'">{{ stock.low ? `⚠️ ${stock.low} en stock bas` : 'Voir' }} →</span>
      </RouterLink>
    </section>

    <!-- Installation et notifications : proposées une fois l'application utilisée -->
    <template v-if="month && month.counts.total">
      <InstallBanner app="marchand" />
      <PushInvite />
    </template>

    <!-- Choix d'une date ou d'un intervalle -->
    <BottomSheet :open="picker.open" title="Choisir les dates" @close="picker.open = false">
      <form class="space-y-4" @submit.prevent="applyPicker">
        <ChoiceChips
          v-model="picker.mode"
          :options="[{ value: 'day', label: 'Un jour' }, { value: 'range', label: 'Du … au …' }]"
          :columns="2"
          label="Type de période"
        />
        <div v-if="picker.mode === 'day'">
          <label class="m-label" for="pick-day">Date</label>
          <input id="pick-day" v-model="picker.from" type="date" :max="todayDate()" class="m-input" required>
        </div>
        <div v-else class="grid grid-cols-2 gap-2">
          <div>
            <label class="m-label" for="pick-from">Du</label>
            <input id="pick-from" v-model="picker.from" type="date" :max="picker.to || todayDate()" class="m-input" required>
          </div>
          <div>
            <label class="m-label" for="pick-to">Au</label>
            <input id="pick-to" v-model="picker.to" type="date" :min="picker.from" :max="todayDate()" class="m-input" required>
          </div>
        </div>
        <button class="m-btn-primary">Afficher</button>
      </form>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cachedGet } from '../../composables/useCachedApi'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import PushInvite from '../../components/mobile/PushInvite.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { date as formatDate, money, today as todayDate } from '../../utils/format'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const firstName = computed(() => (auth.user?.name || '').split(' ')[0])
const todayLabel = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

// Date locale au format AAAA-MM-JJ, décalée de n jours
function shift(days) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}

const presets = [
  { key: 'today', label: 'Aujourd\'hui', from: () => shift(0), to: () => shift(0) },
  { key: 'yesterday', label: 'Hier', from: () => shift(-1), to: () => shift(-1) },
  { key: 'week', label: '7 derniers jours', from: () => shift(-6), to: () => shift(0) },
  { key: 'month', label: 'Ce mois-ci', from: () => `${shift(0).slice(0, 8)}01`, to: () => shift(0) },
]

// Période affichée : aujourd'hui par défaut, ou celle de l'adresse (?du=…&au=…) au retour d'une fiche
const period = reactive({ key: 'today', label: 'Aujourd\'hui', from: shift(0), to: shift(0) })
const isToday = computed(() => period.key === 'today')

const summary = ref(null)
const month = ref(null)
const alerts = ref([])
const orders = ref([])
const page = ref(1)
const total = ref(0)
const hasMore = ref(false)
const loaded = ref(false)
const loadingMore = ref(false)
// Les alertes ne doivent pas repousser le point du jour hors de l'écran
const showAllAlerts = ref(false)
const visibleAlerts = computed(() => (showAllAlerts.value ? alerts.value : alerts.value.slice(0, 2)))
const picker = reactive({ open: false, mode: 'day', from: shift(0), to: shift(0) })
// Ce que l'agence doit à l'e-commerçant (Mon argent), si son compte voit l'argent
const balance = ref(null)
const todayCount = ref(null)

const needsPickupLocation = computed(() => !!auth.user?.merchant && !auth.user.merchant.has_pickup_location)
const todo = computed(() => needsPickupLocation.value || alerts.value.length > 0)
const problems = computed(() => (summary.value ? summary.value.counts.failed + summary.value.counts.rescheduled : 0))
const fees = computed(() => {
  const a = summary.value?.amounts || {}
  return (a.fees || 0) + (a.shipping_fees || 0) + (a.other_fees || 0)
})

// 2026-10-03 → « 03/10 »
const short = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`

function setPeriod(key, from, to) {
  const preset = presets.find((p) => p.key === key)
  const label = preset
    ? preset.label
    : from === to ? `Le ${formatDate(from)}` : `${short(from)} → ${short(to)}`
  Object.assign(period, { key, label, from, to })
}

function choose(preset) {
  setPeriod(preset.key, preset.from(), preset.to())
  router.replace({ query: preset.key === 'today' ? {} : { periode: preset.key, du: period.from, au: period.to } })
  load()
}

function openPicker() {
  Object.assign(picker, { open: true, mode: period.from === period.to ? 'day' : 'range', from: period.from, to: period.to })
}

function applyPicker() {
  const to = picker.mode === 'day' ? picker.from : picker.to
  const from = picker.from <= to ? picker.from : to
  setPeriod('custom', from, picker.from <= to ? to : picker.from)
  picker.open = false
  router.replace({ query: { periode: 'custom', du: period.from, au: period.to } })
  load()
}

async function loadOrders(p = 1) {
  loadingMore.value = p > 1
  try {
    const { data } = await cachedGet('/orders', { params: { from: period.from, to: period.to, per_page: 10, page: p } })
    orders.value = p === 1 ? data.data : [...orders.value, ...data.data]
    page.value = p
    total.value = data.meta?.total ?? orders.value.length
    hasMore.value = data.meta ? data.meta.current_page < data.meta.last_page : false
  } finally {
    loadingMore.value = false
  }
}

async function load() {
  const d = todayDate()
  try {
    const [s, m, a] = await Promise.all([
      cachedGet('/reports/summary', { params: { from: period.from, to: period.to } }),
      cachedGet('/reports/summary', { params: { from: `${d.slice(0, 8)}01`, to: d } }),
      cachedGet('/orders', { params: { status: ['delivery_failed'], per_page: 10 } }),
      loadOrders(1),
    ])
    summary.value = s.data.data
    month.value = m.data.data
    alerts.value = a.data.data
    if (isToday.value) todayCount.value = s.data.data.counts.total
  } catch {
    // Hors ligne sans données en cache : l'accueil reste vide, le bandeau hors ligne s'affiche
  } finally {
    loaded.value = true
  }
}

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/
const { periode, du, au } = route.query
if (periode && DATE_RE.test(du || '') && DATE_RE.test(au || '')) {
  setPeriod(presets.some((p) => p.key === periode) ? periode : 'custom', du, au)
}

watch(orderChanges, () => {
  load()
  loadBalance()
})
// Résumé du stock (seulement si le marchand a des produits)
const stock = reactive({ total: 0, low: 0 })
async function loadStock() {
  // Stock : seulement si la formule de l'entreprise l'inclut
  if (!auth.hasFeature('stock')) return
  try {
    const [all, low] = await Promise.all([
      cachedGet('/products', { params: { per_page: 1 } }),
      cachedGet('/products', { params: { per_page: 1, low: 1 } }),
    ])
    stock.total = all.data.meta?.total || 0
    stock.low = low.data.meta?.total || 0
  } catch {
    // Facultatif : l'accueil reste utilisable sans le stock
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

onMounted(() => {
  load()
  loadStock()
  loadBalance()
})
</script>
