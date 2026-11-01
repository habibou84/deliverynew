<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">Cartes Membre Premium Jibiat Marchand</h1>
      <p class="text-sm text-gray-500">Les entreprises offrent la carte à leurs meilleurs e-commerçants, à la main ou toute seule (carte automatique). Jibiat Marchand applique l'offre.</p>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="loaded && !configured" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
      Le secret partagé avec Jibiat Marchand n'est pas réglé sur le serveur (JIBIAT_PREMIUM_SECRET dans le fichier .env, le même des deux côtés) : aucune carte ne peut partir.
    </p>

    <form v-if="loaded" class="card p-4 space-y-4" @submit.prevent="save">
      <label class="flex items-center gap-2 text-sm font-semibold">
        <input v-model="form.enabled" type="checkbox" class="h-4 w-4" :disabled="!owner"> Programme ouvert aux entreprises
      </label>
      <div class="grid gap-3 sm:grid-cols-3">
        <label class="block text-sm">Qui paie les cartes
          <select v-model="form.funded_by" class="input mt-1 w-full" :disabled="!owner">
            <option v-for="(label, key) in funders" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
        <label class="block text-sm">Prix d'une carte (F)
          <input v-model.number="form.price" type="number" min="0" class="input mt-1 w-full" :disabled="!owner || form.funded_by !== 'company'">
          <span class="text-xs text-gray-500">Sur la facture du mois de l'entreprise</span>
        </label>
        <label class="block text-sm">Cartes par entreprise
          <input v-model.number="form.quota" type="number" min="0" class="input mt-1 w-full" :disabled="!owner">
        </label>
      </div>
      <fieldset class="space-y-2">
        <legend class="text-sm font-semibold">Conditions de la carte automatique</legend>
        <div class="grid gap-3 sm:grid-cols-5">
          <label class="block text-sm">Courses livrées<input v-model.number="form.min_delivered" type="number" min="1" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Sur (jours)<input v-model.number="form.window_days" type="number" min="7" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Réussite (%)<input v-model.number="successPct" type="number" min="0" max="100" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Client depuis (jours)<input v-model.number="form.min_client_days" type="number" min="0" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Argent dû depuis (jours) au plus<input v-model.number="form.max_debt_days" type="number" min="0" class="input mt-1 w-full" :disabled="!owner"></label>
        </div>
      </fieldset>
      <fieldset class="space-y-2">
        <legend class="text-sm font-semibold">Niveaux des entreprises : ce qu'elles ont le droit d'offrir</legend>
        <div class="grid gap-3 sm:grid-cols-4">
          <label class="block text-sm">Niveaux au plus<input v-model.number="form.max_levels" type="number" min="1" max="3" class="input mt-1 w-full" :disabled="!owner"></label>
          <label class="block text-sm">Réduction livraison au plus (%)<input v-model.number="form.max_delivery_discount" type="number" min="0" max="90" class="input mt-1 w-full" :disabled="!owner"></label>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1.5 pr-2 font-medium">Formule Jibiat Marchand</th><th class="px-2 font-medium">Permise</th><th class="px-2 font-medium">Mois offerts au plus</th><th class="px-2 font-medium">Réduction ensuite au plus (%)</th><th class="px-2 font-medium">Prix d'une carte (F)</th></tr></thead>
            <tbody>
              <tr v-for="(p, code) in plans" :key="code" class="border-t">
                <td class="py-2 pr-2"><b>{{ features[code]?.name || code }}</b><span class="block text-xs text-gray-500">{{ (features[code]?.features || []).join(' · ') }}</span></td>
                <td class="px-2"><input v-model="p.allowed" type="checkbox" class="h-4 w-4" :disabled="!owner" :aria-label="`${code} permise`"></td>
                <td class="px-2"><input v-model.number="p.max_months" type="number" min="0" max="36" class="input w-20" :disabled="!owner || !p.allowed"></td>
                <td class="px-2"><input v-model.number="p.max_discount" type="number" min="0" max="90" class="input w-20" :disabled="!owner || !p.allowed"></td>
                <td class="px-2"><input v-model.number="p.price" type="number" min="0" class="input w-28" :disabled="!owner || !p.allowed || form.funded_by !== 'company'"></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="text-xs text-gray-500">Le prix d'une carte s'applique quand l'entreprise paie ses cartes ; il va sur sa facture du mois.</p>
      </fieldset>
      <div v-if="owner" class="flex justify-end">
        <button type="submit" class="btn-primary" :disabled="saving">Enregistrer</button>
      </div>
    </form>

    <section v-if="loaded" class="card overflow-hidden">
      <h2 class="px-4 py-3 font-semibold">Entreprises</h2>
      <ul class="divide-y">
        <li v-for="c in companies" :key="c.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
          <RouterLink :to="`/console/entreprises/${c.id}`" class="min-w-0 flex-1 truncate font-semibold hover:underline">{{ c.name }}</RouterLink>
          <span class="text-gray-500">{{ c.used }} / {{ c.effective_quota }} offertes · {{ c.active }} active{{ c.active > 1 ? 's' : '' }}</span>
          <span :class="['rounded-full px-2 py-0.5 text-xs', c.auto ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600']">{{ c.auto ? 'Carte automatique' : 'À la main' }}</span>
          <span v-if="c.levels.length" class="w-full text-xs text-gray-500 sm:w-auto">⭐ {{ c.levels.map((l) => `${l.name} (${l.merchants}${l.delivery_discount_percent ? `, −${l.delivery_discount_percent} %` : ''}${l.jm_plan ? `, ${features[l.jm_plan]?.name} ${l.jm_free_months} mois` : ''})`).join(' · ') }}</span>
          <label v-if="owner" class="flex items-center gap-1 text-xs text-gray-500">Cartes
            <input v-model.number="c.quota" type="number" min="0" class="input w-20 !py-1" :placeholder="String(form.quota)" @change="saveCompany(c)">
          </label>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { consoleCan } from '../../roles'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const auth = useAuthStore()
const toasts = useToastStore()
const owner = computed(() => consoleCan(auth.role, 'owner'))
const loaded = ref(false)
const saving = ref(false)
const error = ref('')
const configured = ref(true)
const funders = ref({})
const companies = ref([])
const form = reactive({})
const plans = ref({})
const features = ref({})

// Taux de réussite saisi en pourcentage, gardé de 0 à 1
const successPct = computed({
  get: () => Math.round((form.min_success_rate || 0) * 100),
  set: (v) => { form.min_success_rate = Math.min(100, Math.max(0, Number(v) || 0)) / 100 },
})

function apply(data) {
  Object.assign(form, data.settings)
  plans.value = JSON.parse(JSON.stringify(data.plans || {}))
  features.value = data.plan_features || {}
  configured.value = data.configured
  funders.value = data.funders
  companies.value = data.companies
  loaded.value = true
}

async function load() {
  try {
    apply((await http.get('/console/premium')).data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save() {
  saving.value = true
  try {
    apply((await http.put('/console/premium', { ...form, plans: plans.value })).data.data)
    toasts.success('Réglages enregistrés.')
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function saveCompany(c) {
  try {
    const quota = c.quota === '' || c.quota === null || Number.isNaN(c.quota) ? null : c.quota
    const { data } = await http.put(`/console/premium/companies/${c.id}`, { quota })
    c.quota = data.data.quota
    c.effective_quota = data.data.effective_quota
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

onMounted(load)
</script>
