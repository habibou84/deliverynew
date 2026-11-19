<template>
  <div class="space-y-4">
    <InstallBanner app="livreur" popup />

    <!-- Invitation à activer les notifications push -->
    <div v-if="showPushInvite" class="rounded-2xl bg-amber-50 ring-1 ring-amber-200 p-4 space-y-2">
      <p class="flex items-center gap-2 font-semibold text-amber-900"><Icone nom="notifications" />Soyez prévenu même téléphone en veille</p>
      <p class="text-sm text-amber-900">Nouvelles missions et consignes de l'agence, sans garder l'application ouverte.</p>
      <p v-if="push.error" class="text-sm text-red-600">{{ push.error }}</p>
      <div class="flex gap-2">
        <button type="button" class="m-btn-primary py-2" :disabled="push.busy" @click="enablePush">Activer les notifications</button>
        <button type="button" class="m-btn-secondary w-auto px-4 py-2" @click="dismissPushInvite">Plus tard</button>
      </div>
    </div>

    <!-- Bons de retour à remettre aux marchands -->
    <RouterLink v-for="s in returnSlips" :key="s.id" :to="`/livreur/retours/${s.id}`" class="tap flex items-center gap-3 rounded-2xl bg-rose-600 text-white p-4 active:bg-rose-700">
      <Icone nom="bon-retour" taille="grande" />
      <span class="flex-1">
        <span class="block font-semibold">{{ s.orders_count }} colis à rendre à {{ s.merchant.business_name }}</span>
        <span class="block text-sm text-white/80">Bon {{ s.reference }} · faites signer le marchand à la remise</span>
      </span>
      <Icone nom="ouvrir" />
    </RouterLink>

    <!-- Relais : colis à remettre à un autre livreur ou à recevoir de lui -->
    <RouterLink v-if="relays" to="/livreur/relais" class="tap flex items-center gap-3 rounded-2xl bg-violet-600 text-white p-4 active:bg-violet-700">
      <Icone nom="relais" taille="grande" />
      <span class="flex-1">
        <span class="block font-semibold">{{ relays }} colis en relais avec un autre livreur</span>
        <span class="block text-sm text-white/80">À remettre ou à recevoir avant de partir</span>
      </span>
      <Icone nom="ouvrir" />
    </RouterLink>

    <!-- Consignes de l'agence non lues -->
    <RouterLink v-if="messages.unread" to="/livreur/messages" class="tap flex items-center gap-3 rounded-2xl bg-sky-600 text-white p-4 font-semibold active:bg-sky-700">
      <Icone nom="message" taille="grande" />
      <span class="flex-1">{{ messages.unread }} consigne{{ messages.unread > 1 ? 's' : '' }} de l'agence à lire</span>
      <Icone nom="ouvrir" />
    </RouterLink>

    <!-- À verser -->
    <RouterLink v-if="wallet && wallet.cash_in_hand !== 0" to="/livreur/caisse" class="flex items-center justify-between m-card p-4 active:bg-slate-50">
      <span class="flex items-center gap-2 text-slate-600"><Icone nom="argent" />{{ wallet.cash_in_hand > 0 ? 'À verser à la caisse' : 'La caisse vous doit' }}</span>
      <span class="font-bold text-lg">{{ money(Math.abs(wallet.cash_in_hand)) }}</span>
    </RouterLink>

    <!-- Mon itinéraire : à part, au-dessus (il a un autre rôle : ce que je fais maintenant, dans l'ordre) -->
    <RouterLink to="/livreur/itineraire" class="tap flex items-center gap-3 rounded-2xl bg-[var(--app-color)] p-4 text-white shadow-md active:opacity-90">
      <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white/20"><Icone nom="itineraire" taille="grande" /></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[17px] font-bold">Mon itinéraire</span>
        <span class="block truncate text-[13px] text-white/90">{{ nextStopText }}</span>
      </span>
      <Icone nom="ouvrir" taille="grande" />
    </RouterLink>

    <!-- À ramasser, À livrer, À retourner : trois grandes tuiles -->
    <div class="grid grid-cols-3 gap-2" role="tablist" aria-label="Missions">
      <button
        v-for="t in TABS"
        :key="t.value"
        type="button"
        role="tab"
        :aria-selected="tab === t.value"
        :class="['tap flex min-h-[104px] flex-col items-center justify-center gap-1.5 rounded-2xl border-2 bg-white px-1 shadow-sm', tab === t.value ? 'border-[var(--app-color)]' : 'border-transparent']"
        @click="tab = t.value"
      >
        <ColorIcon :name="t.art" :size="44" />
        <span :class="['whitespace-nowrap text-[15px] leading-tight', tab === t.value ? 'font-bold text-[var(--app-color)]' : 'font-semibold text-slate-900']">{{ t.label }}</span>
        <span class="text-[13px] text-slate-600">{{ count(t.value) }} à faire</span>
      </button>
    </div>

    <!-- Sous-onglets de la tuile choisie -->
    <div class="m-card overflow-hidden">
      <div v-if="subTabs.length" class="grid" :style="{ gridTemplateColumns: `repeat(${subTabs.length}, minmax(0, 1fr))` }" role="tablist" :aria-label="TABS.find((t) => t.value === tab).label">
        <button
          v-for="s in subTabs"
          :key="s.value"
          type="button"
          role="tab"
          :aria-selected="sub[tab] === s.value"
          :class="['tap flex min-h-[52px] items-center justify-center gap-1.5 border-b-[3px] px-1 py-1.5 text-center text-[14px] leading-tight', sub[tab] === s.value ? 'border-[var(--app-color)] font-bold text-[var(--app-color)]' : 'border-transparent font-medium text-slate-600']"
          @click="sub[tab] = s.value"
        >
          {{ s.label }}
          <span :class="['min-w-5 rounded-full px-1.5 text-[11px] font-bold leading-[18px]', sub[tab] === s.value ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-600']">{{ s.count }}</span>
        </button>
      </div>
    </div>

    <p v-if="stale" class="flex items-center gap-2 rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">
      <Icone nom="hors-ligne" />Hors ligne : voici vos missions telles qu'elles étaient à la dernière connexion.
    </p>

    <p v-if="loading" class="text-center text-slate-600 py-8">Chargement…</p>
    <div v-else-if="error && !missions.length" class="text-center py-8 space-y-3">
      <p class="text-slate-600">{{ error }}</p>
      <button class="m-btn m-btn-secondary" @click="load">Réessayer</button>
    </div>

    <!-- À RAMASSER, À LIVRER, À RETOURNER : listes de suivi -->
    <template v-else>
      <p class="px-1 text-[13px] text-slate-600">{{ SUMMARIES[`${tab}:${sub[tab]}`] }}</p>
      <EmptyState v-if="!cards.length" art="livre" :title="EMPTY[`${tab}:${sub[tab]}`]" text="La liste se met à jour toute seule." />

      <template v-for="c in cards" :key="c.key">
        <component
          :is="c.link ? RouterLink : 'div'"
          v-bind="c.link ? { to: c.link } : {}"
          :class="['block m-card p-4', c.link ? 'active:bg-slate-50' : '']"
        >
          <div class="flex items-start gap-3">
            <ColorIcon :name="c.art" :size="44" />
            <div class="flex-1 min-w-0">
              <div class="flex justify-between gap-2">
                <p class="font-semibold truncate">{{ c.title }}</p>
                <span v-if="c.cod" class="font-bold whitespace-nowrap">{{ money(c.cod) }}</span>
              </div>
              <p class="flex items-center gap-1 text-sm text-slate-600 truncate"><Icone nom="adresse" taille="petite" />{{ c.place }}</p>
              <div class="flex flex-wrap items-center gap-1.5 mt-2">
                <span v-if="c.toAccept" class="rounded-full bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5">{{ c.toAccept }} à accepter</span>
                <StatusBadge v-else-if="c.status" :status="c.status" :label="c.statusLabel" />
                <span v-if="c.done" :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold', c.failed ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800']">
                  <Icone :nom="c.failed ? 'echec' : 'livre'" taille="petite" />{{ c.done }}
                </span>
                <span v-if="c.stop" class="text-xs font-semibold text-[var(--app-color)]">Arrêt n° {{ c.stop }} de l'itinéraire</span>
                <MissionFlags :missions="c.missions" />
              </div>
            </div>
            <Icone v-if="c.link" nom="ouvrir" class="text-slate-400" />
          </div>
          <!-- Rappel : un retour à faire tout près de cette livraison -->
          <p v-if="c.nearby" class="mt-3 flex items-center gap-2 rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800">
            <Icone nom="a-proximite" taille="petite" />{{ c.nearby }}
          </p>
        </component>
      </template>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { apiErrorMessage } from '../../bootstrap/axios'
