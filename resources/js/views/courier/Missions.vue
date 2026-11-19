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

    <!-- Onglets, puis sous-onglets de l'onglet choisi -->
    <div class="m-card overflow-hidden">
      <div class="m-1.5 grid grid-cols-4 gap-1 rounded-xl bg-slate-200 p-1" role="tablist" aria-label="Missions">
        <button
          v-for="t in TABS"
          :key="t.value"
          type="button"
          role="tab"
          :aria-selected="tab === t.value"
          :class="['tap relative flex min-h-[58px] flex-col items-center justify-center gap-1 rounded-lg px-0.5 text-[11px] font-semibold leading-tight', tab === t.value ? 'bg-white shadow-sm text-[var(--app-color)]' : 'text-slate-600']"
          @click="tab = t.value"
        >
          <Icone :nom="t.icon" />
          <span class="whitespace-nowrap">{{ t.label }}</span>
          <span :class="['absolute right-1 top-1 min-w-[18px] rounded-full px-1 text-[10px] leading-[16px]', count(t.value) ? 'bg-[var(--app-color)] text-white' : 'bg-slate-300 text-slate-700']">{{ count(t.value) }}</span>
        </button>
      </div>
      <div v-if="subTabs.length" class="grid border-t border-slate-200" :style="{ gridTemplateColumns: `repeat(${subTabs.length}, minmax(0, 1fr))` }" role="tablist" :aria-label="TABS.find((t) => t.value === tab).label">
        <button
          v-for="s in subTabs"
          :key="s.value"
          type="button"
          role="tab"
          :aria-selected="sub[tab] === s.value"
          :class="['tap flex min-h-12 items-center justify-center gap-1.5 border-b-[3px] px-1 py-1.5 text-center text-[13px] leading-tight', sub[tab] === s.value ? 'border-[var(--app-color)] font-bold text-[var(--app-color)]' : 'border-transparent font-medium text-slate-600']"
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

    <!-- MON ITINÉRAIRE : tous les arrêts à faire, un arrêt par lieu -->
    <template v-else-if="tab === 'itinerary'">
      <EmptyState v-if="!stops.length" art="itineraire" title="Aucun arrêt pour le moment" text="Vos ramassages, livraisons et retours du jour apparaîtront ici, dans l'ordre de passage." />

      <section v-else-if="!reordering" class="m-card p-3 space-y-2">
        <div class="grid grid-cols-2 gap-2">
          <button v-if="stops.length > 1" type="button" class="tap flex items-center justify-center gap-1.5 rounded-xl bg-[var(--app-color)] px-2 py-2.5 text-sm font-semibold text-white disabled:opacity-60" :disabled="routing" @click="organize">
            <Icone nom="organiser" taille="petite" />{{ routing ? 'Calcul…' : 'Organiser mon itinéraire' }}
          </button>
          <button v-if="stops.length > 1" type="button" class="tap flex items-center justify-center gap-1.5 rounded-xl bg-slate-100 px-2 py-2.5 text-sm font-semibold active:bg-slate-200" @click="startReorder">
            <Icone nom="modifier-ordre" taille="petite" />Modifier l'ordre
          </button>
          <a v-if="routeLink" :href="routeLink" target="_blank" rel="noopener" class="tap col-span-2 flex items-center justify-center gap-1.5 rounded-xl bg-slate-100 px-3 py-2.5 text-sm font-semibold active:bg-slate-200">
            <Icone nom="trajet" taille="petite" />Trajet dans Google Maps ({{ Math.min(stops.length, MAPS_MAX_STOPS) }} arrêt{{ Math.min(stops.length, MAPS_MAX_STOPS) > 1 ? 's' : '' }})
          </a>
        </div>
        <p class="text-xs text-slate-600">{{ itinerarySummary }}</p>
        <p v-if="stops.length > MAPS_MAX_STOPS" class="text-xs text-slate-600">Google Maps n'accepte que {{ MAPS_MAX_STOPS }} arrêts : le trajet s'arrête au {{ MAPS_MAX_STOPS }}ᵉ. Relancez-le ensuite.</p>
        <p v-if="unlocated" class="flex items-start gap-1.5 text-xs text-slate-600"><Icone nom="adresse" taille="petite" />{{ unlocated }} arrêt{{ unlocated > 1 ? 's' : '' }} sans position GPS : placé{{ unlocated > 1 ? 's' : '' }} à la fin, par commune. Déplacez-les si besoin.</p>
      </section>

      <!-- Modifier l'ordre -->
      <section v-else class="m-card p-3 space-y-3">
        <p class="text-sm font-semibold">Rangez vos arrêts dans l'ordre de passage</p>
        <ol class="divide-y rounded-xl border">
          <li v-for="(s, i) in draft" :key="s.key" class="flex items-center gap-2 p-2">
            <span class="h-7 w-7 shrink-0 rounded-full bg-[var(--app-color)] text-center text-sm font-bold leading-7 text-white">{{ i + 1 }}</span>
            <span class="flex-1 min-w-0">
              <span class="block truncate text-sm font-medium">{{ s.name }}</span>
              <span class="block truncate text-xs text-slate-600">{{ s.tasks.map((t) => t.label).join(' + ') }} · {{ s.place.zone }}<template v-if="!s.place.located"> · sans position</template></span>
            </span>
            <button type="button" class="tap grid h-11 w-11 place-items-center rounded-lg bg-slate-100 disabled:opacity-30" :disabled="i === 0" :aria-label="`Monter ${s.name}`" @click="move(i, -1)"><Icone nom="monter" /></button>
            <button type="button" class="tap grid h-11 w-11 place-items-center rounded-lg bg-slate-100 disabled:opacity-30" :disabled="i === draft.length - 1" :aria-label="`Descendre ${s.name}`" @click="move(i, 1)"><Icone nom="descendre" /></button>
          </li>
        </ol>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="m-btn-secondary" :disabled="routing" @click="reordering = false">Annuler</button>
          <button type="button" class="m-btn-primary" :disabled="routing" @click="saveManual">{{ routing ? '…' : 'Enregistrer' }}</button>
        </div>
      </section>

      <template v-if="!reordering">
        <component
          :is="targets(s).length === 1 ? RouterLink : 'div'"
          v-for="(s, i) in stops"
          :key="s.key"
          v-bind="targets(s).length === 1 ? { to: targets(s)[0].to } : {}"
          :class="['block m-card p-4', targets(s).length === 1 ? 'active:bg-slate-50' : '']"
        >
          <div class="flex items-start gap-3">
            <span class="h-8 w-8 shrink-0 rounded-full bg-[var(--app-color)] text-center text-sm font-bold leading-8 text-white">{{ i + 1 }}</span>
            <div class="flex-1 min-w-0 space-y-1.5">
              <div class="flex justify-between gap-2">
                <p class="font-semibold truncate">{{ s.name }}</p>
                <span v-if="stopCod(s)" class="font-bold whitespace-nowrap">{{ money(stopCod(s)) }}</span>
              </div>
              <p class="flex items-center gap-1 text-sm text-slate-600 truncate"><Icone nom="adresse" taille="petite" />{{ [s.place.zone, s.place.address].filter(Boolean).join(' · ') }}</p>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="t in s.tasks" :key="t.type" :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold', TASK_STYLE[t.type]]">
                  <Icone :nom="TASK_ICON[t.type]" taille="petite" />{{ t.label }}
                </span>
                <MissionFlags :missions="s.missions" />
              </div>
            </div>
            <Icone v-if="targets(s).length === 1" nom="ouvrir" class="text-slate-400" />
          </div>
          <!-- Plusieurs choses à faire au même endroit : une ligne par mission -->
          <div v-if="targets(s).length > 1" class="mt-3 divide-y rounded-xl border">
            <RouterLink v-for="t in targets(s)" :key="t.to" :to="t.to" class="tap flex items-center gap-2 px-3 py-2.5 text-sm active:bg-slate-50">
              <Icone :nom="TASK_ICON[t.type]" taille="petite" class="text-slate-600" />
              <span class="flex-1 truncate">{{ t.label }}</span>
              <Icone nom="ouvrir" taille="petite" class="text-slate-400" />
            </RouterLink>
          </div>
        </component>
      </template>
    </template>

    <!-- À RAMASSER, À LIVRER, À RETOURNER : listes de suivi -->
    <template v-else>
      <p class="px-1 text-xs text-slate-600">{{ SUMMARIES[`${tab}:${sub[tab]}`] }}</p>
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
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { cachedGet } from '../../composables/useCachedApi'
import { withPending } from '../../composables/useOfflineQueue'
import { useToastStore } from '../../stores/toasts'
import StatusBadge from '../../components/StatusBadge.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import Icone from '../../components/ui/Icone.vue'
import { useNotificationStore } from '../../stores/notifications'
import { currentPosition } from '../../composables/useGeolocation'
import { useCourierMessageStore } from '../../stores/courierMessages'
import { enablePush, initPush, push } from '../../composables/usePush'
import { dateTime, money } from '../../utils/format'
import { buildStops, googleMapsRoute, MAPS_MAX_STOPS, missionPlace, nearbyReturn, nearestNeighborRoute, orderViolations } from '../../utils/route'

