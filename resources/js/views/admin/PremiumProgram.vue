<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-xl font-bold">Programme Premium Jibiat Marchand</h1>
        <p class="text-sm text-gray-500">Offrez à vos meilleurs e-commerçants leur carte Membre Premium : ils vendent plus, vous livrez plus.</p>
      </div>
      <button v-if="canOffer && data && tab === 'cards'" type="button" class="btn-primary" :disabled="!canOfferMore" @click="pickerOpen = true">Offrir une carte</button>
    </div>

    <div class="flex flex-wrap gap-2" role="tablist">
      <button type="button" role="tab" :aria-selected="tab === 'cards'" class="rounded-full px-4 py-1.5 text-sm font-semibold" :class="tab === 'cards' ? 'bg-violet-600 text-white' : 'bg-slate-200 text-slate-700'" @click="tab = 'cards'">Cartes et meilleurs clients</button>
      <button type="button" role="tab" :aria-selected="tab === 'levels'" class="rounded-full px-4 py-1.5 text-sm font-semibold" :class="tab === 'levels' ? 'bg-violet-600 text-white' : 'bg-slate-200 text-slate-700'" @click="tab = 'levels'">⭐ Avantages et niveaux</button>
    </div>

    <PremiumLevelsPanel v-if="tab === 'levels'" />

    <template v-if="tab === 'cards'">
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="data && !data.program.enabled" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">Le programme n'est pas ouvert pour le moment : Jibiat vous préviendra dès son ouverture.</p>
    <p v-else-if="data && !data.program.configured" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">Le programme n'est pas encore relié à Jibiat Marchand : les cartes ne peuvent pas encore être offertes.</p>

    <template v-if="data">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="card p-4">
          <p class="text-sm text-gray-500">Cartes offertes</p>
          <p class="text-2xl font-bold text-amber-700 tabular-nums">{{ data.used }} / {{ data.quota }}</p>
          <p class="text-xs text-gray-500">{{ data.program.price ? `${money(data.program.price)} la carte, sur votre facture` : 'offertes par Jibiat' }}</p>
        </div>
        <div class="card p-4">
          <p class="text-sm text-gray-500">Actives</p>
          <p class="text-2xl font-bold text-emerald-700 tabular-nums">{{ data.active }}</p>
          <p class="text-xs text-gray-500">{{ waiting }} pas encore activée{{ waiting > 1 ? 's' : '' }}</p>
        </div>
        <div class="card p-4">
          <p class="text-sm text-gray-500">Boutiques parrainées</p>
          <p class="text-2xl font-bold text-blue-700 tabular-nums">{{ data.referrals.stores }}</p>
          <p class="text-xs text-gray-500">{{ data.referrals.paying }} avec un abonnement payé</p>
        </div>
        <div class="card p-4">
          <p class="text-sm text-gray-500">Vos parts gagnées</p>
          <p class="text-2xl font-bold text-violet-700 tabular-nums">{{ money(data.referrals.earned_total) }}</p>
          <p class="text-xs text-gray-500">{{ pct(data.referrals.rate) }} des abonnements pendant {{ data.referrals.months }} mois</p>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <!-- Meilleurs e-commerçants -->
        <section class="card overflow-hidden lg:col-span-2" aria-labelledby="best-title">
          <div class="flex items-center justify-between gap-2 px-4 py-3">
            <h2 id="best-title" class="font-semibold">Vos meilleurs e-commerçants</h2>
            <span class="text-xs text-gray-500">classés sur {{ data.program.conditions.window_days }} jours</span>
          </div>
          <p v-if="!data.ranking.length" class="px-4 pb-4 text-sm text-gray-500">Pas encore de courses livrées sur la période.</p>
          <ul v-else class="divide-y">
            <li v-for="row in data.ranking" :key="row.merchant_id" class="flex flex-wrap items-center gap-3 px-4 py-3">
              <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-sm font-bold text-white" :style="{ background: avatar(row.name) }" aria-hidden="true">{{ initials(row.name) }}</span>
              <div class="min-w-0 flex-1">
                <p class="truncate font-semibold">{{ row.name }}</p>
                <p class="text-xs text-gray-500">{{ row.delivered }} livrée{{ row.delivered > 1 ? 's' : '' }} · {{ row.success_rate === null ? '—' : pct(row.success_rate) }} de réussite · client depuis {{ months(row.client_days) }}</p>
              </div>
              <div class="flex w-28 items-center gap-2" :aria-label="`Score ${row.score} sur 100`">
                <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-emerald-500" :style="{ width: `${row.score}%` }" /></span>
                <span class="w-7 text-right text-sm font-bold tabular-nums">{{ row.score }}</span>
              </div>
              <div class="w-full text-right sm:w-36">
                <span v-if="row.has_card" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">● Carte offerte</span>
                <button v-else-if="canOffer && canOfferMore" type="button" class="rounded-full bg-amber-400 px-3 py-1 text-sm font-bold text-amber-950 hover:bg-amber-300 disabled:opacity-50" :disabled="busy" @click="confirmOffer(row)">Offrir</button>
                <span v-else class="text-xs font-semibold text-amber-700">{{ hint(row) }}</span>
              </div>
            </li>
          </ul>
        </section>

        <!-- Carte automatique -->
        <section class="card p-4 space-y-3" aria-labelledby="auto-title">
          <h2 id="auto-title" class="font-semibold">Carte automatique</h2>
          <label class="flex items-center justify-between gap-3 rounded-xl bg-emerald-50 p-3">
            <span class="text-sm font-semibold">Offrir la carte dès que les conditions sont remplies</span>
            <input v-model="auto" type="checkbox" class="h-5 w-5 accent-emerald-600" :disabled="!auth.can('settings.manage') || busy" @change="saveAuto">
          </label>
          <dl class="divide-y text-sm">
            <div class="flex justify-between gap-2 py-2"><dt class="text-gray-500">Courses livrées</dt><dd class="font-semibold">{{ data.program.conditions.min_delivered }} sur {{ data.program.conditions.window_days }} jours</dd></div>
            <div class="flex justify-between gap-2 py-2"><dt class="text-gray-500">Taux de réussite</dt><dd class="font-semibold">{{ pct(data.program.conditions.min_success_rate) }} au moins</dd></div>
            <div class="flex justify-between gap-2 py-2"><dt class="text-gray-500">Argent dû à l'agence</dt><dd class="font-semibold">aucun retard</dd></div>
            <div class="flex justify-between gap-2 py-2"><dt class="text-gray-500">Client depuis</dt><dd class="font-semibold">{{ months(data.program.conditions.min_client_days) }} au moins</dd></div>
            <div class="flex justify-between gap-2 py-2"><dt class="text-gray-500">Cartes</dt><dd class="font-semibold">{{ data.quota }} au total</dd></div>
          </dl>
          <p class="text-xs text-gray-500">La progression s'affiche dans l'application de vos e-commerçants : « Plus que 12 courses livrées pour gagner votre carte ».</p>
        </section>
      </div>

      <!-- Cartes offertes -->
      <section class="card overflow-hidden" aria-labelledby="cards-title">
        <h2 id="cards-title" class="px-4 py-3 font-semibold">Cartes offertes</h2>
        <p v-if="!data.cards.length" class="px-4 pb-4 text-sm text-gray-500">Aucune carte offerte pour l'instant.</p>
        <ul v-else class="divide-y">
          <li v-for="c in data.cards" :key="c.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
            <span class="grid h-10 w-14 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-slate-900 to-slate-700 text-[10px] font-bold text-amber-300" aria-hidden="true">PREMIUM</span>
            <div class="min-w-0 flex-1">
              <p class="truncate font-semibold">{{ c.merchant }} <span class="font-normal text-gray-500">· {{ c.label }}</span></p>
              <p class="text-xs text-gray-500">
                {{ c.reason === 'automatic' ? 'Gagnée' : `Offerte${c.offered_by ? ` par ${c.offered_by}` : ''}` }} le {{ date(c.offered_at) }}
                <template v-if="c.store_name"> · boutique {{ c.store_name }}</template>
                <template v-if="c.free_until && c.state === 'free'"> · offerte jusqu'au {{ date(c.free_until) }}</template>
              </p>
            </div>
            <span :class="['rounded-full px-2.5 py-1 text-xs font-semibold', STATE_CLASS[c.state] || 'bg-slate-100 text-slate-600']">{{ c.state_label }}</span>
            <div class="flex gap-2">
              <button type="button" class="btn-secondary !py-1" @click="copy(c.url)">{{ copied === c.url ? 'Lien copié' : 'Copier le lien' }}</button>
              <button v-if="canOffer && c.state !== 'revoked'" type="button" class="btn-secondary !py-1" :disabled="busy" @click="revoking = c">Annuler</button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Argumentaire -->
      <section class="card bg-amber-50/60 p-4 text-sm">
        <h2 class="font-semibold">Pourquoi offrir la carte ?</h2>
        <ul class="mt-2 grid gap-1.5 sm:grid-cols-2 text-gray-700">
          <li>📈 Avec leur boutique en ligne, vos e-commerçants vendent plus : vous livrez plus.</li>
          <li>🤝 Une carte offerte par vous : ils restent chez vous.</li>
          <li>📋 Leurs commandes arrivent propres : bonne adresse, bon numéro.</li>
          <li>💰 Vous touchez {{ pct(data.referrals.rate) }} de leur abonnement pendant {{ data.referrals.months }} mois.</li>
        </ul>
      </section>
    </template>
    <p v-else-if="!error" class="text-sm text-gray-500">Chargement…</p>
    </template>

    <!-- Choisir un e-commerçant -->
    <Modal :open="pickerOpen" title="Offrir une carte Membre Premium" @close="pickerOpen = false">
      <input v-model="search" type="search" class="input w-full" placeholder="Nom de l'e-commerçant" aria-label="Rechercher un e-commerçant">
      <ul class="mt-3 max-h-80 divide-y overflow-y-auto">
        <li v-for="row in pickable" :key="row.merchant_id" class="flex items-center justify-between gap-2 py-2 text-sm">
          <span class="min-w-0 truncate">{{ row.name }} <span class="text-gray-500">· {{ row.delivered }} livrées</span></span>
          <button type="button" class="btn-primary !py-1" :disabled="busy" @click="confirmOffer(row)">Offrir</button>
        </li>
        <li v-if="!pickable.length" class="py-2 text-sm text-gray-500">Aucun e-commerçant à qui offrir une carte.</li>
      </ul>
    </Modal>

    <Modal :open="!!offering" title="Offrir la carte" @close="offering = null">
      <p class="text-sm">Offrir la carte Membre Premium Jibiat Marchand à <strong>{{ offering?.name }}</strong> ? Il la reçoit tout de suite sur son téléphone, avec le lien pour l'activer.</p>
      <p v-if="data?.program.price" class="mt-2 text-sm text-amber-800">{{ money(data.program.price) }} sur votre prochaine facture.</p>
      <div class="mt-4 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="offering = null">Retour</button>
        <button type="button" class="btn-primary" :disabled="busy" @click="offer">Offrir la carte</button>
      </div>
    </Modal>

    <Modal :open="!!revoking" title="Annuler la carte" @close="revoking = null">
      <p class="text-sm">Annuler la carte de <strong>{{ revoking?.merchant }}</strong> ? Il perd l'offre Premium ; la carte ne compte plus dans vos {{ data?.quota }} cartes.</p>
      <div class="mt-4 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="revoking = null">Retour</button>
        <button type="button" class="btn-danger" :disabled="busy" @click="revoke">Annuler la carte</button>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import PremiumLevelsPanel from '../../components/premium/PremiumLevelsPanel.vue'
