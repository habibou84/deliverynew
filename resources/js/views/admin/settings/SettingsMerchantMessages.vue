<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/reglages" class="text-sm text-blue-600">‹ Réglages</RouterLink>
      <h1 class="text-xl font-bold">💬 Messages aux e-commerçants</h1>
      <p class="text-sm text-gray-600">
        Pour chaque étape d'une course : la notification dans l'application est toujours envoyée (gratuite).
        Choisissez si l'e-commerçant reçoit aussi un message WhatsApp (ou une notification sur son téléphone quand il l'a activée).
      </p>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="!data && !error" class="card p-6 text-center text-gray-500">Chargement…</p>

    <template v-if="data">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase text-slate-600">
            <tr>
              <th class="px-3 py-2 font-bold">Étape</th>
              <th class="px-3 py-2 font-bold">📱 Application</th>
              <th class="px-3 py-2 font-bold">💬 WhatsApp</th>
              <th class="hidden px-3 py-2 font-bold md:table-cell">Message</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="e in data.events" :key="e.event">
              <td class="px-3 py-2.5">
                <b>{{ e.label }}</b>
                <span v-if="HINTS[e.event]" class="block text-xs text-gray-500">{{ HINTS[e.event] }}</span>
              </td>
              <td class="px-3 py-2.5"><span class="text-xs font-semibold text-emerald-700">✓ toujours</span></td>
              <td class="px-3 py-2.5">
                <button
                  type="button" role="switch" :aria-checked="e.whatsapp" :aria-label="`WhatsApp : ${e.label}`" :disabled="busy"
                  :class="['inline-flex h-6 w-11 items-center rounded-full p-0.5 transition', e.whatsapp ? 'bg-emerald-600' : 'bg-slate-300']"
                  @click="toggle(e)"
                >
                  <span :class="['block h-5 w-5 rounded-full bg-white shadow transition', e.whatsapp ? 'translate-x-5' : '']" />
                </button>
                <span class="ml-1 text-xs text-gray-500">{{ e.whatsapp ? 'oui' : 'non' }}</span>
              </td>
              <td class="hidden px-3 py-2.5 text-xs text-gray-600 md:table-cell">{{ e.example ? `« ${e.example} »` : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="text-sm text-gray-500">Vos choix s'appliquent à tous vos e-commerçants : ils ne peuvent pas les changer.</p>
      <div v-if="live?.enabled" class="card space-y-2 p-4 text-sm">
        <label class="flex items-start gap-2">
          <input type="checkbox" class="mt-1" :checked="live.live_offered" :disabled="busy" @change="saveLive($event.target.checked)">
          <span>
            <b>Offrir l'outil live à mes e-commerçants</b>
            <span class="block text-xs text-gray-500">
              Sinon, ils paient eux-mêmes leurs lives à Jibiat selon les commandes du live (gratuit jusqu'à {{ live.tiers?.[0]?.to }} commandes, puis à partir de {{ live.tiers?.[1]?.price }} F), ou {{ live.price_month }} F pour 30 jours.
              Offert : c'est votre entreprise qui paie, sur votre facture Jibiat. Plus de lives, plus de livraisons.
            </span>
          </span>
        </label>
      </div>
      <p class="text-xs text-gray-500">
        Les courses envoyées par Jibiat Marchand ne reçoivent rien d'ici : Jibiat Marchand prévient lui-même la boutique.
        Numéro WhatsApp, modèles et envois : <RouterLink to="/admin/whatsapp" class="text-blue-600">page WhatsApp</RouterLink>.
      </p>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'

/** Réglages › Messages aux e-commerçants : WhatsApp oui ou non par étape, choix laissés ou non aux e-commerçants */
const HINTS = {
  'order.received': 'À l\'enregistrement, quand la validation est automatique',
  'order.confirmed': 'Quand l\'équipe valide la course à la main',
}
const toasts = useToastStore()
const data = ref(null)
const error = ref('')
const busy = ref(false)

const live = ref(null)
async function saveLive(value) {
  busy.value = true
  try {
    live.value.live_offered = (await http.put('/company/live-offer', { live_offered: value })).data.data.live_offered
    toasts.success('Enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function load() {
  http.get('/company/live-offer').then(({ data: r }) => { live.value = r.data }).catch(() => {})
  try {
    data.value = (await http.get('/company/merchant-messages')).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save(payload) {
  busy.value = true
  try {
    data.value = (await http.put('/company/merchant-messages', payload)).data.data
    toasts.success('Enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const toggle = (e) => save({ events: { [e.event]: !e.whatsapp } })

onMounted(load)
</script>
