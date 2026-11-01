<template>
  <div class="space-y-4">
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="saved" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700" role="status">{{ saved }}</p>

    <template v-if="data">
      <!-- Niveaux -->
      <section class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h2 class="font-semibold">🏅 Niveaux</h2>
            <p class="text-sm text-gray-500">Un bilan le 1er de chaque mois : chaque e-commerçant passe au plus haut niveau dont il remplit les conditions (sur {{ data.limits.window_days }} jours). Vous pouvez garder un seul niveau.</p>
          </div>
          <div class="flex gap-2">
            <button v-if="canEdit && levels.length < data.limits.max_levels" type="button" class="btn-secondary" @click="addLevel">+ Ajouter un niveau</button>
          </div>
        </div>
        <p v-if="!levels.length" class="mt-3 text-sm text-gray-500">Aucun niveau pour le moment. Ajoutez-en un pour récompenser vos meilleurs clients.</p>
        <div class="mt-3 grid gap-3" :class="{ 2: 'md:grid-cols-2', 3: 'md:grid-cols-3' }[levels.length]">
          <button v-for="(l, i) in levels" :key="i" type="button" class="flex flex-col justify-start rounded-xl border p-3 text-left transition" :class="i === current ? 'border-violet-500 ring-2 ring-violet-200' : 'border-slate-200 hover:border-violet-300'" @click="current = i">
            <div class="flex items-center justify-between gap-2">
              <span class="font-semibold">{{ l.name || 'Sans nom' }}</span>
              <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="BADGES[i]">{{ l.merchants || 0 }} marchand{{ (l.merchants || 0) > 1 ? 's' : '' }}</span>
            </div>
            <ul class="mt-2 space-y-0.5 text-xs text-slate-700">
              <li v-for="a in advantages(l)" :key="a">✓ {{ a }}</li>
              <li v-if="!advantages(l).length" class="text-gray-400">Aucun avantage coché</li>
            </ul>
            <p class="mt-2 border-t border-dashed pt-1.5 text-xs text-gray-500">{{ l.min_delivered }} courses · {{ Math.round(l.min_success_rate * 100) }} % de réussite<template v-if="l.min_client_days"> · client depuis {{ l.min_client_days }} j</template></p>
          </button>
        </div>
      </section>

      <div v-if="level" class="grid gap-4 lg:grid-cols-5">
        <!-- Conditions et avantages livraison -->
        <section class="card p-4 lg:col-span-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">🚚 Niveau « {{ level.name }} »</h2>
            <button v-if="canEdit" type="button" class="text-sm text-red-600 hover:underline" @click="removeLevel">Supprimer ce niveau</button>
          </div>
          <div class="mt-3 grid gap-3 sm:grid-cols-4">
            <label class="block text-sm sm:col-span-1">Nom<input v-model.trim="level.name" class="input mt-1 w-full" maxlength="40" :disabled="!canEdit"></label>
            <label class="block text-sm">Courses livrées<input v-model.number="level.min_delivered" type="number" min="0" class="input mt-1 w-full" :disabled="!canEdit"></label>
            <label class="block text-sm">Réussite (%)<input v-model.number="level.success_pct" type="number" min="0" max="100" class="input mt-1 w-full" :disabled="!canEdit"></label>
            <label class="block text-sm">Client depuis (jours)<input v-model.number="level.min_client_days" type="number" min="0" class="input mt-1 w-full" :disabled="!canEdit"></label>
          </div>

          <h3 class="mt-4 text-sm font-semibold text-gray-700">Avantages livraison</h3>
          <div class="mt-1 divide-y">
            <div class="flex flex-wrap items-center gap-3 py-2.5">
              <input id="adv-discount" v-model="on.discount" type="checkbox" class="h-5 w-5 accent-violet-600" :disabled="!canEdit">
              <label for="adv-discount" class="min-w-0 flex-1"><b class="block text-sm">Réduction sur les frais de livraison</b><span class="text-xs text-gray-500">Sur chaque course, retirée du tarif (au plus {{ data.limits.max_delivery_discount }} %)</span></label>
              <span class="flex items-center gap-1"><input v-model.number="level.delivery_discount_percent" type="number" min="0" :max="data.limits.max_delivery_discount" class="input w-20 text-right" :disabled="!canEdit || !on.discount"> %</span>
            </div>
            <div class="flex flex-wrap items-center gap-3 py-2.5">
              <input id="adv-payout" v-model="on.payout" type="checkbox" class="h-5 w-5 accent-violet-600" :disabled="!canEdit">
              <label for="adv-payout" class="min-w-0 flex-1"><b class="block text-sm">Paiement plus rapide</b><span class="text-xs text-gray-500">Ces e-commerçants passent en tête des paiements de la caisse</span></label>
              <select v-model="level.payout_frequency" class="input w-44" :disabled="!canEdit || !on.payout">
                <option v-for="(label, key) in data.limits.payout_frequencies" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
            <div class="flex flex-wrap items-center gap-3 py-2.5">
              <input id="adv-pickup" v-model="level.priority_pickup" type="checkbox" class="h-5 w-5 accent-violet-600" :disabled="!canEdit">
              <label for="adv-pickup" class="min-w-0 flex-1"><b class="block text-sm">Ramassage prioritaire</b><span class="text-xs text-gray-500">Ses colis passent en tête des ramassages à prévoir</span></label>
            </div>
            <div class="flex flex-wrap items-center gap-3 py-2.5">
              <input id="adv-cutoff" v-model="on.cutoff" type="checkbox" class="h-5 w-5 accent-violet-600" :disabled="!canEdit">
              <label for="adv-cutoff" class="min-w-0 flex-1"><b class="block text-sm">Heure limite plus tardive</b><span class="text-xs text-gray-500">Pour la livraison du jour même</span></label>
              <select v-model.number="level.cutoff_extra_minutes" class="input w-32" :disabled="!canEdit || !on.cutoff">
                <option :value="30">+30 min</option><option :value="60">+1 h</option><option :value="90">+1 h 30</option><option :value="120">+2 h</option>
              </select>
            </div>
            <div class="flex flex-wrap items-center gap-3 py-2.5">
              <input id="adv-return" v-model="on.returns" type="checkbox" class="h-5 w-5 accent-violet-600" :disabled="!canEdit">
              <label for="adv-return" class="min-w-0 flex-1"><b class="block text-sm">Retours moins chers</b><span class="text-xs text-gray-500">100 % : retours gratuits</span></label>
              <span class="flex items-center gap-1">−<input v-model.number="level.return_discount_percent" type="number" min="0" max="100" class="input w-20 text-right" :disabled="!canEdit || !on.returns"> %</span>
            </div>
          </div>
          <p v-if="level.id" class="mt-3 rounded-lg bg-cyan-50 px-3 py-2 text-sm text-cyan-800">💡 Ce mois-ci : <b>{{ money(level.discounts_month || 0) }}</b> de réductions accordées sur {{ level.orders_month || 0 }} course{{ (level.orders_month || 0) > 1 ? 's' : '' }}.</p>
        </section>

        <!-- Jibiat Marchand offert -->
        <section class="card p-4 lg:col-span-2">
          <h2 class="font-semibold">📱 Jibiat Marchand offert</h2>
          <p class="text-sm text-gray-500">L'application de boutique offerte aux e-commerçants de ce niveau, dans les limites fixées par Jibiat.</p>
          <div class="mt-3 grid grid-cols-3 gap-2">
            <button type="button" class="rounded-xl border p-2 text-center" :class="!level.jm_plan ? 'border-violet-500 bg-violet-50' : 'border-slate-200'" :disabled="!canEdit" @click="level.jm_plan = null"><b class="block text-sm">Aucun</b><span class="text-xs text-gray-500">—</span></button>
            <button v-for="(p, code) in data.limits.plans" :key="code" type="button" class="rounded-xl border p-2 text-center" :class="level.jm_plan === code ? 'border-violet-500 bg-violet-50' : 'border-slate-200'" :disabled="!canEdit" @click="pickPlan(code)">
              <b class="block text-sm">{{ p.name }}</b><span class="text-xs text-gray-500">{{ money(p.monthly_price) }} / mois</span>
            </button>
          </div>
          <template v-if="plan">
            <label class="mt-3 flex items-center justify-between gap-2 text-sm"><span><b>Mois offerts</b><span class="block text-xs text-gray-500">Autorisé par Jibiat : 0 à {{ plan.max_months }}</span></span>
              <input v-model.number="level.jm_free_months" type="number" min="0" :max="plan.max_months" class="input w-20 text-right" :disabled="!canEdit"></label>
            <label class="mt-2 flex items-center justify-between gap-2 text-sm"><span><b>Réduction ensuite (%)</b><span class="block text-xs text-gray-500">Tant qu'il livre avec vous · autorisé : 0 à {{ plan.max_discount }} %</span></span>
              <input v-model.number="level.jm_discount_percent" type="number" min="0" :max="plan.max_discount" class="input w-20 text-right" :disabled="!canEdit"></label>
            <div class="mt-3 rounded-xl border-2 border-violet-500 bg-violet-50 p-3">
              <p class="text-xs font-bold text-violet-800">CE QUE REÇOIT LE MARCHAND</p>
              <p class="text-lg font-extrabold text-violet-800">{{ plan.name }} · {{ level.jm_free_months || 0 }} mois offerts</p>
              <ul class="mt-1 space-y-0.5 text-sm"><li v-for="f in plan.features" :key="f">✓ {{ f }}</li></ul>
            </div>
            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ plan.price ? `Prix d'une carte : ${money(plan.price)}, ajouté à votre facture Jibiat du mois.` : 'Carte offerte par Jibiat.' }}</p>
          </template>
        </section>
      </div>

      <div v-if="canEdit" class="card flex flex-wrap items-center justify-between gap-3 p-3">
        <p class="text-sm text-gray-600">Dernier bilan : <b>{{ data.reviewed_at ? date(data.reviewed_at) : 'pas encore fait' }}</b>. Le marchand qui monte de niveau est prévenu par WhatsApp.</p>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary" :disabled="busy || dirty" :title="dirty ? 'Enregistrez d\'abord' : ''" @click="review">Faire le bilan maintenant</button>
          <button type="button" class="btn-primary" :disabled="busy" @click="save">Enregistrer</button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { date, money } from '../../utils/format'