/**
 * Missions du livreur : son itinéraire (tous les arrêts à faire, dans l'ordre de passage) et, par onglet, le suivi de
 * ses ramassages, livraisons et retours (à faire, déjà faits, échecs).
 */
const TABS = [
  { value: 'itinerary', label: 'Mon itinéraire', icon: 'itineraire' },
  { value: 'pickup', label: 'À ramasser', icon: 'ramasser' },
  { value: 'delivery', label: 'À livrer', icon: 'livrer' },
  { value: 'return', label: 'À retourner', icon: 'retourner' },
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
const TASK_ICON = { pickup: 'ramasser', delivery: 'livrer', return: 'retourner' }
const TASK_STYLE = { pickup: 'bg-indigo-100 text-indigo-800', delivery: 'bg-blue-100 text-blue-800', return: 'bg-rose-100 text-rose-800' }

// Petites étiquettes d'une mission : express, relais, modifiée, consigne, hors ligne, à expédier, gain
const MissionFlags = defineComponent({
  props: { missions: { type: Array, required: true } },
  setup(props) {
    const flag = (cls, icon, text, title) => h('span', { class: `inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`, title }, [h(Icone, { nom: icon, taille: 'petite' }), text])
    return () => {
      const ms = props.missions
      const gain = ms.reduce((sum, m) => sum + (m.gain || 0), 0)
      const unread = ms.reduce((sum, m) => sum + (m.unread_messages || 0), 0)
      return [
        ms.some((m) => m.order?.package?.is_express) && flag('bg-red-100 text-red-700', 'rapide', 'Express'),
        ms.some((m) => m.relay_from) && flag('bg-violet-100 text-violet-800', 'relais', 'Relais'),
        ms.some((m) => m.last_edit && !m.last_edit.seen) && flag('bg-amber-100 text-amber-800', 'modifier', 'Modifiée'),
        unread && flag('bg-sky-100 text-sky-800', 'message', String(unread), 'Consignes de l\'agence'),
        ms.some((m) => m.offline) && flag('bg-sky-100 text-sky-800', 'hors-ligne', 'À envoyer'),
        ms.some((m) => m.type === 'delivery' && m.order?.is_shipping) && flag('bg-indigo-100 text-indigo-800', 'expedition', 'À expédier'),
        gain && flag('bg-emerald-100 text-emerald-800', 'gain', `+ ${money(gain)}`, 'Votre gain prévu'),
      ].filter(Boolean)
    }
  },
})

const notifications = useNotificationStore()
const toasts = useToastStore()
const messages = useCourierMessageStore()
const missions = ref([])
const history = ref([])
const wallet = ref(null)
const returnSlips = ref([])
const relays = ref(0)
const loading = ref(true)
const stale = ref(false)
const error = ref('')
const tab = ref('itinerary')
const sub = reactive({ pickup: 'todo', delivery: 'todo', return: 'todo' })

const active = (type) => missions.value.filter((m) => m.type === type)
const finished = (type, status) => history.value.filter((m) => m.type === type && m.status === status)
const count = (t) => (t === 'itinerary' ? stops.value.length : active(t).length)

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
const routeLink = computed(() => googleMapsRoute(stops.value))
const unlocated = computed(() => stops.value.filter((s) => !s.place.located).length)
const itinerarySummary = computed(() => {
  const n = (type) => missions.value.filter((m) => m.type === type).length
  const parts = [[n('pickup'), 'ramassage'], [n('delivery'), 'livraison'], [n('return'), 'retour']].filter(([k]) => k).map(([k, w]) => `${k} ${w}${k > 1 ? 's' : ''}`)
  return `${stops.value.length} arrêt${stops.value.length > 1 ? 's' : ''} : ${parts.join(', ')}. Un même lieu ne fait qu'un arrêt ; un colis est toujours ramassé avant d'être livré.`
})
const stopCod = (s) => s.missions.filter((m) => m.type === 'delivery').reduce((sum, m) => sum + (m.order?.amounts?.cod_amount || 0), 0)

// Où mène chaque arrêt : la mission, ou le ramassage groupé ; plusieurs lignes s'il y a plusieurs choses à faire
function targets(s) {
  const list = []
  for (const t of s.tasks) {
    if (t.type === 'pickup' && t.missions.length > 1 && t.missions[0].pickup_group) {
      list.push({ type: 'pickup', to: `/livreur/ramassage/${t.missions[0].pickup_group}`, label: t.label })
      continue
    }
    for (const m of t.missions) {
      const verb = { pickup: 'Ramasser', delivery: 'Livrer', return: 'Retourner' }[t.type]
      list.push({ type: t.type, to: `/livreur/missions/${m.id}`, label: `${verb} · ${m.order?.tracking_code || ''}` })
    }
  }
  return list
}

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
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger vos missions.')
  } finally {
    loading.value = false
  }
}

