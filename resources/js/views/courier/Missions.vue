<template>
  <div class="space-y-4">
    <!-- Disponibilité -->
    <button
      :class="['tap w-full rounded-2xl p-4 flex items-center gap-4 text-left transition', available ? 'bg-emerald-600 text-white' : 'bg-white ring-1 ring-slate-200']"
      :aria-pressed="available"
      @click="toggleAvailability"
    >
      <span :class="['h-8 w-14 rounded-full p-1 transition', available ? 'bg-white/30' : 'bg-slate-200']">
        <span :class="['block h-6 w-6 rounded-full bg-white shadow transition', available ? 'translate-x-6' : '']" />
      </span>
      <span class="flex-1">
        <span class="block font-semibold text-lg">{{ available ? 'Je suis en service' : 'Je suis hors service' }}</span>
        <span :class="['block text-sm', available ? 'text-white/80' : 'text-slate-500']">{{ available ? 'Vous recevez des missions' : 'Touchez pour commencer votre journée' }}</span>
      </span>
    </button>

    <InstallBanner app="livreur" />

    <!-- Invitation à activer les notifications push -->
    <div v-if="showPushInvite" class="rounded-2xl bg-amber-50 ring-1 ring-amber-200 p-4 space-y-2">
      <p class="font-semibold text-amber-900">🔔 Soyez prévenu même téléphone en veille</p>
      <p class="text-sm text-amber-900">Nouvelles missions et consignes de l'agence, sans garder l'application ouverte.</p>
      <p v-if="push.error" class="text-sm text-red-600">{{ push.error }}</p>
      <div class="flex gap-2">
        <button type="button" class="m-btn-primary py-2" :disabled="push.busy" @click="enablePush">Activer les notifications</button>
        <button type="button" class="m-btn-secondary w-auto px-4 py-2" @click="dismissPushInvite">Plus tard</button>
      </div>
    </div>

    <!-- Consignes de l'agence non lues -->
    <RouterLink v-if="messages.unread" to="/livreur/messages" class="tap block rounded-2xl bg-sky-600 text-white p-4 font-semibold active:bg-sky-700">
      💬 {{ messages.unread }} consigne{{ messages.unread > 1 ? 's' : '' }} de l'agence à lire →
    </RouterLink>

    <!-- À verser -->
    <RouterLink v-if="wallet && wallet.cash_in_hand !== 0" to="/livreur/caisse" class="block m-card p-4 active:bg-slate-50">
      <div class="flex justify-between items-center">
        <span class="text-slate-600">{{ wallet.cash_in_hand > 0 ? '💵 À verser à la caisse' : '💵 La caisse vous doit' }}</span>
        <span class="font-bold text-lg">{{ money(Math.abs(wallet.cash_in_hand)) }}</span>
      </div>
    </RouterLink>

    <!-- Onglets -->
    <div class="flex rounded-xl bg-slate-200 p-1">
      <button v-for="t in tabs" :key="t.value" :class="['tap flex-1 rounded-lg py-2.5 text-sm font-semibold', tab === t.value ? 'bg-white shadow-sm text-[var(--app-color)]' : 'text-slate-600']" @click="tab = t.value">
        {{ t.label }}
        <span :class="['ml-1 rounded-full px-1.5 text-xs', count(t.value) ? 'bg-[var(--app-color)] text-white' : 'bg-slate-300 text-slate-600']">{{ count(t.value) }}</span>
      </button>
    </div>

    <p v-if="stale" class="rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">
      Hors ligne : voici vos missions telles qu'elles étaient à la dernière connexion.
    </p>

    <p v-if="loading" class="text-center text-slate-400 py-8">Chargement…</p>
    <div v-else-if="error && !missions.length" class="text-center py-8 space-y-3">
      <p class="text-slate-600">{{ error }}</p>
      <button class="m-btn m-btn-secondary" @click="load">Réessayer</button>
    </div>
    <EmptyState
      v-else-if="!visible.length"
      icon="✅"
      :title="tab === 'pickup' ? 'Aucun ramassage en attente' : tab === 'delivery' ? 'Aucune livraison en attente' : 'Aucun retour'"
      text="Les nouvelles missions apparaissent ici automatiquement."
    />

    <RouterLink v-for="m in visible" :key="m.id" :to="`/livreur/missions/${m.id}`" class="block m-card p-4 active:bg-slate-50">
      <div class="flex items-start gap-3">
        <span :class="['h-11 w-11 shrink-0 rounded-xl grid place-items-center text-xl', m.type === 'pickup' ? 'bg-indigo-100' : m.type === 'delivery' ? 'bg-blue-100' : 'bg-rose-100']">
          {{ m.type === 'pickup' ? '📦' : m.type === 'delivery' ? '🛵' : '↩️' }}
        </span>
        <div class="flex-1 min-w-0">
          <p class="font-semibold truncate">
            {{ m.type === 'pickup' ? m.order.merchant?.business_name : (m.order.recipient.name || m.order.recipient.phone) }}
            <span v-if="m.unread_messages" class="ml-1 rounded-full bg-sky-600 px-2 py-0.5 text-xs text-white">💬 {{ m.unread_messages }}</span>
          </p>
          <p class="text-sm text-slate-500 truncate">
            📍 {{ m.type === 'pickup' ? m.order.pickup.zone_name : m.order.delivery.zone_name }}
            · {{ m.type === 'pickup' ? m.order.pickup.address : m.order.delivery.address }}
          </p>
          <div class="flex flex-wrap items-center gap-2 mt-2">
            <span v-if="m.status === 'assigned'" class="rounded-full bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5">Nouvelle · à accepter</span>
            <StatusBadge v-else :status="m.order.status" :label="m.order.status_label" />
            <span v-if="m.order.package.is_express" class="rounded-full bg-red-100 text-red-700 text-xs font-semibold px-2 py-0.5">⚡ Express</span>
            <span v-if="m.order.is_shipping && m.type === 'delivery'" class="rounded-full bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-0.5">🚌 À expédier</span>
          </div>
        </div>
        <span v-if="m.type === 'delivery' && m.order.amounts.cod_amount" class="font-bold whitespace-nowrap">{{ money(m.order.amounts.cod_amount) }}</span>
      </div>
    </RouterLink>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { cachedGet } from '../../composables/useCachedApi'
