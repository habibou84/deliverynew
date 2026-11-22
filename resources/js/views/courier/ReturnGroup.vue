<template>
  <div v-if="missions.length" class="space-y-4 pb-40">
    <!-- Le marchand -->
    <section class="m-card p-4 space-y-2">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">Retour au marchand</span>
        <span class="rounded-full bg-rose-100 text-rose-800 text-xs font-semibold px-2 py-0.5">{{ missions.length }} colis à rendre</span>
      </div>
      <p v-if="offlineCount" class="flex items-start gap-2 rounded-xl bg-sky-50 text-sky-900 p-3 text-sm">
        <Icone nom="hors-ligne" taille="petite" />Enregistré sur votre téléphone : envoi automatique au retour du réseau.
      </p>
      <p class="text-xl font-bold">{{ shop.name }}</p>
      <p class="flex items-start gap-1.5 text-slate-700"><Icone nom="adresse" class="mt-0.5" />{{ shop.zone }}<span v-if="shop.address"> · {{ shop.address }}</span></p>
      <p v-if="shop.landmark" class="text-slate-600">Repère : {{ shop.landmark }}</p>
      <p v-if="stop" class="text-sm font-semibold text-[var(--app-color)]">Arrêt n° {{ stop }} de votre itinéraire</p>
      <p class="flex items-center gap-2 rounded-xl bg-slate-50 p-3 font-semibold text-slate-700"><Icone nom="retourner" />Colis à rendre · rien à encaisser</p>
      <p v-if="gain" class="flex items-center gap-1.5 text-sm font-semibold text-emerald-700"><Icone nom="gain" taille="petite" />Votre gain prévu : + {{ money(gain) }}</p>

      <ContactActions class="pt-2" :phone="shop.phone" :maps-href="mapsLink(shop.lat, shop.lng, `${shop.address || ''} ${shop.zone}`)" />
    </section>

    <!-- Les colis -->
    <section class="m-card divide-y">
      <p class="p-4 text-sm text-slate-600">Les colis à rapporter à {{ shop.name }}.</p>
      <RouterLink v-for="m in missions" :key="m.id" :to="`/livreur/missions/${m.id}`" class="flex items-start gap-3 p-4 active:bg-slate-50">
        <span class="flex-1 min-w-0">
          <span class="flex items-center gap-2">
            <span class="font-mono text-sm font-semibold">{{ m.order.tracking_code }}</span>
            <span class="text-sm text-slate-600 truncate">{{ m.order.recipient.name || m.order.recipient.phone }}</span>
          </span>
          <span class="flex flex-wrap items-center gap-2 mt-1">
            <span v-if="m.status === 'assigned'" class="rounded-full bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5">À accepter</span>
            <StatusBadge v-else :status="m.order.status" :label="m.order.status_label" />
            <span v-if="m.offline" class="inline-flex items-center gap-1 rounded-full bg-sky-100 text-sky-800 text-xs font-semibold px-2 py-0.5"><Icone nom="hors-ligne" taille="petite" />À envoyer</span>
            <span :class="['inline-flex items-center gap-1 rounded-full text-xs font-semibold px-2 py-0.5', where(m).class]"><Icone :nom="where(m).icon" taille="petite" />{{ where(m).label }}</span>
          </span>
        </span>
        <Icone nom="ouvrir" class="text-slate-400" />
      </RouterLink>
    </section>

    <!-- Actions -->
    <div class="fixed bottom-16 inset-x-0 z-20 bg-white border-t pb-safe">
      <div class="mx-auto max-w-lg p-3 space-y-2">
        <button v-if="toAccept.length" class="m-btn-success text-lg py-5" :disabled="busy" @click="acceptAll">
          <span class="inline-flex items-center justify-center gap-2"><Icone nom="valider" taille="grande" />Accepter {{ plural(toAccept.length) }}</span>
        </button>
        <template v-else-if="active.length">
          <button v-if="toStart.length" class="m-btn-primary text-lg py-5" :disabled="busy" @click="leave">
            <span class="inline-flex items-center justify-center gap-2"><Icone nom="livrer" taille="grande" />Je pars rapporter {{ plural(toStart.length) }}</span>
          </button>
          <button v-else class="m-btn-success text-lg py-5" :disabled="busy" @click="handOver">
            <span class="inline-flex items-center justify-center gap-2"><Icone nom="valider" taille="grande" />{{ withQr ? 'Remettre au marchand' : `J'ai rendu ${plural(active.length)}` }}</span>
          </button>
        </template>
        <p v-if="toAccept.length" class="text-center text-xs text-slate-600">Pour refuser un colis, ouvrez son détail.</p>
      </div>
    </div>
  </div>
  <p v-else class="text-center text-slate-600 py-10">Chargement…</p>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatusBadge from '../../components/StatusBadge.vue'
