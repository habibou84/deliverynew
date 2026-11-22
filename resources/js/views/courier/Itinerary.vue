<template>
  <div class="space-y-4">
    <p v-if="stale" class="flex items-center gap-2 rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">
      <Icone nom="hors-ligne" />Hors ligne : voici votre itinéraire tel qu'il était à la dernière connexion.
    </p>
    <p v-if="loading" class="text-center text-slate-600 py-8">Chargement…</p>
    <div v-else-if="error && !missions.length" class="text-center py-8 space-y-3">
      <p class="text-slate-600">{{ error }}</p>
      <button class="m-btn m-btn-secondary" @click="load">Réessayer</button>
    </div>
    <template v-else>
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
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { cachedGet } from '../../composables/useCachedApi'
import { withPending } from '../../composables/useOfflineQueue'
import { currentPosition } from '../../composables/useGeolocation'
import { useToastStore } from '../../stores/toasts'
import EmptyState from '../../components/mobile/EmptyState.vue'
import MissionFlags from '../../components/courier/MissionFlags.vue'
import Icone from '../../components/ui/Icone.vue'
import { money } from '../../utils/format'
import { buildStops, googleMapsRoute, MAPS_MAX_STOPS, nearestNeighborRoute, orderViolations } from '../../utils/route'

/**
 * Mon itinéraire : tous les arrêts à faire (ramassages, livraisons, retours), un arrêt par lieu, dans l'ordre de
 * passage. Organiser de proche en proche, ranger à la main, ouvrir le trajet dans Google Maps.
 */
const TASK_ICON = { pickup: 'ramasser', delivery: 'livrer', return: 'retourner' }
const TASK_STYLE = { pickup: 'bg-indigo-100 text-indigo-800', delivery: 'bg-blue-100 text-blue-800', return: 'bg-rose-100 text-rose-800' }

const toasts = useToastStore()
const missions = ref([])
const loading = ref(true)
const stale = ref(false)
const error = ref('')

const stops = computed(() => buildStops(missions.value))
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
    // Retours d'un même marchand : un seul écran pour tous ses colis
    const merchant = t.missions[0].order?.merchant?.id
    if (t.type === 'return' && merchant) {
      list.push({ type: 'return', to: `/livreur/retour/${merchant}`, label: t.label })
      continue
    }
    for (const m of t.missions) {
      const verb = { pickup: 'Ramasser', delivery: 'Livrer', return: 'Retourner' }[t.type]
      list.push({ type: t.type, to: `/livreur/missions/${m.id}`, label: `${verb} · ${m.order?.tracking_code || ''}` })
    }
  }
  return list
}


async function load() {
  try {
    const m = await cachedGet('/courier/missions')
    missions.value = withPending(m.data.data)
    stale.value = m.stale
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger votre itinéraire.')
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
window.addEventListener('offline-synced', load)
onBeforeUnmount(() => window.removeEventListener('offline-synced', load))
onMounted(load)
</script>
