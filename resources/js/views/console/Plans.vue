<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Formules d'abonnement</h1>
        <p class="text-sm text-gray-500">
          Forfait mensuel avec des courses incluses puis un prix par course en plus, ou formule à la livraison (pourcentage des frais de chaque course). Essai gratuit des nouvelles entreprises :
          {{ trialDays ? `${trialDays} jours` : 'aucun' }} (réglage PLATFORM_TRIAL_DAYS).
        </p>
      </div>
      <button class="btn-primary" @click="edit(null)">+ Nouvelle formule</button>
    </div>

    <div v-if="loaded && !plans.length" class="card p-6 text-center space-y-3">
      <p class="text-gray-600">Aucune formule pour l'instant. Partez des quatre formules proposées, puis ajustez prix et limites.</p>
      <button class="btn-primary" :disabled="creating" @click="createSuggested">Créer À la livraison, Essentiel, Pro et Entreprise</button>
    </div>

    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="p in plans" :key="p.id" :class="['card p-4 space-y-3', !p.is_active && 'opacity-60']">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="font-semibold text-lg">{{ p.name }}</h2>
            <p v-if="p.description" class="text-sm text-gray-500">{{ p.description }}</p>
          </div>
          <span v-if="!p.is_active" class="rounded-full bg-slate-200 px-2 py-0.5 text-xs">Retirée</span>
        </div>
        <template v-if="p.pricing === 'percent'">
          <p class="text-2xl font-bold">{{ rate(p.percent_rate) }} <span class="text-sm font-normal text-gray-500">des frais de chaque course</span></p>
          <ul class="text-sm space-y-1">
            <li>↕️ {{ money(p.percent_min) }} au moins{{ p.percent_max !== null ? `, ${money(p.percent_max)} au plus` : '' }} par course</li>
            <li v-if="p.volume_threshold">📉 {{ rate(p.volume_rate) }} au-delà de {{ p.volume_threshold }} courses par mois</li>
            <li v-if="p.cap_amount !== null">🧢 Plafond de {{ money(p.cap_amount) }} par mois</li>
            <li v-if="p.min_invoice">🧾 Facture reportée sous {{ money(p.min_invoice) }}</li>
            <li>🛵 {{ couriersText(p) }}</li>
          </ul>
        </template>
        <p v-else class="text-2xl font-bold">{{ money(p.price_monthly) }} <span class="text-sm font-normal text-gray-500">/ mois</span></p>
        <ul v-if="p.pricing !== 'percent'" class="text-sm space-y-1">
          <li>📦 {{ p.included_orders === null ? 'Courses illimitées' : `${p.included_orders} courses incluses` }}</li>
          <li v-if="p.included_orders !== null">➕ {{ money(p.overage_per_order) }} par course en plus</li>
          <li>🛵 {{ couriersText(p) }}</li>
          <li v-if="p.min_invoice">🧾 Facture reportée sous {{ money(p.min_invoice) }}</li>
        </ul>
        <ul class="text-sm space-y-1 border-t pt-2">
          <li v-for="f in features" :key="f.key" :class="p.features.includes(f.key) ? 'text-gray-800' : 'text-gray-400 line-through'">
            {{ p.features.includes(f.key) ? '✓' : '✗' }} {{ f.label }}
          </li>
        </ul>
        <div class="flex items-center justify-between border-t pt-2">
          <span class="text-sm text-gray-500">{{ p.companies_count }} entreprise{{ p.companies_count > 1 ? 's' : '' }}</span>
          <button class="btn-secondary" @click="edit(p)">Modifier</button>
        </div>
      </div>
    </div>

    <Modal :open="form.open" :title="form.id ? `Formule ${form.name}` : 'Nouvelle formule'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div><label class="label" for="p-name">Nom *</label><input id="p-name" v-model.trim="form.name" class="input" required maxlength="60"></div>
        <div><label class="label" for="p-desc">Description</label><input id="p-desc" v-model.trim="form.description" class="input" maxlength="500"></div>
        <div class="grid grid-cols-2 gap-2">
          <label v-for="m in PRICINGS" :key="m.value" :class="['flex cursor-pointer gap-2 rounded-lg border p-2 text-sm', form.pricing === m.value ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200']">
            <input v-model="form.pricing" type="radio" :value="m.value" class="mt-1 accent-emerald-700">
            <span><span class="block font-medium">{{ m.label }}</span><span class="block text-xs text-gray-500">{{ m.help }}</span></span>
          </label>
        </div>
        <div v-if="form.pricing === 'percent'" class="grid grid-cols-2 gap-3">
          <div><label class="label" for="p-rate">Pourcentage par course (%) *</label><input id="p-rate" v-model.number="form.percent_rate" type="number" min="0.01" max="100" step="0.01" class="input" required></div>
          <div>
            <label class="label" for="p-couriers2">Livreurs actifs au plus</label>
            <input id="p-couriers2" v-model.number="form.max_couriers" type="number" min="1" class="input" placeholder="Illimité">
          </div>
          <div><label class="label" for="p-min">Minimum par course (F)</label><input id="p-min" v-model.number="form.percent_min" type="number" min="0" class="input"></div>
          <div><label class="label" for="p-max">Maximum par course (F)</label><input id="p-max" v-model.number="form.percent_max" type="number" min="0" class="input" placeholder="Aucun"></div>
          <div><label class="label" for="p-threshold">Au-delà de (courses par mois)</label><input id="p-threshold" v-model.number="form.volume_threshold" type="number" min="1" class="input" placeholder="Pas de palier"></div>
          <div>
            <label class="label" for="p-vrate">Pourcentage réduit (%)</label>
            <input id="p-vrate" v-model.number="form.volume_rate" type="number" min="0.01" max="100" step="0.01" class="input" :disabled="orNull(form.volume_threshold) === null" :required="orNull(form.volume_threshold) !== null">
          </div>
          <div class="col-span-2">
            <label class="label" for="p-cap">Plafond par mois (F)</label>
            <input id="p-cap" v-model.number="form.cap_amount" type="number" min="0" class="input" placeholder="Aucun plafond">
            <p class="text-xs text-gray-500 mt-1">L'entreprise ne paie jamais plus que ce montant sur un mois (ramené à la semaine si elle paie chaque semaine).</p>
          </div>
          <p class="col-span-2 text-xs text-gray-500">
            Exemple : course à 1 500 F → {{ money(example(1500)) }} ; course à 10 000 F → {{ money(example(10000)) }}. Les frais comptés sont ceux de la course et ses suppléments.
          </p>
        </div>
        <div v-else class="grid grid-cols-2 gap-3">
          <div><label class="label" for="p-price">Prix mensuel (F) *</label><input id="p-price" v-model.number="form.price_monthly" type="number" min="0" class="input" required></div>
          <div>
            <label class="label" for="p-couriers">Livreurs actifs au plus</label>
            <input id="p-couriers" v-model.number="form.max_couriers" type="number" min="1" class="input" placeholder="Illimité">
          </div>
          <div>
            <label class="label" for="p-orders">Courses incluses par mois</label>
            <input id="p-orders" v-model.number="form.included_orders" type="number" min="0" class="input" placeholder="Illimité">
          </div>
          <div>
            <label class="label" for="p-overage">Prix par course en plus (F)</label>
            <input id="p-overage" v-model.number="form.overage_per_order" type="number" min="0" class="input" :disabled="form.included_orders === '' || form.included_orders === null">
          </div>
        </div>
        <div>
          <label class="label" for="p-extra">Prix d'un livreur en plus (F par mois)</label>
          <input id="p-extra" v-model.number="form.extra_courier_price" type="number" min="0" class="input" placeholder="Pas de livreur en plus" :disabled="orNull(form.max_couriers) === null">
          <p class="text-xs text-gray-500 mt-1">Au-delà des livreurs de la formule, l'entreprise peut en ajouter : chacun est facturé ce prix par mois, au prorata des jours où il est actif. Vide : elle ne peut pas dépasser la limite.</p>
        </div>
        <div>
          <label class="label" for="p-mininv">Montant minimum d'une facture (F)</label>
          <input id="p-mininv" v-model.number="form.min_invoice" type="number" min="0" class="input" placeholder="Aucun">
          <p class="text-xs text-gray-500 mt-1">En dessous, la facture n'est pas à payer : son montant est ajouté à la suivante.</p>
        </div>
        <fieldset class="border rounded p-3 space-y-2">
          <legend class="text-sm font-medium px-1">Modules inclus</legend>
          <label v-for="f in features" :key="f.key" class="flex items-start gap-2 text-sm">
            <input v-model="form.features" type="checkbox" :value="f.key" class="mt-1">
            <span>{{ f.label }}<span class="block text-gray-500">{{ f.description }}</span></span>
          </label>
          <p class="text-xs text-gray-500">Courses, livreurs, caisse, suivi, notifications et import Excel sont inclus dans toutes les formules.</p>
        </fieldset>
        <label v-if="form.id" class="flex items-start gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="mt-1">
          <span>Proposée aux nouvelles entreprises<span class="block text-gray-500">Décochée : la formule est retirée, les entreprises qui l'ont la gardent.</span></span>
        </label>
        <p v-if="form.error" class="field-error">{{ form.error }}</p>
        <button class="btn-primary w-full" :disabled="form.saving">{{ form.saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useToastStore } from '../../stores/toasts'
import { money, rate } from '../../utils/format'

const PRICINGS = [
  { value: 'flat', label: 'Forfait', help: 'Prix fixe, courses incluses, puis prix par course en plus' },
  { value: 'percent', label: 'À la livraison', help: 'Pourcentage des frais de chaque course prise en charge' },
]

const toasts = useToastStore()
const plans = ref([])
const features = ref([])
const trialDays = ref(0)
const loaded = ref(false)
const creating = ref(false)
const form = reactive({ open: false })

function apply(data) {
  plans.value = data.data
  features.value = data.meta.features
  trialDays.value = data.meta.trial_days
  loaded.value = true
}

async function load() {
  apply((await http.get('/console/plans')).data)
}

async function createSuggested() {
  creating.value = true
  try {
    apply((await http.post('/console/plans/suggested')).data)
    toasts.success('Formules créées : ajustez-les si besoin.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

function edit(plan) {
  Object.assign(form, {
    open: true, error: '', saving: false,
    id: plan?.id ?? null,
    name: plan?.name ?? '',
    description: plan?.description ?? '',
    price_monthly: plan?.price_monthly ?? 0,
    included_orders: plan?.included_orders ?? '',
    overage_per_order: plan?.overage_per_order ?? 0,
    max_couriers: plan?.max_couriers ?? '',
    extra_courier_price: plan?.extra_courier_price ?? '',
    // Pourcentages affichés en % (enregistrés en centièmes de pour cent)
    pricing: plan?.pricing ?? 'flat',
    percent_rate: plan?.percent_rate ? plan.percent_rate / 100 : 1,
    percent_min: plan?.percent_min ?? 10,
    percent_max: plan ? (plan.percent_max ?? '') : 60,
    volume_threshold: plan ? (plan.volume_threshold ?? '') : 1000,
    volume_rate: plan?.volume_rate ? plan.volume_rate / 100 : 0.75,
    cap_amount: plan ? (plan.cap_amount ?? '') : 60000,
    min_invoice: plan ? (plan.min_invoice ?? '') : 1000,
    features: [...(plan?.features ?? [])],
    is_active: plan?.is_active ?? true,
  })
}

function couriersText(p) {
  if (p.max_couriers === null) return 'Livreurs illimités'
  const extra = p.extra_courier_price !== null ? `, puis ${money(p.extra_courier_price)} par mois par livreur en plus` : ' au plus'
  return `${p.max_couriers} livreurs actifs${extra}`
}

// Champ vide = illimité
const orNull = (value) => (value === '' || value === null || value === undefined ? null : value)
const hundredths = (value) => (orNull(value) === null ? null : Math.round(value * 100))

// Part d'une course selon les réglages du formulaire (sans palier ni plafond)
function example(fee) {
  let charge = Math.round(fee * (form.percent_rate || 0) / 100)
  charge = Math.max(charge, form.percent_min || 0)
  return orNull(form.percent_max) === null ? charge : Math.min(charge, form.percent_max)
}

async function save() {
  const used = form.id ? (plans.value.find((p) => p.id === form.id)?.companies_count ?? 0) : 0
  if (used > 0 && !window.confirm(`Cette formule est utilisée par ${used} entreprise${used > 1 ? 's' : ''} : le changement s'applique à leurs prochaines factures. Enregistrer ?`)) return
  form.saving = true
  form.error = ''
  const percent = form.pricing === 'percent'
  const payload = {
    name: form.name,
    description: form.description || null,
    pricing: form.pricing,
    price_monthly: percent ? 0 : form.price_monthly,
    included_orders: percent ? null : orNull(form.included_orders),
    overage_per_order: percent || orNull(form.included_orders) === null ? 0 : (form.overage_per_order || 0),
    max_couriers: orNull(form.max_couriers),
    extra_courier_price: orNull(form.max_couriers) === null ? null : orNull(form.extra_courier_price),
    percent_rate: percent ? hundredths(form.percent_rate) : null,
    percent_min: percent ? (form.percent_min || 0) : 0,
    percent_max: percent ? orNull(form.percent_max) : null,
    volume_threshold: percent ? orNull(form.volume_threshold) : null,
    volume_rate: percent && orNull(form.volume_threshold) !== null ? hundredths(form.volume_rate) : null,
    cap_amount: percent ? orNull(form.cap_amount) : null,
    min_invoice: orNull(form.min_invoice),
    features: form.features,
    ...(form.id ? { is_active: form.is_active } : {}),
  }
  try {
    if (form.id) await http.patch(`/console/plans/${form.id}`, payload)
    else await http.post('/console/plans', payload)
    form.open = false
    toasts.success('Formule enregistrée.')
    await load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

onMounted(load)
</script>