/**
 * Niveaux Premium de l'entreprise : conditions, avantages livraison et formule Jibiat Marchand offerte.
 */
const auth = useAuthStore()
const data = ref(null)
const levels = ref([])
const current = ref(0)
const error = ref('')
const saved = ref('')
const busy = ref(false)
const dirty = ref(false)
const on = reactive({ discount: false, payout: false, cutoff: false, returns: false })

const BADGES = ['bg-slate-200 text-slate-700', 'bg-amber-100 text-amber-800', 'bg-violet-100 text-violet-800']
const NAMES = ['Argent', 'Or', 'Platine']
const canEdit = computed(() => auth.can('settings.manage'))
const level = computed(() => levels.value[current.value] || null)
const plan = computed(() => (level.value?.jm_plan ? data.value?.limits.plans[level.value.jm_plan] : null))

function advantages(l) {
  const out = []
  if (l.delivery_discount_percent > 0) out.push(`−${l.delivery_discount_percent} % sur la livraison`)
  if (l.payout_frequency) out.push(`Paiement ${(data.value.limits.payout_frequencies[l.payout_frequency] || '').toLowerCase()}`)
  if (l.priority_pickup) out.push('Ramassage prioritaire')
  if (l.cutoff_extra_minutes > 0) out.push(`Heure limite +${l.cutoff_extra_minutes} min`)
  if (l.return_discount_percent >= 100) out.push('Retours gratuits')
  else if (l.return_discount_percent > 0) out.push(`−${l.return_discount_percent} % sur les retours`)
  if (l.jm_plan) out.push(`Jibiat Marchand ${data.value.limits.plans[l.jm_plan]?.name || l.jm_plan} offert`)
  return out
}

