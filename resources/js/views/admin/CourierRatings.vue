<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Notes des livreurs</h1>
        <p class="text-sm text-gray-500">
          {{ settings.window_days || 90 }} derniers jours · clients {{ pct(settings.weights?.recipient) }}, e-commerçants {{ pct(settings.weights?.merchant) }}, responsables {{ pct(settings.weights?.manager) }}
        </p>
      </div>
      <button v-if="auth.can('settings.manage')" class="btn-secondary" @click="settingsOpen = true">⚙️ Réglages</button>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="settings.ask_recipient_rating === false" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
      Les clients ne sont pas invités à noter leur livraison. Activez-le dans les réglages de cette page.
    </p>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="card p-4"><p class="text-sm text-gray-500">Note moyenne</p><p class="text-2xl font-bold">{{ scoreText(summary.average) }}</p></div>
      <div class="card p-4"><p class="text-sm text-gray-500">Avis ce mois-ci</p><p class="text-2xl font-bold">{{ summary.ratings_this_month ?? '—' }}</p></div>
      <div class="card p-4"><p class="text-sm text-gray-500">Clients qui notent</p><p class="text-2xl font-bold">{{ summary.response_rate === null || summary.response_rate === undefined ? '—' : `${summary.response_rate} %` }}</p></div>
      <div :class="['card p-4', summary.to_handle ? 'border-red-200 bg-red-50' : '']">
        <p :class="['text-sm', summary.to_handle ? 'text-red-800' : 'text-gray-500']">Signalements à traiter</p>
        <p :class="['text-2xl font-bold', summary.to_handle ? 'text-red-700' : '']">{{ summary.to_handle ?? 0 }}</p>
      </div>
    </div>

    <!-- Avis graves à traiter tout de suite -->
    <section v-if="toHandle.length" class="space-y-2" aria-label="Signalements à traiter">
      <div v-for="r in shownReports" :key="r.id" class="card border-red-200 p-4 flex flex-wrap items-center gap-3">
        <span class="h-9 w-9 shrink-0 rounded-lg bg-red-100 text-red-700 grid place-items-center" aria-hidden="true">⚠️</span>
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ r.courier_name }} — {{ problem(r) }}</p>
          <p class="text-sm text-gray-500">
            <StarRating :model-value="r.score" readonly size="sm" class="align-[-2px]" />
            Client<template v-if="r.merchant_name"> de {{ r.merchant_name }}</template> · course {{ r.tracking_code }} · {{ dateTime(r.created_at) }}
            <template v-if="r.comment"> · « {{ r.comment }} »</template>
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <RouterLink :to="`/admin/courses/${r.order_id}`" class="btn-secondary">Voir la course</RouterLink>
          <button class="btn-secondary" @click="askCancel(r)">Annuler cet avis</button>
          <button class="btn-primary" @click="handle(r)">Marquer comme traité</button>
        </div>
      </div>
      <button v-if="toHandle.length > 3" class="text-sm font-semibold text-blue-600" @click="allReports = !allReports">
        {{ allReports ? 'Réduire' : `Voir les ${toHandle.length - 3} autres signalements` }}
      </button>
    </section>

    <!-- Classement -->
    <div class="card overflow-hidden">
      <table class="w-full text-sm hidden md:table">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-gray-500">
          <tr>
            <th class="px-4 py-3 text-left">Livreur</th>
            <th class="px-4 py-3 text-left">Note</th>
            <th class="px-4 py-3 text-left">Clients</th>
            <th class="px-4 py-3 text-left">E-com.</th>
            <th class="px-4 py-3 text-left">Resp.</th>
            <th class="px-4 py-3 text-left">Avis</th>
            <th class="px-4 py-3 text-left">Tendance</th>
            <th class="px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="c in couriers" :key="c.id" class="cursor-pointer hover:bg-slate-50" @click="open(c)">
            <td class="px-4 py-3 font-semibold">{{ c.name }}</td>
            <td class="px-4 py-3"><span class="font-bold text-base mr-1">{{ scoreText(c.score) }}</span><StarRating v-if="c.score" :model-value="c.score" readonly size="sm" class="align-[-2px]" /></td>
            <td class="px-4 py-3">{{ scoreText(c.sources.recipient.score) }}</td>
            <td class="px-4 py-3">{{ scoreText(c.sources.merchant.score) }}</td>
            <td class="px-4 py-3">{{ scoreText(c.sources.manager.score) }}</td>
            <td class="px-4 py-3">{{ c.count }}</td>
            <td :class="['px-4 py-3 font-semibold', trendClass(c.trend)]">{{ trendText(c.trend) }}</td>
            <td class="px-4 py-3 text-right text-sm font-semibold text-orange-700">
              <span v-if="c.to_handle">⚠ {{ c.to_handle }} signalement{{ c.to_handle > 1 ? 's' : '' }}</span>
              <span v-else-if="c.below_threshold">⚠ sous le seuil</span>
            </td>
          </tr>
        </tbody>
      </table>
      <ul class="md:hidden divide-y divide-slate-100">
        <li v-for="c in couriers" :key="c.id">
          <button class="w-full px-4 py-3 text-left flex items-center gap-3" @click="open(c)">
            <span class="min-w-0 flex-1">
              <span class="block font-semibold truncate">{{ c.name }}</span>
              <span class="text-xs text-gray-500">{{ c.count }} avis<template v-if="c.trend"> · <span :class="trendClass(c.trend)">{{ trendText(c.trend) }}</span></template></span>
              <span v-if="c.to_handle || c.below_threshold" class="block text-xs font-semibold text-orange-700">⚠ {{ c.to_handle ? `${c.to_handle} signalement(s)` : 'sous le seuil' }}</span>
            </span>
            <span class="text-right"><span class="block text-lg font-bold">{{ scoreText(c.score) }}</span><StarRating v-if="c.score" :model-value="c.score" readonly size="sm" /></span>
          </button>
        </li>
      </ul>
      <p v-if="loaded && !couriers.length" class="p-6 text-center text-gray-500">Aucun livreur pour le moment.</p>
    </div>
    <p class="text-xs text-gray-500">La note est lissée : un livreur avec peu d'avis reste proche de 4 tant qu'il n'en a pas reçu assez.</p>

    <Modal :open="!!cancelling" title="Annuler cet avis" @close="cancelling = null">
      <form class="space-y-3" @submit.prevent="cancel">
        <p class="text-sm text-gray-600">L'avis ne comptera plus dans la note du livreur. Il reste visible, barré, dans sa fiche.</p>
        <div><label class="label" for="cancel-reason">Raison</label><input id="cancel-reason" v-model="cancelReason" class="input" placeholder="Ex. : client absent, pas la faute du livreur"></div>
        <button class="btn-primary w-full">Annuler l'avis</button>
      </form>
    </Modal>

    <Modal :open="settingsOpen" title="Réglages des notes" @close="settingsOpen = false">
      <form class="space-y-4" @submit.prevent="saveSettings">
        <label class="flex gap-3 text-sm">
          <input v-model="draft.ask_recipient_rating" type="checkbox" class="mt-1">
          <span><b>Demander l'avis du client après la livraison</b><br><span class="text-gray-500">Un message WhatsApp : répondre de 1 à 5, ou noter en détail par un lien.</span></span>
        </label>
        <div>
          <label class="label" for="rating-threshold">Seuil d'alerte</label>
          <input id="rating-threshold" v-model.number="draft.rating_alert_threshold" type="number" min="1" max="5" step="0.1" class="input">
          <p class="mt-1 text-xs text-gray-500">Un livreur dont la note passe sous ce seuil est signalé dans le classement.</p>
        </div>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import StarRating from '../../components/ratings/StarRating.vue'