import { cachedGet } from '../../composables/useCachedApi'
import { withPending } from '../../composables/useOfflineQueue'
import StatusBadge from '../../components/StatusBadge.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import Icone from '../../components/ui/Icone.vue'
import MissionFlags from '../../components/courier/MissionFlags.vue'
import { useNotificationStore } from '../../stores/notifications'
import { useCourierMessageStore } from '../../stores/courierMessages'
import { enablePush, initPush, push } from '../../composables/usePush'
import { dateTime, money } from '../../utils/format'
import { buildStops, missionPlace, nearbyReturn } from '../../utils/route'

/**
 * Missions du livreur : en haut, son itinéraire (écran à part : tous les arrêts, dans l'ordre de passage) ; en
 * dessous, trois tuiles pour suivre ses ramassages, livraisons et retours (à faire, déjà faits, échecs).
 */
const TABS = [
  { value: 'pickup', label: 'À ramasser', art: 'boutique' },
  { value: 'delivery', label: 'À livrer', art: 'moto' },
  { value: 'return', label: 'À retourner', art: 'retour' },
]
const SUB_TABS = {
  pickup: [['todo', 'En attente de ramassage'], ['done', 'Déjà ramassés']],
  delivery: [['todo', 'En cours'], ['done', 'Déjà livrés'], ['failed', 'Échecs']],
  return: [['todo', 'En attente de retour'], ['done', 'Déjà retournés']],
}
const SUMMARIES = {
  'pickup:todo': 'Ramassages du jour et en avance. Leur ordre de passage se règle dans Mon itinéraire.',
  'pickup:done': 'Colis ramassés aujourd\'hui.',
  'delivery:todo': 'Livraisons du jour pas encore faites. Leur ordre de passage se règle dans Mon itinéraire.',
  'delivery:done': 'Colis livrés aujourd\'hui.',
  'delivery:failed': 'Échecs du jour : l\'agence décide de la suite (nouvelle date, retour).',
  'return:todo': 'Colis à rapporter aux e-commerçants. Leur ordre de passage se règle dans Mon itinéraire.',
  'return:done': 'Colis rendus aux e-commerçants ces 7 derniers jours.',
}
const EMPTY = {
  'pickup:todo': 'Aucun ramassage en attente', 'pickup:done': 'Aucun colis ramassé aujourd\'hui',
  'delivery:todo': 'Aucune livraison en attente', 'delivery:done': 'Aucun colis livré aujourd\'hui', 'delivery:failed': 'Aucun échec aujourd\'hui',
  'return:todo': 'Aucun retour en attente', 'return:done': 'Aucun colis retourné ces 7 derniers jours',
}

