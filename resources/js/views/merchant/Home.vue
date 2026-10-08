<template>
  <div class="space-y-5">
    <div>
      <p class="text-2xl font-bold">Bonjour {{ firstName }} 👋</p>
      <p class="text-slate-500">{{ isToday ? 'Voici vos courses du jour.' : period.from === period.to ? `Vos courses du ${formatDate(period.from)}.` : `Vos courses du ${formatDate(period.from)} au ${formatDate(period.to)}.` }}</p>
    </div>

    <InstallBanner app="marchand" />

    <!-- Choix de la période -->
    <div class="-mx-4 px-4 flex gap-2 overflow-x-auto no-scrollbar" role="radiogroup" aria-label="Période">
      <button
        v-for="p in presets"
        :key="p.key"
        type="button"
        role="radio"
        :aria-checked="period.key === p.key"
        :class="['tap shrink-0 rounded-full px-4 py-2 text-sm font-medium ring-1', period.key === p.key ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
        :style="period.key === p.key ? { backgroundColor: 'var(--app-color)' } : {}"
        @click="choose(p)"
      >
        {{ p.label }}
      </button>
      <button
        type="button"
        role="radio"
        :aria-checked="period.key === 'custom'"
        :class="['tap shrink-0 rounded-full px-4 py-2 text-sm font-medium ring-1', period.key === 'custom' ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
        :style="period.key === 'custom' ? { backgroundColor: 'var(--app-color)' } : {}"
        @click="openPicker"
      >
        📅 {{ period.key === 'custom' ? period.label : 'Choisir les dates' }}
      </button>
    </div>

    <!-- Résumé de la période -->
    <section v-if="summary" class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-80">{{ period.label }}</p>
      <p :class="['text-3xl font-bold mt-1', summary.amounts.net_to_merchant < 0 ? 'opacity-90' : '']">{{ money(summary.amounts.net_to_merchant) }}</p>
      <p class="text-sm opacity-80">
        {{ summary.amounts.net_to_merchant < 0 ? 'à régler à l\'agence' : 'à recevoir' }} · {{ money(summary.amounts.collected) }} encaissés
      </p>
      <div class="grid grid-cols-4 gap-2 mt-4 text-center">
        <div><p class="text-xl font-bold">{{ summary.counts.total }}</p><p class="text-xs opacity-80">Courses</p></div>
        <div><p class="text-xl font-bold">{{ summary.counts.in_progress }}</p><p class="text-xs opacity-80">En cours</p></div>
        <div><p class="text-xl font-bold">{{ summary.counts.delivered }}</p><p class="text-xs opacity-80">Livrées</p></div>
        <div><p class="text-xl font-bold">{{ summary.counts.failed + summary.counts.rescheduled }}</p><p class="text-xs opacity-80">Problèmes</p></div>
      </div>
    </section>

    <!-- Courses qui demandent une action (quelle que soit la date) -->
    <section v-if="alerts.length" class="space-y-2">
      <h2 class="font-semibold text-red-700">⚠️ À traiter ({{ alerts.length }})</h2>
      <RouterLink
        v-for="o in visibleAlerts"
        :key="o.id"
        :to="`/marchand/courses/${o.id}`"
        class="block rounded-2xl bg-red-50 ring-1 ring-red-200 p-4 active:bg-red-100"
      >
        <div class="flex justify-between gap-2">
          <p class="font-semibold">{{ o.recipient.name || o.recipient.phone }}</p>
          <span class="text-sm text-red-700 font-medium">Agir →</span>
        </div>
        <p class="text-sm text-red-700">{{ o.last_incident?.label || o.status_label }}</p>
        <p class="text-xs text-slate-500 mt-1">{{ o.tracking_code }} · {{ o.delivery.zone_name }}</p>
      </RouterLink>
      <button v-if="alerts.length > visibleAlerts.length" type="button" class="w-full text-sm font-medium text-red-700 py-1" @click="showAllAlerts = true">
        Voir les {{ alerts.length - visibleAlerts.length }} autres →
      </button>
    </section>

    <!-- Stock : alerte de stock bas -->
    <RouterLink
      v-if="stock.total"
      :to="stock.low ? '/marchand/stock?filtre=bas' : '/marchand/stock'"
      :class="['tap block m-card p-4 flex items-center justify-between text-sm active:bg-slate-50', stock.low ? 'ring-2 ring-amber-300' : '']"
    >
      <span>📦 Mon stock · {{ stock.total }} produit{{ stock.total > 1 ? 's' : '' }}</span>
      <span :class="stock.low ? 'font-semibold text-amber-700' : 'text-slate-500'">{{ stock.low ? `⚠️ ${stock.low} en stock bas` : 'Voir' }} →</span>
    </RouterLink>

    <!-- Rappel du mois quand on regarde une autre période -->
    <button v-if="month && period.key !== 'month'" type="button" class="tap w-full m-card p-4 flex items-center justify-between text-sm active:bg-slate-50" @click="choose(presets.find((p) => p.key === 'month'))">
      <span class="text-slate-500">Ce mois-ci · {{ month.counts.total }} courses</span>
      <span class="font-semibold">{{ money(month.amounts.net_to_merchant) }} →</span>
    </button>

    <!-- Point de la période : argent -->
    <section v-if="summary && (summary.amounts.collected || summary.amounts.fees || summary.amounts.shipping_fees || summary.amounts.other_fees)" class="m-card p-4 space-y-2 text-sm">
      <h2 class="font-semibold text-base">{{ isToday ? 'Point du jour' : 'Point de la période' }}</h2>
      <div class="flex justify-between"><span class="text-slate-500">Encaissé</span><span>{{ money(summary.amounts.collected) }}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Frais de livraison</span><span>− {{ money(summary.amounts.fees) }}</span></div>
      <div v-if="summary.amounts.shipping_fees" class="flex justify-between">
        <span class="text-slate-500">🚌 Frais d'expédition ({{ summary.counts.shipped }} colis)</span><span>− {{ money(summary.amounts.shipping_fees) }}</span>
      </div>
      <div v-if="summary.amounts.other_fees" class="flex justify-between">
        <span class="text-slate-500">💸 Autres frais (transport, emballage…)</span><span>− {{ money(summary.amounts.other_fees) }}</span>
      </div>
      <div class="flex justify-between font-semibold text-base border-t pt-2">
        <span>{{ isToday ? 'Net du jour' : 'Net de la période' }}</span><span :class="signedClass(summary.amounts.net_to_merchant)">{{ money(summary.amounts.net_to_merchant) }}</span>
      </div>
    </section>

    <!-- Courses de la période -->
    <section class="space-y-2">
      <div class="flex justify-between items-center">
        <h2 class="font-semibold">{{ isToday ? 'Courses du jour' : 'Courses de la période' }}<span v-if="total" class="text-slate-400 font-normal"> ({{ total }})</span></h2>
        <RouterLink to="/marchand/courses" class="text-sm font-medium text-[var(--app-color)]">Toutes mes courses</RouterLink>
      </div>
      <OrderCard v-for="o in orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
      <button v-if="hasMore" type="button" class="m-btn-secondary" :disabled="loadingMore" @click="loadOrders(page + 1)">Voir plus</button>
      <EmptyState
        v-if="loaded && !orders.length"
        icon="📦"
        :title="isToday ? 'Aucune course aujourd\'hui' : 'Aucune course sur cette période'"
        :text="isToday ? 'Créez une course en un instant.' : 'Choisissez d\'autres dates.'"
      >
        <RouterLink v-if="isToday" to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
      </EmptyState>
    </section>

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
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { date as formatDate, money, signedClass, today as todayDate } from '../../utils/format'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const firstName = computed(() => (auth.user?.name || '').split(' ')[0])

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

watch(orderChanges, load)
// Résumé du stock (seulement si le marchand a des produits)
const stock = reactive({ total: 0, low: 0 })
async function loadStock() {
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

onMounted(() => {
  load()
  loadStock()
})
</script>