import { useAuthStore } from '../../stores/auth'
import { date, money } from '../../utils/format'

const auth = useAuthStore()
const data = ref(null)
const tab = ref(new URLSearchParams(location.search).get('onglet') === 'niveaux' ? 'levels' : 'cards')
const error = ref('')
const busy = ref(false)
const auto = ref(false)
const pickerOpen = ref(false)
const search = ref('')
const offering = ref(null)
const revoking = ref(null)
const copied = ref('')

const STATE_CLASS = {
  available: 'bg-slate-100 text-slate-700',
  free: 'bg-emerald-50 text-emerald-700',
  discount: 'bg-blue-50 text-blue-700',
  lapsed: 'bg-amber-50 text-amber-800',
  revoked: 'bg-red-50 text-red-700',
}
const AVATARS = ['#8B6CF2', '#4C8DF6', '#F0587A', '#1FA5AE', '#F5A524', '#22A06B']

const canOffer = computed(() => auth.can('merchants.manage'))
const canOfferMore = computed(() => data.value && data.value.program.enabled && data.value.program.configured && data.value.used < data.value.quota)
const waiting = computed(() => (data.value?.cards || []).filter((c) => c.state === 'available').length)
const pickable = computed(() => (data.value?.ranking || [])
  .filter((r) => !r.has_card && r.name.toLowerCase().includes(search.value.trim().toLowerCase())))

