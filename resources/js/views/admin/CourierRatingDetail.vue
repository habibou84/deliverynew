<template>
  <div class="space-y-4">
    <RouterLink to="/admin/livreurs/notes" class="text-sm text-blue-600">← Notes des livreurs</RouterLink>
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <template v-if="d">
      <div class="flex items-center gap-3">
        <span class="h-12 w-12 shrink-0 rounded-full bg-indigo-100 text-indigo-800 grid place-items-center font-bold" aria-hidden="true">{{ initials }}</span>
        <div class="min-w-0">
          <h1 class="text-xl font-bold truncate">{{ d.courier.name }}</h1>
          <p class="text-sm text-gray-500">Notes des {{ d.window_days }} derniers jours</p>
        </div>
      </div>

      <div class="grid lg:grid-cols-[1fr_380px] gap-4 items-start">
        <div class="space-y-4 min-w-0">
          <!-- Note et sources -->
          <div class="card p-5 flex flex-wrap gap-6 items-center">
            <div>
              <p class="text-5xl font-extrabold tracking-tight">{{ scoreText(d.score) }}</p>
              <StarRating v-if="d.score" :model-value="d.score" readonly size="sm" />
              <p v-if="d.trend" :class="['mt-1 text-sm font-semibold', d.trend > 0 ? 'text-emerald-700' : 'text-orange-700']">{{ d.trend > 0 ? '▲' : '▼' }} {{ scoreText(Math.abs(d.trend)) }} en un mois</p>
              <p v-if="below" class="mt-1 text-sm font-semibold text-orange-700">⚠ sous le seuil de {{ scoreText(d.threshold) }}</p>
            </div>
            <div class="min-w-[220px] flex-1 space-y-2">
              <div v-for="s in sourceRows" :key="s.key">
                <div class="flex justify-between text-sm"><span>{{ s.label }} · {{ s.count }} {{ s.unit }}</span><b>{{ scoreText(s.score) }}</b></div>
                <div class="mt-1 h-2 rounded-full bg-slate-100 overflow-hidden"><i class="block h-full rounded-full" :style="{ width: `${(s.score || 0) * 20}%`, background: barColor(s.score) }" /></div>
              </div>
            </div>
          </div>

          <div v-if="d.tags.length" class="card p-4">
            <h2 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Ce qui revient</h2>
            <div class="flex flex-wrap gap-2">
              <span v-for="t in d.tags" :key="t.code" :class="['rounded-full border px-3 py-1 text-sm font-semibold', t.good ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-red-300 bg-red-50 text-red-800']">{{ t.label }} · {{ t.count }}</span>
            </div>
          </div>

          <div v-if="d.figures" class="card p-4">
            <h2 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Chiffres automatiques ({{ d.window_days }} jours)</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
              <div><b class="block text-xl">{{ d.figures.delivered }}</b>livrées</div>
              <div><b class="block text-xl">{{ d.figures.success_rate === null ? '—' : `${d.figures.success_rate} %` }}</b>de réussite</div>
              <div><b class="block text-xl">{{ d.figures.delivery_minutes === null ? '—' : `${d.figures.delivery_minutes} min` }}</b>du ramassage à la livraison</div>
              <div><b :class="['block text-xl', d.figures.cash_gap < 0 ? 'text-red-700' : '']">{{ money(d.figures.cash_gap || 0) }}</b>d'écart de caisse</div>
            </div>
          </div>

          <div class="card p-4">
            <h2 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Derniers avis</h2>
            <p v-if="!d.ratings.length" class="text-sm text-gray-500">Aucun avis pour le moment.</p>
            <ul class="divide-y divide-slate-100">
              <li v-for="r in d.ratings" :key="r.id" :class="['py-2.5 flex flex-wrap items-start gap-x-3 gap-y-1 text-sm', r.cancelled_at ? 'opacity-50' : '']">
                <StarRating :model-value="r.score" readonly size="sm" class="mt-0.5" />
                <span class="min-w-0 flex-1">
                  <span :class="r.cancelled_at ? 'line-through' : ''">{{ r.comment ? `« ${r.comment} »` : (r.tags.length ? r.tags.map((t) => t.label).join(', ') : 'Sans commentaire') }}</span>
                  <span class="text-gray-500"> · {{ r.source === 'merchant' ? 'e-commerçant' : 'client' }} · <RouterLink :to="`/admin/courses/${r.order_id}`" class="text-blue-600">{{ r.tracking_code }}</RouterLink> · {{ dateTime(r.created_at) }}</span>
                  <span v-if="r.comment && r.tags.length" class="block text-xs text-gray-500">{{ r.tags.map((t) => t.label).join(', ') }}</span>
                  <span v-if="r.cancelled_at" class="block text-xs text-gray-500">Annulé{{ r.cancel_reason ? ` : ${r.cancel_reason}` : '' }}</span>
                  <span v-else-if="r.flagged && !r.handled_at" class="block text-xs font-semibold text-red-700">⚠ Signalement à traiter</span>
                </span>
                <span v-if="!r.cancelled_at" class="flex gap-3 text-xs">
                  <button v-if="r.flagged && !r.handled_at" class="text-blue-600" @click="handle(r)">Traité</button>
                  <button class="text-gray-600 underline" @click="askCancel(r)">Annuler</button>
                </span>
              </li>
            </ul>
          </div>
        </div>

        <!-- Évaluation du mois -->
        <form class="card p-5 space-y-3" @submit.prevent="saveReview">
          <div>
            <h2 class="text-lg font-bold">Évaluation du mois</h2>
            <p class="text-sm text-gray-500">{{ monthLabel }} · par un responsable, privée</p>
          </div>
          <div>
            <label class="label" for="review-month">Mois évalué</label>
            <input id="review-month" v-model="review.period" type="month" class="input" :max="thisMonth" @change="fillReview">
          </div>
          <div v-for="(label, key) in d.criteria" :key="key" class="flex items-center justify-between gap-3">
            <span class="text-sm">{{ label }}</span>
            <StarRating v-model="review.criteria[key]" size="md" :label="label" />
          </div>
          <div>
            <label class="label" for="review-comment">Commentaire</label>
            <textarea id="review-comment" v-model="review.comment" rows="3" class="input" placeholder="Ex. : rappeler d'appeler les clients avant d'arriver…" />
          </div>
          <label class="flex gap-2 text-sm"><input v-model="review.comment_visible" type="checkbox"> Montrer ce commentaire au livreur</label>
          <p v-if="reviewSaved" class="text-sm font-semibold text-emerald-700">Évaluation enregistrée.</p>
          <button class="btn-primary w-full" :disabled="!reviewComplete || saving">{{ saving ? 'Enregistrement…' : 'Enregistrer l\'évaluation' }}</button>
          <div v-if="d.reviews.length" class="border-t border-slate-100 pt-3 space-y-1 text-sm">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Évaluations précédentes</p>
            <p v-for="r in d.reviews" :key="r.id" class="flex justify-between"><span>{{ monthName(r.period) }}<span class="text-gray-500"> · {{ r.reviewer || '—' }}</span></span><b>{{ scoreText(r.score) }}</b></p>
          </div>
        </form>
      </div>
    </template>

    <Modal :open="!!cancelling" title="Annuler cet avis" @close="cancelling = null">
      <form class="space-y-3" @submit.prevent="cancel">
        <p class="text-sm text-gray-600">L'avis ne comptera plus dans la note du livreur.</p>
        <div><label class="label" for="cancel-reason-d">Raison</label><input id="cancel-reason-d" v-model="cancelReason" class="input" placeholder="Ex. : client absent, pas la faute du livreur"></div>
        <button class="btn-primary w-full">Annuler l'avis</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import StarRating from '../../components/ratings/StarRating.vue'
