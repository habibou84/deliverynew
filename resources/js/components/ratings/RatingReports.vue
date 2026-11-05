<template>
  <div>
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="settings.ask_recipient_rating === false" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
      Les clients ne sont pas invités à noter leur livraison.
      <button v-if="auth.can('settings.manage')" type="button" class="font-semibold underline" @click="settingsOpen = true">Activer</button>
    </p>

    <!-- Avis graves à traiter tout de suite -->
    <section v-if="toHandle.length" class="space-y-2" aria-label="Signalements à traiter">
      <div v-for="r in shownReports" :key="r.id" class="card border-red-200 p-3 flex flex-wrap items-center gap-3">
        <span class="h-9 w-9 shrink-0 rounded-lg bg-red-100 text-red-700 grid place-items-center" aria-hidden="true">⚠️</span>
        <div class="min-w-0 flex-1">
          <p class="font-semibold"><RouterLink :to="`/admin/livreurs/${r.courier_id}?onglet=notes`" class="hover:underline">{{ r.courier_name }}</RouterLink> — {{ problem(r) }}</p>
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

    <Modal :open="!!cancelling" title="Annuler cet avis" @close="cancelling = null">
      <form class="space-y-3" @submit.prevent="cancel">
        <p class="text-sm text-gray-600">L'avis ne comptera plus dans la note du livreur. Il reste visible, barré, dans sa fiche.</p>
        <div><label class="label" for="cancel-reason">Raison</label><input id="cancel-reason" v-model="cancelReason" class="input" placeholder="Ex. : client absent, pas la faute du livreur"></div>
        <button class="btn-primary w-full">Annuler l'avis</button>
      </form>
    </Modal>

    <Modal :open="settingsOpen" title="Réglages des notes" @close="settingsOpen = false">
      <form class="space-y-4" @submit.prevent="saveSettings">
        <p class="text-sm text-gray-500">
          Note sur les {{ settings.window_days || 90 }} derniers jours : clients {{ pct(settings.weights?.recipient) }}, e-commerçants {{ pct(settings.weights?.merchant) }}, responsables {{ pct(settings.weights?.manager) }}.
          Elle est lissée : un livreur avec peu d'avis reste proche de 4.
        </p>
        <label class="flex gap-3 text-sm">
          <input v-model="draft.ask_recipient_rating" type="checkbox" class="mt-1">
          <span><b>Demander l'avis du client après la livraison</b><br><span class="text-gray-500">Un message WhatsApp : répondre de 1 à 5, ou noter en détail par un lien.</span></span>
        </label>
        <div>
          <label class="label" for="rating-threshold">Seuil d'alerte</label>
          <input id="rating-threshold" v-model.number="draft.rating_alert_threshold" type="number" min="1" max="5" step="0.1" class="input">
          <p class="mt-1 text-xs text-gray-500">Un livreur dont la note passe sous ce seuil est signalé dans la liste.</p>
        </div>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { dateTime } from '../../utils/format'
import Modal from '../Modal.vue'
import StarRating from './StarRating.vue'

/** Page Livreurs : signalements des clients à traiter et réglages des notes */
const emit = defineEmits(['changed'])
const auth = useAuthStore()
const toHandle = ref([])
const settings = ref({})
const error = ref('')
const cancelling = ref(null)
const cancelReason = ref('')
const settingsOpen = ref(false)
const allReports = ref(false)
const shownReports = computed(() => (allReports.value ? toHandle.value : toHandle.value.slice(0, 3)))
const draft = reactive({ ask_recipient_rating: true, rating_alert_threshold: 3.5 })

const pct = (w) => (w === undefined ? '—' : `${Math.round(w * 100)} %`)
const problem = (r) => {
  const bad = r.tags.filter((t) => !t.good).map((t) => `« ${t.label} »`)
  return bad.length ? bad.join(', ') : `${r.score} étoile${r.score > 1 ? 's' : ''}`
}

async function load() {
  try {
    const { data } = await http.get('/courier-ratings')
    toHandle.value = data.data.to_handle
    settings.value = data.data.settings
    Object.assign(draft, { ask_recipient_rating: settings.value.ask_recipient_rating, rating_alert_threshold: settings.value.rating_alert_threshold })
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function act(fn) {
  try {
    await fn()
    await load()
    emit('changed')
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

const handle = (r) => act(() => http.post(`/courier-ratings/${r.id}/handle`))
function askCancel(r) {
  cancelling.value = r
  cancelReason.value = ''
}
const cancel = () => act(async () => {
  await http.post(`/courier-ratings/${cancelling.value.id}/cancel`, { reason: cancelReason.value || null })
  cancelling.value = null
})
const saveSettings = () => act(async () => {
  await http.put('/courier-ratings/settings', draft)
  settingsOpen.value = false
})

defineExpose({ openSettings: () => { settingsOpen.value = true } })
onMounted(load)
</script>
