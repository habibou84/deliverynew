<template>
  <div class="space-y-4">
    <!-- Disponibilité -->
    <button
      :class="['tap w-full rounded-2xl p-4 flex items-center gap-4 text-left transition', available ? 'bg-emerald-700 text-white' : 'bg-white ring-1 ring-slate-200']"
      :aria-pressed="available"
      @click="toggleAvailability"
    >
      <span :class="['h-8 w-14 rounded-full p-1 transition', available ? 'bg-white/30' : 'bg-slate-200']">
        <span :class="['block h-6 w-6 rounded-full bg-white shadow transition', available ? 'translate-x-6' : '']" />
      </span>
      <span class="flex-1">
        <span class="block font-semibold text-lg">{{ available ? 'Je suis en service' : 'Je suis hors service' }}</span>
        <span :class="['block text-sm', available ? 'text-white/80' : 'text-slate-600']">{{ available ? 'Vous recevez des missions' : 'Touchez pour commencer votre journée' }}</span>
      </span>
    </button>

    <InstallBanner app="livreur" popup />

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

    <!-- Bons de retour à remettre aux marchands -->
    <RouterLink v-for="s in returnSlips" :key="s.id" :to="`/livreur/retours/${s.id}`" class="tap block rounded-2xl bg-rose-600 text-white p-4 active:bg-rose-700">
      <span class="block font-semibold">📮 {{ s.orders_count }} colis à rendre à {{ s.merchant.business_name }}</span>
      <span class="block text-sm text-white/80">Bon {{ s.reference }} · faites signer le marchand à la remise →</span>
    </RouterLink>

    <!-- Relais : colis à remettre à un autre livreur ou à recevoir de lui -->
    <RouterLink v-if="relays" to="/livreur/relais" class="tap block rounded-2xl bg-violet-600 text-white p-4 active:bg-violet-700">
      <span class="block font-semibold">🤝 {{ relays }} colis en relais avec un autre livreur</span>
      <span class="block text-sm text-white/80">À remettre ou à recevoir avec un code avant de partir livrer →</span>
    </RouterLink>

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

    <p v-if="tab === 'pickup' && !error && !loading" class="px-1 text-xs text-slate-600">
      Ramassages du jour et en avance. Un colis en retard revient ici quand sa nouvelle date est fixée.
    </p>
    <p v-if="loading" class="text-center text-slate-600 py-8">Chargement…</p>
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

    <!-- Tournée : ordre de passage des livraisons, trajet dans Google Maps -->
    <section v-if="tab === 'delivery' && visible.length && !loading" class="m-card p-3 space-y-2">
      <template v-if="!reordering">
        <div class="grid grid-cols-2 gap-2">
          <button v-if="visible.length > 1" type="button" class="tap rounded-xl bg-[var(--app-color)] px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-60" :disabled="routing" @click="organizeRoute">
            {{ routing ? 'Calcul…' : '🧭 Organiser ma tournée' }}
          </button>
          <button v-if="visible.length > 1" type="button" class="tap rounded-xl bg-slate-100 px-3 py-2.5 text-sm font-semibold active:bg-slate-200" @click="startReorder">✋ Modifier l'ordre</button>
          <a v-if="routeLink" :href="routeLink" target="_blank" rel="noopener" class="tap col-span-2 rounded-xl bg-slate-100 px-3 py-2.5 text-center text-sm font-semibold active:bg-slate-200">
            🗺️ Trajet dans Google Maps ({{ Math.min(visible.length, MAPS_MAX_STOPS) }} arrêt{{ Math.min(visible.length, MAPS_MAX_STOPS) > 1 ? 's' : '' }})
          </a>
        </div>
        <p v-if="visible.length > MAPS_MAX_STOPS" class="text-xs text-slate-600">Google Maps n'accepte que {{ MAPS_MAX_STOPS }} arrêts : le trajet s'arrête au {{ MAPS_MAX_STOPS }}ᵉ. Relancez-le après ces livraisons.</p>
        <p v-if="unlocated" class="text-xs text-slate-600">📍 {{ unlocated }} livraison{{ unlocated > 1 ? 's' : '' }} sans position GPS : placée{{ unlocated > 1 ? 's' : '' }} à la fin, par commune. Déplacez-les si besoin.</p>
      </template>
      <template v-else>
        <p class="text-sm font-semibold">Rangez vos livraisons dans l'ordre de passage</p>
        <ol class="divide-y rounded-xl border">
          <li v-for="(m, i) in draft" :key="m.id" class="flex items-center gap-2 p-2">
            <span class="h-7 w-7 shrink-0 rounded-full bg-[var(--app-color)] text-center text-sm font-bold leading-7 text-white">{{ i + 1 }}</span>
            <span class="flex-1 min-w-0">
              <span class="block truncate text-sm font-medium">{{ m.order.recipient.name || m.order.recipient.phone }}</span>
              <span class="block truncate text-xs text-slate-600">{{ m.order.delivery.zone_name }} · {{ m.order.delivery.address }}<template v-if="!(m.order.delivery.lat && m.order.delivery.lng)"> · sans position</template></span>
            </span>
            <button type="button" class="tap h-10 w-10 rounded-lg bg-slate-100 text-lg disabled:opacity-30" :disabled="i === 0" :aria-label="`Monter ${m.order.recipient.name || ''}`" @click="move(i, -1)">↑</button>
            <button type="button" class="tap h-10 w-10 rounded-lg bg-slate-100 text-lg disabled:opacity-30" :disabled="i === draft.length - 1" :aria-label="`Descendre ${m.order.recipient.name || ''}`" @click="move(i, 1)">↓</button>
          </li>
        </ol>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="m-btn-secondary" :disabled="routing" @click="reordering = false">Annuler</button>
          <button type="button" class="m-btn-primary" :disabled="routing" @click="saveRoute(draft)">{{ routing ? '…' : 'Enregistrer' }}</button>
        </div>
      </template>
    </section>

    <!-- Plusieurs colis chez la même boutique : une seule mission -->
    <template v-for="(c, idx) in (reordering ? [] : cards)" :key="c.key">
      <RouterLink v-if="c.group" :to="`/livreur/ramassage/${c.key}`" class="block m-card p-4 active:bg-slate-50">
        <div class="flex items-start gap-3">
          <span class="h-11 w-11 shrink-0 rounded-xl grid place-items-center text-xl bg-indigo-100">🏪</span>
          <div class="flex-1 min-w-0">
            <p class="font-semibold truncate">{{ c.first.order.merchant?.business_name || c.first.order.pickup.contact_name }} : {{ c.missions.length }} colis</p>
            <p class="text-sm text-slate-600 truncate">📍 {{ c.first.order.pickup.zone_name }} · {{ c.first.order.pickup.address }}</p>
            <div class="flex flex-wrap items-center gap-2 mt-2">
              <span v-if="c.toAccept" class="rounded-full bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5">{{ c.toAccept }} à accepter</span>
              <span v-if="c.started" class="rounded-full bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-0.5">En route</span>
              <span v-if="c.offline" class="rounded-full bg-sky-100 text-sky-800 text-xs font-semibold px-2 py-0.5">⏳ À envoyer</span>
              <span v-if="c.express" class="rounded-full bg-red-100 text-red-700 text-xs font-semibold px-2 py-0.5">⚡ Express</span>
              <span v-if="c.gain" class="rounded-full bg-emerald-100 text-emerald-800 text-xs font-semibold px-2 py-0.5" title="Votre gain prévu">💰 + {{ money(c.gain) }}</span>
            </div>
          </div>
          <span class="text-slate-600 text-xl">›</span>
        </div>
      </RouterLink>
      <RouterLink v-else :to="`/livreur/missions/${c.first.id}`" class="block m-card p-4 active:bg-slate-50">
        <div class="flex items-start gap-3">
          <span :class="['relative h-11 w-11 shrink-0 rounded-xl grid place-items-center text-xl', c.first.type === 'pickup' ? 'bg-indigo-100' : c.first.type === 'delivery' ? 'bg-blue-100' : 'bg-rose-100']">
            {{ c.first.type === 'pickup' ? '📦' : c.first.type === 'delivery' ? '🛵' : '↩️' }}
            <!-- Place dans la tournée -->
            <span v-if="c.first.type === 'delivery' && routeSet" class="absolute -right-1.5 -top-1.5 h-6 min-w-6 rounded-full bg-[var(--app-color)] px-1 text-center text-xs font-bold leading-6 text-white">{{ idx + 1 }}</span>
          </span>
          <div class="flex-1 min-w-0">
            <p class="font-semibold truncate">
              {{ c.first.type === 'pickup' ? c.first.order.merchant?.business_name : (c.first.order.recipient.name || c.first.order.recipient.phone) }}
              <span v-if="c.first.unread_messages" class="ml-1 rounded-full bg-sky-600 px-2 py-0.5 text-xs text-white">💬 {{ c.first.unread_messages }}</span>
              <span v-if="c.missions.some((m) => m.relay_from)" class="ml-1 rounded-full bg-violet-600 px-2 py-0.5 text-xs text-white">🤝 Relais</span>
              <span v-if="c.missions.some((m) => m.last_edit && !m.last_edit.seen)" class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs text-white">⚠️ Modifiée</span>
            </p>
            <p class="text-sm text-slate-600 truncate">
              📍 {{ c.first.type === 'pickup' ? c.first.order.pickup.zone_name : c.first.order.delivery.zone_name }}
              · {{ c.first.type === 'pickup' ? c.first.order.pickup.address : c.first.order.delivery.address }}
            </p>
            <div class="flex flex-wrap items-center gap-2 mt-2">
              <span v-if="c.first.status === 'assigned'" class="rounded-full bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5">Nouvelle · à accepter</span>
              <StatusBadge v-else :status="c.first.order.status" :label="c.first.order.status_label" />
              <span v-if="c.first.offline" class="rounded-full bg-sky-100 text-sky-800 text-xs font-semibold px-2 py-0.5">⏳ À envoyer</span>
              <span v-if="c.first.order.package.is_express" class="rounded-full bg-red-100 text-red-700 text-xs font-semibold px-2 py-0.5">⚡ Express</span>
              <span v-if="c.first.order.is_shipping && c.first.type === 'delivery'" class="rounded-full bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-0.5">🚌 À expédier</span>
              <span v-if="c.first.gain" class="rounded-full bg-emerald-100 text-emerald-800 text-xs font-semibold px-2 py-0.5" title="Votre gain prévu">💰 + {{ money(c.first.gain) }}</span>
            </div>
          </div>
          <span v-if="c.first.type === 'delivery' && c.first.order.amounts.cod_amount" class="font-bold whitespace-nowrap">{{ money(c.first.order.amounts.cod_amount) }}</span>
        </div>
      </RouterLink>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { cachedGet } from '../../composables/useCachedApi'
