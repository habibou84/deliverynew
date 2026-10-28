<template>
  <div class="max-w-3xl space-y-4">
    <h1 class="text-xl font-bold">Mon abonnement</h1>

    <template v-if="summary">
      <!-- À payer -->
      <div v-if="summary.billing.amount_due" :class="['card p-4 space-y-2', summary.billing.state === 'ok' ? '' : 'ring-2', ringClass]">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-semibold">Reste à payer : {{ money(summary.billing.amount_due) }}</p>
          <p v-if="summary.billing.state === 'restricted'" class="text-sm font-medium text-red-700">Création de courses suspendue jusqu'au paiement</p>
          <p v-else-if="summary.billing.days_late" class="text-sm font-medium text-amber-700">En retard de {{ summary.billing.days_late }} jour{{ summary.billing.days_late > 1 ? 's' : '' }}</p>
        </div>
        <div v-if="summary.payment_instructions" class="rounded bg-slate-50 p-3 text-sm">
          <p class="font-medium mb-1">Comment payer</p>
          <p class="whitespace-pre-line text-gray-700">{{ summary.payment_instructions }}</p>
          <p class="mt-1 text-gray-500">Indiquez le numéro de la facture avec votre paiement.</p>
        </div>
      </div>

      <div class="card p-4">
        <SubscriptionSummary :summary="summary" />
      </div>

      <!-- Applications à votre nom : incluses, en option ou non disponibles -->
      <div v-if="summary.plan && summary.apps?.length" class="card p-4 space-y-3 text-sm">
        <div>
          <h2 class="font-semibold">📱 Applications à votre nom</h2>
          <p class="text-gray-600">Vos e-commerçants et vos livreurs installent une application à votre nom, avec votre logo et vos couleurs.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="a in summary.apps" :key="a.platform" class="flex gap-3 rounded-lg border p-3">
            <span :class="['grid h-11 w-11 flex-none place-items-center rounded-xl text-xl', a.status === 'unavailable' ? 'bg-slate-100' : 'bg-emerald-50']">{{ a.platform === 'ios' ? '🍎' : '🤖' }}</span>
            <div class="min-w-0 flex-1 space-y-1">
              <p class="flex flex-wrap items-center gap-2 font-medium">
                {{ a.label }}
                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', APP_STATUS[a.status].cls]">{{ APP_STATUS[a.status].label }}</span>
                <span v-if="a.soon" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">Bientôt</span>
              </p>
              <p class="text-gray-600">{{ appHelp(a) }}</p>
              <div class="flex flex-wrap gap-2 pt-1">
                <button v-if="a.status === 'available' && !a.soon" class="btn-primary !py-1" :disabled="appBusy" @click="requestApps(a)">Demander l'option</button>
                <button v-if="a.status === 'pending'" class="btn-secondary !py-1" :disabled="appBusy" @click="stopApps(a, 'Retirer votre demande ?')">Retirer la demande</button>
                <button v-if="a.status === 'active'" class="btn-secondary !py-1 text-red-700" :disabled="appBusy" @click="stopApps(a, 'Arrêter l\'option ? Vos applications déjà installées continueront de marcher, mais ne seront plus mises à jour.')">Arrêter l'option</button>
              </div>
            </div>
          </div>
        </div>
        <p class="text-xs text-gray-500">L'option est facturée au prorata des jours, à partir de son acceptation par Jibiat. Si vous l'arrêtez, vos applications installées continuent de marcher, sans nouvelle version.</p>
      </div>

      <!-- Comparaison des formules sur les 30 derniers jours -->
      <div v-if="summary.comparison?.length > 1" class="card p-4 space-y-2 text-sm">
        <h2 class="font-semibold">Combien vous auriez payé avec chaque formule</h2>
        <p class="text-gray-600">Calcul sur vos courses prises en charge des 30 derniers jours.</p>
        <ul class="divide-y">
          <li v-for="c in summary.comparison" :key="c.plan_id" class="flex items-center justify-between gap-2 py-2">
            <span>
              {{ c.name }}
              <span v-if="c.current" class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Votre formule</span>
              <span v-else-if="c.plan_id === cheapest" class="ml-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-800">La moins chère pour vous</span>
            </span>
            <span class="font-medium">{{ money(c.amount) }}</span>
          </li>
        </ul>
      </div>

      <!-- Rythme de facturation : au mois ou à la semaine -->
      <div v-if="summary.plan" class="card p-4 space-y-3 text-sm">
        <h2 class="font-semibold">Rythme de facturation</h2>
        <div class="grid gap-2 sm:grid-cols-2">
          <label v-for="c in CYCLES" :key="c.value" :class="['flex cursor-pointer gap-2 rounded-lg border p-3', chosenCycle === c.value ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200']">
            <input type="radio" :value="c.value" :checked="chosenCycle === c.value" :disabled="savingCycle" class="mt-1 accent-emerald-700" @change="changeCycle(c.value)">
            <span>
              <span class="block font-medium">{{ c.label }}</span>
              <span v-if="summary.plan.pricing === 'percent'" class="block text-gray-600">Facture des courses {{ c.value === 'weekly' ? 'de la semaine' : 'du mois' }}</span>
              <span v-else class="block text-gray-600">{{ c.value === 'weekly' ? `${money(summary.plan.price_weekly)} par semaine` : `${money(summary.plan.price_monthly)} par mois` }}{{ (c.value === 'weekly' ? summary.plan.included_orders_weekly : summary.plan.included_orders) !== null ? `, ${c.value === 'weekly' ? summary.plan.included_orders_weekly : summary.plan.included_orders} courses incluses` : '' }}</span>
              <span class="block text-xs text-gray-500">{{ c.help }}</span>
            </span>
          </label>
        </div>
        <p v-if="summary.billing_period.next_cycle" class="rounded bg-amber-50 p-2 text-amber-900">
          Passage {{ summary.billing_period.next_cycle === 'weekly' ? 'à la semaine' : 'au mois' }} le lundi {{ date(summary.billing_period.next_cycle_from) }}. D'ici là, la facturation continue {{ summary.billing_period.cycle === 'weekly' ? 'à la semaine' : 'au mois' }}.
        </p>
        <p class="text-xs text-gray-500">Seules les courses prises en charge sont facturées : ramassées par un livreur ou préparées à l'entrepôt. Une course annulée, refusée ou jamais traitée n'est pas comptée.</p>
      </div>

      <div class="card overflow-x-auto">
        <h2 class="font-semibold p-4 pb-2">Factures</h2>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Facture</th><th class="p-2">Période</th><th class="p-2 text-right">Total</th><th class="p-2 text-right">Reste</th><th class="p-2">Échéance</th><th class="p-2">Statut</th><th class="p-2" /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="i in summary.invoices" :key="i.id">
              <td class="p-2 font-mono"><RouterLink :to="`/admin/abonnement/factures/${i.id}`" class="text-blue-600">{{ i.number }}</RouterLink></td>
              <td class="p-2">{{ i.period_label || i.period }}</td>
              <td class="p-2 text-right">{{ money(i.total) }}</td>
              <td class="p-2 text-right">{{ money(i.balance) }}</td>
              <td class="p-2">{{ date(i.due_at) }}</td>
              <td class="p-2"><InvoiceStatus :invoice="i" /></td>
              <td class="p-2 text-right whitespace-nowrap">
                <template v-if="summary.wave && i.status === 'open' && i.balance > 0">
                  <span v-if="pendingFor(i)?.claimed_at" class="text-xs text-gray-500">Paiement Wave en vérification</span>
                  <button v-else class="btn-primary !py-1" @click="openWave(i)">Payer avec Wave</button>
                </template>
              </td>
            </tr>
            <tr v-if="!summary.invoices.length"><td colspan="7" class="p-4 text-center text-gray-500">Aucune facture pour l'instant.</td></tr>
          </tbody>
        </table>
      </div>
    </template>
    <p v-else class="text-gray-500">Chargement…</p>

    <Modal :open="wave.open" title="Payer avec Wave" @close="wave.open = false">
      <form v-if="!wave.payment" class="space-y-3" @submit.prevent="startWave">
        <p class="text-sm text-gray-600">Facture {{ wave.invoice?.number }} · {{ wave.invoice?.period_label }}</p>
        <div>
          <label class="label" for="w-phone">Numéro Wave qui va payer *</label>
          <input id="w-phone" v-model.trim="wave.phone" type="tel" class="input" placeholder="07 00 00 00 00" required>
        </div>
        <p v-if="wave.error" class="field-error">{{ wave.error }}</p>
        <button class="btn-primary w-full" :disabled="wave.busy">{{ wave.busy ? 'Un instant…' : 'Continuer' }}</button>
      </form>
      <div v-else class="space-y-5 text-sm">
        <div class="rounded-lg bg-slate-50 p-4 text-center">
          <p class="text-2xl font-bold">{{ money(wave.payment.amount) }} à payer</p>
          <p class="mt-1 text-gray-600">Depuis le numéro Wave {{ wave.payment.payer_phone }}</p>
          <p class="mt-1 text-xs text-gray-500">Facture {{ wave.invoice?.number }} · {{ wave.invoice?.period_label }}</p>
        </div>

        <section class="space-y-3">
          <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-sky-600 text-xs text-white">1</span>Payez avec Wave</h3>
          <!-- Téléphone : le bouton d'abord ; ordinateur : le code QR d'abord (on paie avec son téléphone) -->
          <div class="flex flex-col gap-3">
            <a v-if="wave.payment.link" :href="wave.payment.link" target="_blank" rel="noopener" class="order-1 flex w-full justify-center rounded-lg bg-sky-600 px-4 py-3 font-semibold text-white hover:bg-sky-700 md:order-2">Cliquez ici pour payer avec Wave</a>
            <div v-if="summary?.wave?.qr_url" class="order-2 flex flex-col items-center gap-2 md:order-1">
              <p class="text-gray-600">{{ wave.payment.link ? 'ou scannez ce code QR avec l\'application Wave' : 'Scannez ce code QR avec l\'application Wave' }}</p>
              <img :src="summary.wave.qr_url" alt="Code QR Wave de Jibiat" class="h-52 w-52 rounded bg-white object-contain ring-1 ring-slate-200">
            </div>
          </div>
        </section>

        <section class="space-y-2 border-t pt-4">
          <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-700 text-xs text-white">2</span>Confirmez votre paiement</h3>
          <p class="text-gray-600">Une fois le paiement fait dans Wave :</p>
          <p v-if="wave.error" class="field-error">{{ wave.error }}</p>
          <button class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800 disabled:opacity-60" :disabled="wave.busy" @click="claimWave">{{ wave.busy ? 'Envoi…' : 'Cliquez ici pour confirmer votre paiement' }}</button>
          <p class="text-xs text-gray-500">Nous vérifions la réception, puis le paiement est enregistré sur la facture.</p>
        </section>
      </div>
    </Modal>

    <p class="text-sm text-gray-500">
      Pour changer de formule, ajouter des livreurs au-delà de la limite ou activer un module, contactez-nous.
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import InvoiceStatus from '../../components/InvoiceStatus.vue'
import Modal from '../../components/Modal.vue'
import { useToastStore } from '../../stores/toasts'
import SubscriptionSummary from '../../components/SubscriptionSummary.vue'
import { date, money } from '../../utils/format'