const notifications = useNotificationStore()
const messages = useCourierMessageStore()
const missions = ref([])
const history = ref([])
const wallet = ref(null)
const returnSlips = ref([])
const relays = ref(0)
const loading = ref(true)
const stale = ref(false)
const error = ref('')
const tab = ref('delivery')
const sub = reactive({ pickup: 'todo', delivery: 'todo', return: 'todo' })

const active = (type) => missions.value.filter((m) => m.type === type)
const finished = (type, status) => history.value.filter((m) => m.type === type && m.status === status)
const count = (t) => active(t).length

const subTabs = computed(() => (SUB_TABS[tab.value] || []).map(([value, label]) => ({
  value,
  label,
  count: value === 'todo' ? active(tab.value).length : finished(tab.value, value === 'done' ? 'completed' : 'failed').length,
})))

// Itinéraire : arrêts dans l'ordre enregistré ; place de chaque mission
const stops = computed(() => buildStops(missions.value))
const stopOf = computed(() => {
  const map = new Map()
  stops.value.forEach((s, i) => s.missions.forEach((m) => map.set(m.id, i + 1)))
  return map
})
// Bandeau : nombre d'arrêts et prochain arrêt
const nextStopText = computed(() => {
  const n = stops.value.length
  if (!n) return 'Aucun arrêt pour le moment'
  const next = stops.value[0]
  return `${n} arrêt${n > 1 ? 's' : ''} · prochain : ${next.name}${next.place.zone ? `, ${next.place.zone}` : ''}`
})