import { withPending } from '../../composables/useOfflineQueue'
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
import { googleMapsRoute, MAPS_MAX_STOPS, nearestNeighborRoute } from '../../utils/route'

const auth = useAuthStore()
const notifications = useNotificationStore()
const toasts = useToastStore()
const missions = ref([])
const wallet = ref(null)
const returnSlips = ref([])
const relays = ref(0)
const loading = ref(true)
const stale = ref(false)
const error = ref('')
const available = ref(auth.user?.courier?.is_available ?? false)
const tab = ref('pickup')
const tabs = [
  { value: 'pickup', label: 'À ramasser' },
  { value: 'delivery', label: 'À livrer' },
  { value: 'return', label: 'À retourner' },
]

// Livraisons : dans l'ordre de la tournée choisie par le livreur (non placées à la fin)
const visible = computed(() => {
  const list = missions.value.filter((m) => m.type === tab.value)
  if (tab.value !== 'delivery') return list
  return list.map((m, i) => [m, i]).sort(([a, i], [b, j]) => (a.route_rank ?? Infinity) - (b.route_rank ?? Infinity) || i - j).map(([m]) => m)
})
// Ramassages : colis d'une même boutique (même adresse) regroupés en une carte
const cards = computed(() => {
  const groups = new Map()
  for (const m of visible.value) {
    const key = m.type === 'pickup' && m.pickup_group ? m.pickup_group : `m${m.id}`
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(m)
  }
  return [...groups].map(([key, list]) => ({
    key,
    group: list.length > 1,
    first: list[0],
    missions: list,
    toAccept: list.filter((m) => m.status === 'assigned').length,
    started: list.some((m) => m.order.status === 'pickup_in_progress'),
    offline: list.some((m) => m.offline),
    express: list.some((m) => m.order.package.is_express),
    gain: list.reduce((sum, m) => sum + (m.gain || 0), 0),
  }))
})
const count = (type) => missions.value.filter((m) => m.type === type).length