function fromServer(l) {
  return { ...l, success_pct: Math.round((l.min_success_rate ?? 0.85) * 100) }
}

// Cases cochées : déduites des valeurs du niveau affiché
function syncChecks() {
  const l = level.value
  if (!l) return
  on.discount = l.delivery_discount_percent > 0
  on.payout = !!l.payout_frequency
  on.cutoff = l.cutoff_extra_minutes > 0
  on.returns = l.return_discount_percent > 0
}
watch(current, syncChecks)
watch(() => ({ ...on }), (v) => {
  const l = level.value
  if (!l || !canEdit.value) return
  if (!v.discount) l.delivery_discount_percent = 0
  else if (!l.delivery_discount_percent) l.delivery_discount_percent = 5
  if (!v.payout) l.payout_frequency = null
  else if (!l.payout_frequency) l.payout_frequency = 'twice_weekly'
  if (!v.cutoff) l.cutoff_extra_minutes = 0
  else if (!l.cutoff_extra_minutes) l.cutoff_extra_minutes = 60
  if (!v.returns) l.return_discount_percent = 0
  else if (!l.return_discount_percent) l.return_discount_percent = 50
})
watch(levels, () => { dirty.value = true }, { deep: true })

function pickPlan(code) {
  const l = level.value
  const limits = data.value.limits.plans[code]
  l.jm_plan = code
  l.jm_free_months = Math.min(l.jm_free_months || limits.max_months, limits.max_months)
  l.jm_discount_percent = Math.min(l.jm_discount_percent || 0, limits.max_discount)
}