import { scoreText } from '../../components/ratings/ratingWords'
import { useAuthStore } from '../../stores/auth'
import { dateTime } from '../../utils/format'

const auth = useAuthStore()
const router = useRouter()
const couriers = ref([])
const toHandle = ref([])
const summary = ref({})
const settings = ref({})
const loaded = ref(false)
const error = ref('')
const cancelling = ref(null)
const cancelReason = ref('')
const settingsOpen = ref(false)
const allReports = ref(false)
const shownReports = computed(() => (allReports.value ? toHandle.value : toHandle.value.slice(0, 3)))
const draft = reactive({ ask_recipient_rating: true, rating_alert_threshold: 3.5 })

const pct = (w) => (w === undefined ? '—' : `${Math.round(w * 100)} %`)
const trendText = (t) => (t === null || t === undefined || t === 0 ? '—' : `${t > 0 ? '▲' : '▼'} ${scoreText(Math.abs(t))}`)
const trendClass = (t) => (t > 0 ? 'text-emerald-700' : t < 0 ? 'text-orange-700' : 'text-gray-400')
const problem = (r) => {
  const bad = r.tags.filter((t) => !t.good).map((t) => `« ${t.label} »`)
  return bad.length ? bad.join(', ') : `${r.score} étoile${r.score > 1 ? 's' : ''}`
}

function open(c) {
  router.push(`/admin/livreurs/${c.id}/notes`)
}

async function load() {
  try {
    const { data } = await http.get('/courier-ratings')
    couriers.value = data.data.couriers
    toHandle.value = data.data.to_handle
    summary.value = data.data.summary
    settings.value = data.data.settings
    Object.assign(draft, { ask_recipient_rating: settings.value.ask_recipient_rating, rating_alert_threshold: settings.value.rating_alert_threshold })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loaded.value = true
  }
}

async function handle(r) {
  try {
    await http.post(`/courier-ratings/${r.id}/handle`)
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function askCancel(r) {
  cancelling.value = r
  cancelReason.value = ''
}

async function cancel() {
  try {
    await http.post(`/courier-ratings/${cancelling.value.id}/cancel`, { reason: cancelReason.value || null })
    cancelling.value = null
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function saveSettings() {
  try {
    await http.put('/courier-ratings/settings', draft)
    settingsOpen.value = false
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

onMounted(load)
</script>