async function load() {
  try {
    const [m, w, r] = await Promise.all([cachedGet('/courier/missions'), cachedGet('/courier/wallet'), cachedGet('/courier/return-slips').catch(() => null)])
    returnSlips.value = r?.data.data || []
    missions.value = withPending(m.data.data)
    messages.setUnread(m.data.meta?.unread_messages)
    relays.value = m.data.meta?.relays || 0
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

// Tournée : de proche en proche (position du téléphone), à la main, puis enregistrée pour la journée
const reordering = ref(false)
const routing = ref(false)
const draft = ref([])
const routeSet = computed(() => visible.value.some((m) => m.type === 'delivery' && m.route_rank != null))
const routeLink = computed(() => (tab.value === 'delivery' ? googleMapsRoute(visible.value) : null))
const unlocated = computed(() => (tab.value === 'delivery' ? visible.value.filter((m) => !(m.order.delivery.lat && m.order.delivery.lng)).length : 0))

async function saveRoute(ordered, message = 'Ordre de la tournée enregistré.') {
  routing.value = true
  try {
    await http.put('/courier/route', { assignment_ids: ordered.map((m) => m.id) })
    ordered.forEach((m, i) => { m.route_rank = i + 1 })
    reordering.value = false
    toasts.success(message)
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'Impossible d\'enregistrer la tournée.'))
  } finally {
    routing.value = false
  }
}

async function organizeRoute() {
  routing.value = true
  const position = await currentPosition(5000)
  routing.value = false
  const { ordered, located } = nearestNeighborRoute(visible.value, position)
  if (!located) {
    toasts.error('Aucune livraison n\'a de position GPS : rangez-les à la main avec « Modifier l\'ordre ».')
    return
  }
  await saveRoute(ordered, position ? 'Tournée organisée de proche en proche depuis votre position.' : 'Tournée organisée (position du téléphone indisponible : départ de la première livraison).')
}

function startReorder() {
  draft.value = [...visible.value]
  reordering.value = true
}

function move(i, step) {
  const list = [...draft.value]
  ;[list[i], list[i + step]] = [list[i + step], list[i]]
  draft.value = list
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
watch(tab, () => { reordering.value = false })

// Actions faites hors connexion envoyées : liste rechargée
window.addEventListener('offline-synced', load)
onBeforeUnmount(() => window.removeEventListener('offline-synced', load))

onMounted(() => {
  load()
  initPush()
})
</script>