const pct = (v) => (v === null || v === undefined ? '—' : `${Math.round(v * 100)} %`)
const months = (days) => (days >= 60 ? `${Math.floor(days / 30)} mois` : `${days} jours`)
const initials = (name) => (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('')
const avatar = (name) => AVATARS[[...(name || '')].reduce((n, ch) => n + ch.charCodeAt(0), 0) % AVATARS.length]

// Ce qui manque au marchand pour la carte automatique
function hint(row) {
  if (row.eligible) return 'Conditions remplies'
  if (!row.checks.delivered) return `${row.missing_deliveries} course${row.missing_deliveries > 1 ? 's' : ''} à faire`
  if (!row.checks.success_rate) return 'taux trop bas'
  if (!row.checks.no_late_debt) return 'argent en retard'
  return 'client trop récent'
}

async function load() {
  try {
    data.value = (await http.get('/premium')).data.data
    auto.value = data.value.auto
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function confirmOffer(row) {
  pickerOpen.value = false
  offering.value = row
}

async function offer() {
  busy.value = true
  try {
    await http.post('/premium/cards', { merchant_id: offering.value.merchant_id })
    offering.value = null
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
    offering.value = null
  } finally {
    busy.value = false
  }
}

async function revoke() {
  busy.value = true
  try {
    await http.post(`/premium/cards/${revoking.value.id}/revoke`)
    revoking.value = null
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
    revoking.value = null
  } finally {
    busy.value = false
  }
}

async function saveAuto() {
  busy.value = true
  try {
    auto.value = (await http.put('/premium/auto', { auto: auto.value })).data.data.auto
  } catch (e) {
    error.value = apiErrorMessage(e)
    auto.value = !auto.value
  } finally {
    busy.value = false
  }
}

async function copy(url) {
  try {
    await navigator.clipboard.writeText(url)
    copied.value = url
    setTimeout(() => { copied.value = '' }, 2000)
  } catch {
    window.prompt('Lien de la carte', url)
  }
}

onMounted(load)
</script>