const summary = ref(null)

const ringClass = computed(() => ({
  due: 'ring-amber-300', warning: 'ring-amber-500', restricted: 'ring-red-500',
})[summary.value?.billing.state] ?? '')

const toasts = useToastStore()
// Formule la moins chère sur les 30 derniers jours
const cheapest = computed(() => {
  const list = summary.value?.comparison ?? []
  return list.length ? list.reduce((a, b) => (b.amount < a.amount ? b : a)).plan_id : null
})

const CYCLES = [
  { value: 'monthly', label: 'Au mois', help: 'Une facture le 1er de chaque mois, à régler sous 10 jours.' },
  { value: 'weekly', label: 'À la semaine', help: 'Une facture chaque lundi pour la semaine écoulée, à régler sous 4 jours.' },
]
// Rythme choisi : le prochain s'il change, sinon celui en cours
const chosenCycle = computed(() => summary.value?.billing_period.next_cycle ?? summary.value?.billing_period.cycle)
const savingCycle = ref(false)

async function changeCycle(cycle) {
  savingCycle.value = true
  try {
    const { data } = await http.put('/subscription/billing-cycle', { billing_cycle: cycle })
    summary.value = { ...summary.value, ...data.data }
    toasts.success(data.data.billing_period.next_cycle ? `Facturation ${cycle === 'weekly' ? 'à la semaine' : 'au mois'} à partir du lundi ${date(data.data.billing_period.next_cycle_from)}.` : 'Rythme de facturation inchangé.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    await load()
  } finally {
    savingCycle.value = false
  }
}

async function load() {
  summary.value = (await http.get('/subscription')).data.data
}

const APP_STATUS = {
  included: { label: 'Incluse', cls: 'bg-emerald-100 text-emerald-800' },
  active: { label: 'Option active', cls: 'bg-emerald-100 text-emerald-800' },
  pending: { label: 'Demande envoyée', cls: 'bg-sky-100 text-sky-800' },
  available: { label: 'En option', cls: 'bg-amber-100 text-amber-800' },
  unavailable: { label: 'Non disponible', cls: 'bg-slate-100 text-slate-600' },
}

const names = (list) => list.map((p) => p.name).join(', ')

function appHelp(a) {
  const upgrade = a.included_in.length ? ` Incluse dans la formule ${names(a.included_in)}.` : ''
  switch (a.status) {
    case 'included': return 'Comprise dans votre formule, sans frais en plus.'
    case 'active': return `${money(a.price)} par mois, ajouté à votre facture${a.since ? ` depuis le ${date(a.since)}` : ''}.`
    case 'pending': return 'Jibiat valide votre demande, en général sous 48 h. Vous serez facturé à partir de l\'acceptation.'
    case 'available': return `Application e-commerçant et application livreur : ${money(a.price)} par mois, ajouté à votre facture.${upgrade}`
    default: {
      const option = a.option_in.length ? ` En option avec la formule ${a.option_in.map((p) => `${p.name} (${money(p.price)} / mois)`).join(', ')}.` : ''
      return `Pas dans votre formule.${option}${upgrade}`
    }
  }
}

const appBusy = ref(false)

async function requestApps(a) {
  if (!window.confirm(`Demander l'option ${a.label} à ${money(a.price)} par mois ?`)) return
  appBusy.value = true
  try {
    const { data } = await http.post(`/subscription/apps/${a.platform}`)
    summary.value = { ...summary.value, apps: data.data }
    toasts.success(data.message)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    appBusy.value = false
  }
}

async function stopApps(a, question) {
  if (!window.confirm(question)) return
  appBusy.value = true
  try {
    await http.delete(`/subscription/apps/${a.platform}`)
    await load()
    toasts.success('C\'est fait.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    appBusy.value = false
  }
}

onMounted(load)

// Paiement par le lien marchand Wave : numéro qui paie, puis lien ou QR code, puis « J'ai payé »
const wave = reactive({ open: false })
const pendingFor = (invoice) => summary.value?.wave_pending?.find((p) => p.invoice_id === invoice.id)

function openWave(invoice) {
  const pending = pendingFor(invoice)
  Object.assign(wave, { open: true, invoice, phone: pending?.payer_phone ?? '', payment: pending ?? null, error: '', busy: false })
}

async function startWave() {
  wave.busy = true
  wave.error = ''
  try {
    wave.payment = (await http.post(`/subscription/invoices/${wave.invoice.id}/wave`, { payer_phone: wave.phone })).data.data
  } catch (e) {
    wave.error = apiErrorMessage(e)
  } finally {
    wave.busy = false
  }
}

async function claimWave() {
  wave.busy = true
  wave.error = ''
  try {
    await http.post(`/subscription/wave-payments/${wave.payment.id}/paid`)
    wave.open = false
    toasts.success(`Merci ! Nous vérifions votre paiement de ${money(wave.payment.amount)}. Il sera enregistré sur la facture dès qu'il est confirmé.`)
    await load()
  } catch (e) {
    wave.error = apiErrorMessage(e)
  } finally {
    wave.busy = false
  }
}
</script>