import { useToastStore } from '../../stores/toasts'
import StatusBadge from '../../components/StatusBadge.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import { useAuthStore } from '../../stores/auth'
import { useNotificationStore } from '../../stores/notifications'
import { currentPosition } from '../../composables/useGeolocation'
import { useCourierMessageStore } from '../../stores/courierMessages'
import { enablePush, initPush, push } from '../../composables/usePush'
import { money } from '../../utils/format'

const auth = useAuthStore()
const notifications = useNotificationStore()
const toasts = useToastStore()
const missions = ref([])
const wallet = ref(null)
const loading = ref(true)
const stale = ref(false)
const error = ref('')
const available = ref(auth.user?.courier?.is_available ?? false)
const tab = ref('pickup')
const tabs = [
  { value: 'pickup', label: 'Ramasser' },
  { value: 'delivery', label: 'Livrer' },
  { value: 'return', label: 'Retours' },
]

const visible = computed(() => missions.value.filter((m) => m.type === tab.value))
const count = (type) => missions.value.filter((m) => m.type === type).length

async function load() {
  try {
    const [m, w] = await Promise.all([cachedGet('/courier/missions'), cachedGet('/courier/wallet')])
    missions.value = m.data.data
    messages.setUnread(m.data.meta?.unread_messages)
    wallet.value = w.data.data
    stale.value = m.stale || w.stale
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger vos missions.')
  } finally {
    loading.value = false
  }
  if (!count(tab.value)) tab.value = ['pickup', 'delivery', 'return'].find((t) => count(t)) || tab.value
}

async function toggleAvailability() {
  try {
    const position = await currentPosition()
    const { data } = await http.patch('/courier/status', { is_available: !available.value, ...(position || {}) })
    available.value = data.data.is_available
    if (auth.user.courier) auth.user.courier.is_available = available.value
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

// Nouvelle mission reçue en temps réel
const messages = useCourierMessageStore()

// Invitation masquée 3 jours après « Plus tard »
const pushInviteHiddenUntil = ref(Number((() => { try { return localStorage.getItem('push-invite-hidden') } catch { return 0 } })() || 0))
const showPushInvite = computed(() => push.checked && push.supported && push.serverEnabled && !push.subscribed
  && push.permission !== 'denied' && Date.now() > pushInviteHiddenUntil.value)
function dismissPushInvite() {
  pushInviteHiddenUntil.value = Date.now() + 3 * 86400000
  try { localStorage.setItem('push-invite-hidden', String(pushInviteHiddenUntil.value)) } catch { /* stockage indisponible */ }
}

watch(() => notifications.unread, load)

onMounted(() => {
  load()
  initPush()
})
</script>