// Itinéraire : de proche en proche (position du téléphone), à la main, puis enregistré pour la journée
const reordering = ref(false)
const routing = ref(false)
const draft = ref([])

async function saveStops(ordered, message) {
  routing.value = true
  try {
    const ids = ordered.flatMap((s) => s.missions.map((m) => m.id))
    await http.put('/courier/route', { assignment_ids: ids })
    const rank = new Map(ids.map((id, i) => [id, i + 1]))
    missions.value.forEach((m) => { m.route_rank = rank.get(m.id) ?? null })
    reordering.value = false
    toasts.success(message)
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'Impossible d\'enregistrer l\'itinéraire.'))
  } finally {
    routing.value = false
  }
}

async function organize() {
  routing.value = true
  const position = await currentPosition(5000)
  routing.value = false
  const { ordered, located } = nearestNeighborRoute(stops.value, position)
  if (!located) {
    toasts.error('Aucun arrêt n\'a de position GPS : rangez-les à la main avec « Modifier l\'ordre ».')
    return
  }
  await saveStops(ordered, position ? 'Itinéraire organisé de proche en proche depuis votre position.' : 'Itinéraire organisé (position du téléphone indisponible : départ du premier arrêt).')
}

function startReorder() {
  draft.value = [...stops.value]
  reordering.value = true
}

function move(i, step) {
  const list = [...draft.value]
  ;[list[i], list[i + step]] = [list[i + step], list[i]]
  draft.value = list
}

function saveManual() {
  const wrong = orderViolations(draft.value)
  if (wrong.length) {
    const { stop, before } = wrong[0]
    toasts.error(`${stop.name} doit venir après ${before.name} : le colis est ramassé avant d'être livré.`)
    return
  }
  saveStops(draft.value, 'Ordre de l\'itinéraire enregistré.')
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
