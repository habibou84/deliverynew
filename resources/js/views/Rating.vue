<template>
  <div class="min-h-screen bg-white">
    <header class="flex items-center gap-3 border-b border-slate-200 px-5 py-4">
      <BrandLogo size="sm" />
    </header>

    <main class="mx-auto max-w-md px-5 py-6 space-y-6">
      <p v-if="loading" class="py-16 text-center text-slate-500">Chargement…</p>
      <p v-else-if="notFound" class="py-16 text-center text-slate-600">Ce lien d'avis n'existe pas ou n'est plus valable.</p>

      <template v-else-if="info">
        <div class="flex items-center gap-3">
          <span class="h-12 w-12 shrink-0 rounded-full bg-emerald-100 text-emerald-800 grid place-items-center text-lg font-bold" aria-hidden="true">{{ initial }}</span>
          <div class="min-w-0">
            <p class="text-lg font-bold">{{ info.courier_first_name ? `${info.courier_first_name} vous a livré` : 'Votre colis a été livré' }}</p>
            <p class="text-sm text-slate-500">{{ deliveredText }}<template v-if="info.merchant_name"> · {{ info.merchant_name }}</template></p>
          </div>
        </div>

        <!-- Déjà noté, ou trop tard -->
        <div v-if="info.rated" class="rounded-2xl bg-emerald-50 p-6 text-center space-y-2">
          <p class="text-4xl" aria-hidden="true">🙏</p>
          <p class="text-xl font-bold">Merci pour votre avis !</p>
          <StarRating v-if="info.score" :model-value="info.score" readonly size="md" />
          <p class="text-sm text-slate-600">Il aide {{ info.courier_first_name || 'le livreur' }} et toute l'équipe à mieux vous servir.</p>
        </div>
        <div v-else-if="info.expired" class="rounded-2xl bg-slate-100 p-6 text-center text-slate-600">
          Le délai pour noter cette livraison est passé (7 jours). Merci quand même !
        </div>

        <form v-else class="space-y-6" @submit.prevent="send">
          <div>
            <h1 class="text-2xl font-bold mb-3">Notez la livraison</h1>
            <StarRating v-model="score" label="Note de la livraison" />
            <p class="mt-1 h-5 text-sm font-semibold text-amber-700">{{ SCORE_WORDS[score] || '' }}</p>
          </div>

          <fieldset>
            <legend class="font-semibold mb-2">Qu'avez-vous apprécié ?</legend>
            <div class="flex flex-wrap gap-2">
              <button v-for="t in goodTags" :key="t.code" type="button" :aria-pressed="tags.includes(t.code)" :class="chip(t)" @click="toggle(t.code)">{{ t.label }}</button>
            </div>
          </fieldset>
          <fieldset>
            <legend class="font-semibold mb-2">Un problème ?</legend>
            <div class="flex flex-wrap gap-2">
              <button v-for="t in badTags" :key="t.code" type="button" :aria-pressed="tags.includes(t.code)" :class="chip(t)" @click="toggle(t.code)">{{ t.label }}</button>
            </div>
          </fieldset>

          <div>
            <label for="rating-comment" class="sr-only">Un mot pour le livreur</label>
            <textarea id="rating-comment" v-model="comment" rows="3" maxlength="500" class="input" placeholder="Un mot pour le livreur (facultatif)…" />
          </div>

          <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
          <button class="w-full rounded-xl bg-emerald-700 py-3.5 font-semibold text-white disabled:opacity-50" :disabled="!score || sending">
            {{ sending ? 'Envoi…' : 'Envoyer mon avis' }}
          </button>
          <p class="text-center text-xs text-slate-500">Votre avis est anonyme : le livreur ne voit ni votre nom ni votre numéro.</p>
        </form>
      </template>
    </main>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import BrandLogo from '../components/auth/BrandLogo.vue'
import StarRating from '../components/ratings/StarRating.vue'
import { SCORE_WORDS } from '../components/ratings/ratingWords'
import { loadBranding } from '../composables/useBranding'
import { dateTime } from '../utils/format'
import { siteOrigin } from '../native'

/**
 * Page d'avis du destinataire : lien reçu sur WhatsApp après la livraison.
 */
const route = useRoute()
const info = ref(null)
const loading = ref(true)
const notFound = ref(false)
const score = ref(0)
const tags = ref([])
const comment = ref('')
const sending = ref(false)
const error = ref('')

const url = () => `${siteOrigin()}/api/v1/ratings/${encodeURIComponent(route.params.token)}`
const headers = { Accept: 'application/json' }

const initial = computed(() => (info.value?.courier_first_name || '✓')[0].toUpperCase())
const deliveredText = computed(() => (info.value?.delivered_at ? `Livré le ${dateTime(info.value.delivered_at)}` : 'Livré'))
const goodTags = computed(() => (info.value?.tags || []).filter((t) => t.good))
const badTags = computed(() => (info.value?.tags || []).filter((t) => !t.good))

const chip = (t) => ['tap rounded-full border-[1.5px] px-3.5 py-2 text-sm font-semibold transition', tags.value.includes(t.code)
  ? (t.good ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-red-400 bg-red-50 text-red-800')
  : 'border-slate-300 bg-white text-slate-700']

function toggle(code) {
  tags.value = tags.value.includes(code) ? tags.value.filter((c) => c !== code) : [...tags.value, code]
}

async function send() {
  sending.value = true
  error.value = ''
  try {
    const { data } = await axios.post(url(), { score: score.value, tags: tags.value, comment: comment.value || null }, { headers })
    info.value = data.data
  } catch (e) {
    error.value = e.response?.data?.message || 'Envoi impossible pour le moment. Réessayez.'
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  loadBranding()
  try {
    info.value = (await axios.get(url(), { headers })).data.data
  } catch (e) {
    notFound.value = true
  } finally {
    loading.value = false
  }
})
</script>