function addLevel() {
  const i = levels.value.length
  const prev = levels.value[i - 1]
  levels.value.push({
    name: NAMES[i] || `Niveau ${i + 1}`, min_delivered: prev ? prev.min_delivered * 3 : 50, success_pct: prev ? Math.min(100, prev.success_pct + 5) : 85,
    min_client_days: 0, delivery_discount_percent: 0, return_discount_percent: 0, cutoff_extra_minutes: 0, priority_pickup: false,
    payout_frequency: null, jm_plan: null, jm_free_months: 0, jm_discount_percent: 0, merchants: 0,
  })
  current.value = i
  syncChecks()
}

function removeLevel() {
  levels.value.splice(current.value, 1)
  current.value = Math.max(0, current.value - 1)
  syncChecks()
}

function apply(payload) {
  data.value = payload
  levels.value = payload.levels.map(fromServer)
  current.value = Math.min(current.value, Math.max(0, levels.value.length - 1))
  syncChecks()
  setTimeout(() => { dirty.value = false })
}

async function load() {
  try {
    apply((await http.get('/premium/levels')).data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save() {
  busy.value = true
  error.value = ''
  saved.value = ''
  try {
    const body = levels.value.map(({ success_pct: pct, ...l }) => ({
      name: l.name, min_delivered: l.min_delivered || 0, min_success_rate: (pct ?? 0) / 100, min_client_days: l.min_client_days || 0,
      delivery_discount_percent: l.delivery_discount_percent || 0, return_discount_percent: l.return_discount_percent || 0,
      cutoff_extra_minutes: l.cutoff_extra_minutes || 0, priority_pickup: !!l.priority_pickup, payout_frequency: l.payout_frequency || null,
      jm_plan: l.jm_plan || null, jm_free_months: l.jm_free_months || 0, jm_discount_percent: l.jm_discount_percent || 0,
    }))
    apply((await http.put('/premium/levels', { levels: body })).data.data)
    saved.value = 'Niveaux enregistrés.'
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function review() {
  busy.value = true
  error.value = ''
  try {
    const res = (await http.post('/premium/levels/review')).data.data
    apply(res)
    const r = res.result
    saved.value = `Bilan fait : ${r.up} monté(s), ${r.down} descendu(s), ${r.same} sans changement${r.cards ? `, ${r.cards} carte(s) Jibiat Marchand offerte(s)` : ''}.`
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