import ContactActions from '../../components/courier/ContactActions.vue'
import Icone from '../../components/ui/Icone.vue'
import { useAuthStore } from '../../stores/auth'
import { cachedGet } from '../../composables/useCachedApi'
import { useToastStore } from '../../stores/toasts'
import { currentPosition } from '../../composables/useGeolocation'
import { sendStatus, withPending } from '../../composables/useOfflineQueue'
import { buildStops } from '../../utils/route'
import { mapsLink, money } from '../../utils/format'

/**
 * Un retour par marchand : tous les colis à lui rapporter. Le livreur accepte le lot, part avec tous les colis,
 * puis les remet d'un coup (QR code scanné par le marchand, ou simple validation si l'agence ne demande pas de
 * confirmation). Rien n'est encaissé sur un retour.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const missions = ref([])
const all = ref([])
const busy = ref(false)

const merchantId = computed(() => Number(route.params.merchant))
const offlineEnabled = computed(() => !!auth.user?.company?.offline_courier_actions)
const withQr = computed(() => !!auth.user?.company?.return_confirmation)
const myCourierId = computed(() => auth.user?.courier?.id)
const OFFLINE_SAVED = 'Enregistré sur le téléphone : envoi automatique au retour du réseau.'

const shop = computed(() => {
  const o = missions.value[0].order
  return { name: o.merchant?.business_name || o.pickup.contact_name || 'le marchand', phone: o.merchant?.phone || o.pickup.phone, zone: o.pickup.zone_name, address: o.pickup.address, landmark: o.pickup.landmark, lat: o.pickup.lat, lng: o.pickup.lng }
})
const toAccept = computed(() => missions.value.filter((m) => m.status === 'assigned'))
const active = computed(() => missions.value.filter((m) => ['accepted', 'in_progress'].includes(m.status) && ['return_assigned', 'returning'].includes(m.order.status)))
const toStart = computed(() => active.value.filter((m) => m.order.status === 'return_assigned'))
const offlineCount = computed(() => missions.value.filter((m) => m.offline).length)
const gain = computed(() => missions.value.reduce((sum, m) => sum + (m.gain || 0), 0))
const stop = computed(() => {
  const i = buildStops(all.value).findIndex((s) => s.missions.some((m) => missions.value.some((x) => x.id === m.id)))
  return i >= 0 ? i + 1 : null
})

const plural = (n) => (n > 1 ? `les ${n} colis` : 'le colis')

/** Où se trouve le colis : chez le livreur, chez un autre livreur (relais), ou à l'agence */
function where(m) {
  const holder = m.order.held_by
  if (holder && holder.id === myCourierId.value) return { label: 'Chez vous', icon: 'valider', class: 'bg-emerald-100 text-emerald-800' }
  if (holder) return { label: `À recevoir par relais de ${holder.name || 'un livreur'}`, icon: 'relais', class: 'bg-amber-100 text-amber-800' }
  return { label: 'À récupérer à l\'agence', icon: 'depot', class: 'bg-slate-100 text-slate-700' }
}

async function load() {
  let data
  try {
    ;({ data } = await cachedGet('/courier/missions'))
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return
  }
  all.value = withPending(data.data)
  missions.value = all.value.filter((m) => m.type === 'return' && (m.order?.merchant?.id ?? null) === merchantId.value
    // Déjà rendu (hors connexion compris) : sort du lot
    && m.order.status !== 'returned')
  if (!missions.value.length) router.replace('/livreur')
}

async function acceptAll() {
  busy.value = true
  try {
    for (const m of toAccept.value) await http.post(`/courier/assignments/${m.id}/accept`)
    toasts.success('Retour accepté.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    await load()
    busy.value = false
  }
}

/** Même statut pour plusieurs colis ; continue après un refus du serveur et renvoie le bilan */
async function sendAll(list, extra) {
  const position = await currentPosition()
  const report = { done: 0, offline: 0, errors: [] }
  for (const m of list) {
    try {
      const result = await sendStatus(m.order, { ...extra, ...(position || {}) }, offlineEnabled.value)
      result.offline ? report.offline++ : report.done++
    } catch (e) {
      report.errors.push(`${m.order.tracking_code} : ${apiErrorMessage(e)}`)
    }
  }
  return report
}

function announce(report, success) {
  if (report.errors.length) toasts.error(report.errors.join(' · '))
  if (report.offline) toasts.success(OFFLINE_SAVED)
  else if (report.done) toasts.success(success)
}

async function leave() {
  busy.value = true
  try {
    announce(await sendAll(toStart.value, { status: 'returning' }), 'Bonne route !')
    await load()
  } finally {
    busy.value = false
  }
}

async function handOver() {
  // Remise confirmée par le marchand : QR code pour tous ses colis
  if (withQr.value) return router.push(`/livreur/remise/${merchantId.value}`)
  busy.value = true
  try {
    const n = active.value.length
    announce(await sendAll(active.value, { status: 'returned' }), `${n} colis rendu${n > 1 ? 's' : ''}. Merci !`)
    await load()
  } finally {
    busy.value = false
  }
}

// Actions hors connexion envoyées : état du serveur rechargé
window.addEventListener('offline-synced', load)
onBeforeUnmount(() => window.removeEventListener('offline-synced', load))

onMounted(load)
</script>