import { scoreText } from '../../components/ratings/ratingWords'
import { dateTime, money } from '../../utils/format'

const route = useRoute()
const d = ref(null)
const error = ref('')
const saving = ref(false)
const reviewSaved = ref(false)
const cancelling = ref(null)
const cancelReason = ref('')

const thisMonth = new Date().toISOString().slice(0, 7)
const review = reactive({ period: thisMonth, criteria: {}, comment: '', comment_visible: false })

const initials = computed(() => (d.value?.courier.name || '?').split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase())
const below = computed(() => d.value?.score !== null && d.value?.count >= 5 && d.value?.score < d.value?.threshold)
const sourceRows = computed(() => [
  { key: 'recipient', label: 'Clients', unit: 'avis' },
  { key: 'merchant', label: 'E-commerçants', unit: 'avis' },
  { key: 'manager', label: 'Responsables', unit: 'évaluation(s)' },
].map((s) => ({ ...s, ...d.value.sources[s.key] })))
const reviewComplete = computed(() => d.value && Object.keys(d.value.criteria).every((k) => review.criteria[k] >= 1))
const monthName = (iso) => new Date(`${iso.slice(0, 7)}-15`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
const monthLabel = computed(() => monthName(`${review.period}-01`))
const barColor = (s) => (s === null ? '#e2e8f0' : s >= 4 ? '#10b981' : s >= 3.5 ? '#84cc16' : '#f59e0b')

// Reprend l'évaluation déjà faite pour le mois choisi
function fillReview() {
  const existing = d.value.reviews.find((r) => r.period.slice(0, 7) === review.period)
  review.criteria = existing ? { ...existing.criteria } : {}
  review.comment = existing?.comment || ''
  review.comment_visible = existing?.comment_visible || false
  reviewSaved.value = false
}

async function load() {
  try {
    d.value = (await http.get(`/couriers/${route.params.id}/ratings`)).data.data
    fillReview()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function saveReview() {
  saving.value = true
  try {
    await http.post(`/couriers/${route.params.id}/reviews`, { ...review })
    await load()
    reviewSaved.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function handle(r) {
  await http.post(`/courier-ratings/${r.id}/handle`).catch((e) => { error.value = apiErrorMessage(e) })
  await load()
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

onMounted(load)
</script>
