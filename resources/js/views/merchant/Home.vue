<template>
  <div class="space-y-5">
    <div>
      <p class="text-2xl font-bold">Bonjour {{ firstName }} 👋</p>
      <p class="text-slate-500">Voici vos livraisons du jour.</p>
    </div>

    <InstallBanner app="marchand" />

    <!-- Courses qui demandent une action -->
    <section v-if="alerts.length" class="space-y-2">
      <h2 class="font-semibold text-red-700">⚠️ À traiter ({{ alerts.length }})</h2>
      <RouterLink
        v-for="o in alerts"
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
    </section>

    <!-- Aujourd'hui -->
    <section v-if="today" class="grid grid-cols-3 gap-2">
      <div class="m-card p-3 text-center">
        <p class="text-2xl font-bold">{{ today.counts.in_progress }}</p>
        <p class="text-xs text-slate-500">En cours</p>
      </div>
      <div class="m-card p-3 text-center">
        <p class="text-2xl font-bold text-emerald-700">{{ today.counts.delivered }}</p>
        <p class="text-xs text-slate-500">Livrées</p>
      </div>
      <div class="m-card p-3 text-center">
        <p class="text-2xl font-bold text-red-600">{{ today.counts.failed + today.counts.rescheduled }}</p>
        <p class="text-xs text-slate-500">Problèmes</p>
      </div>
    </section>

    <!-- Point du jour : argent -->
    <section v-if="today && (today.amounts.collected || today.amounts.fees || today.amounts.shipping_fees)" class="m-card p-4 space-y-2 text-sm">
      <h2 class="font-semibold text-base">Point du jour</h2>
      <div class="flex justify-between"><span class="text-slate-500">Encaissé</span><span>{{ money(today.amounts.collected) }}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Frais de livraison</span><span>− {{ money(today.amounts.fees) }}</span></div>
      <div v-if="today.amounts.shipping_fees" class="flex justify-between">
        <span class="text-slate-500">🚌 Frais d'expédition ({{ today.counts.shipped }} colis)</span><span>− {{ money(today.amounts.shipping_fees) }}</span>
      </div>
      <div class="flex justify-between font-semibold text-base border-t pt-2">
        <span>Net du jour</span><span :class="signedClass(today.amounts.net_to_merchant)">{{ money(today.amounts.net_to_merchant) }}</span>
      </div>
    </section>

    <!-- Ce mois-ci -->
    <section v-if="month" class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-80">Ce mois-ci</p>
      <p class="text-3xl font-bold mt-1">{{ money(month.amounts.net_to_merchant) }}</p>
      <p class="text-sm opacity-80">à recevoir sur {{ money(month.amounts.collected) }} encaissés</p>
      <div class="flex gap-4 mt-3 text-sm">
        <span>📦 {{ month.counts.total }} courses</span>
        <span>✅ {{ month.delivery_rate ?? 0 }} % livrées</span>
      </div>
    </section>

    <!-- Dernières courses -->
    <section class="space-y-2">
      <div class="flex justify-between items-center">
        <h2 class="font-semibold">Dernières courses</h2>
        <RouterLink to="/marchand/courses" class="text-sm font-medium text-[var(--app-color)]">Tout voir</RouterLink>
      </div>
      <OrderCard v-for="o in recent" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
      <EmptyState v-if="loaded && !recent.length" icon="📦" title="Aucune course pour le moment" text="Créez votre première course en un instant.">
        <RouterLink to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
      </EmptyState>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { cachedGet } from '../../composables/useCachedApi'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { money, signedClass, today as todayDate } from '../../utils/format'

const auth = useAuthStore()
const firstName = computed(() => (auth.user?.name || '').split(' ')[0])

const today = ref(null)
const month = ref(null)
const alerts = ref([])
const recent = ref([])
const loaded = ref(false)

async function load() {
  const d = todayDate()
  try {
    const [t, m, a, r] = await Promise.all([
      cachedGet('/reports/summary', { params: { from: d, to: d } }),
      cachedGet('/reports/summary', { params: { from: `${d.slice(0, 8)}01`, to: d } }),
      cachedGet('/orders', { params: { status: ['delivery_failed'], per_page: 10 } }),
      cachedGet('/orders', { params: { per_page: 5 } }),
    ])
    today.value = t.data.data
    month.value = m.data.data
    alerts.value = a.data.data
    recent.value = r.data.data
  } catch {
    // Hors ligne sans données en cache : l'accueil reste vide, le bandeau hors ligne s'affiche
  } finally {
    loaded.value = true
  }
}

watch(orderChanges, load)
onMounted(load)
</script>