// Listes de suivi : à faire (missions en cours, ramassages d'une même boutique regroupés) ou terminées
const cards = computed(() => {
  const type = tab.value
  const which = sub[type]
  if (which !== 'todo') {
    return finished(type, which === 'done' ? 'completed' : 'failed').map((m) => ({
      key: `h${m.id}`,
      art: which === 'failed' ? 'echec' : 'livre',
      title: titleOf(m),
      place: placeOf(m),
      cod: type === 'delivery' ? m.order?.amounts?.cod_amount : 0,
      done: `${which === 'failed' ? (m.order?.status_label || 'Échec') : { pickup: 'Ramassé', delivery: 'Livré', return: 'Retourné' }[type]}${m.completed_at || m.updated_at ? ` · ${dateTime(m.completed_at || m.updated_at)}` : ''}`,
      failed: which === 'failed',
      missions: [m],
    }))
  }
  const groups = new Map()
  for (const m of active(type)) {
    const key = type === 'pickup' && m.pickup_group ? m.pickup_group : `m${m.id}`
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(m)
  }
  return [...groups].map(([key, list]) => {
    const first = list[0]
    const near = type === 'delivery' ? nearbyReturn(first, stops.value) : null
    return {
      key,
      link: list.length > 1 ? `/livreur/ramassage/${key}` : `/livreur/missions/${first.id}`,
      art: { pickup: 'boutique', delivery: 'moto', return: 'retour' }[type],
      title: list.length > 1 ? `${titleOf(first)} : ${list.length} colis` : titleOf(first),
      place: placeOf(first),
      cod: type === 'delivery' ? first.order?.amounts?.cod_amount : 0,
      toAccept: list.filter((m) => m.status === 'assigned').length,
      status: first.order?.status,
      statusLabel: first.order?.status_label,
      stop: stopOf.value.get(first.id),
      nearby: near ? `Retour à faire à ${near.km < 1 ? `${Math.max(100, Math.round(near.km * 10) * 100)} m` : `${near.km.toFixed(1)} km`} : ${near.stop.name} (${near.stop.missions.filter((m) => m.type === 'return').length} colis)` : '',
      missions: list,
    }
  })
})
const titleOf = (m) => (m.type === 'delivery' ? (m.order?.recipient?.name || m.order?.recipient?.phone) : (m.order?.merchant?.business_name || m.order?.pickup?.contact_name))
const placeOf = (m) => { const p = missionPlace(m); return [p.zone, p.address].filter(Boolean).join(' · ') }

let firstLoad = true
async function load() {
  try {
    const [m, w, r, hist] = await Promise.all([
      cachedGet('/courier/missions'),
      cachedGet('/courier/wallet'),
      cachedGet('/courier/return-slips').catch(() => null),
      cachedGet('/courier/missions?history=1').catch(() => null),
    ])
    returnSlips.value = r?.data.data || []
    missions.value = withPending(m.data.data)
    history.value = hist?.data.data || []
    messages.setUnread(m.data.meta?.unread_messages)
    relays.value = m.data.meta?.relays || 0
    wallet.value = w.data.data
    stale.value = m.stale || w.stale
    error.value = ''
    // Première ouverture : la tuile qui a des missions (À livrer de préférence)
    if (firstLoad && !count(tab.value)) tab.value = TABS.map((t) => t.value).find((t) => count(t)) || tab.value
    firstLoad = false
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger vos missions.')
  } finally {
    loading.value = false
  }
}

// Invitation masquée 3 jours après « Plus tard »
const pushInviteHiddenUntil = ref(Number((() => { try { return localStorage.getItem('push-invite-hidden') } catch { return 0 } })() || 0))
const showPushInvite = computed(() => push.checked && push.supported && push.serverEnabled && !push.subscribed
  && push.permission !== 'denied' && Date.now() > pushInviteHiddenUntil.value)
function dismissPushInvite() {
  pushInviteHiddenUntil.value = Date.now() + 3 * 86400000
  try { localStorage.setItem('push-invite-hidden', String(pushInviteHiddenUntil.value)) } catch { /* stockage indisponible */ }
}

// Nouvelle mission ou notification reçue en temps réel : liste rechargée

// Actions faites hors connexion envoyées : liste rechargée
window.addEventListener('offline-synced', load)
onBeforeUnmount(() => window.removeEventListener('offline-synced', load))

onMounted(() => {
  load()
  initPush()
})
</script>
