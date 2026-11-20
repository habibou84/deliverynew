<template>
  <!-- Demandes d'ouverture de compte (annuaire, Jibiat Marchand) : clients potentiels à accepter ou refuser -->
  <section v-if="requests.length" class="card p-4 space-y-3 border-amber-300 bg-amber-50/40">
    <div>
      <h2 class="font-semibold">🤝 {{ requests.length }} {{ requests.length > 1 ? 'nouveaux clients potentiels' : 'nouveau client potentiel' }}</h2>
      <p class="text-sm text-slate-600">Ces e-commerçants vous ont trouvé dans l'annuaire ou dans Jibiat Marchand et demandent à ouvrir un compte. Accepté : leur compte est créé (avec le mot de passe choisi, ou connexion par WhatsApp) ; venus de Jibiat Marchand, leurs colis vous arrivent aussitôt.</p>
    </div>
    <ul class="divide-y rounded-lg border bg-white">
      <li v-for="r in requests" :key="r.id" class="p-3 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 text-sm">
          <p class="font-semibold">{{ r.business_name }} <span v-if="r.from_app" class="ml-1 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">via {{ r.from_app }}</span></p>
          <p class="text-slate-600">{{ r.contact_name }} · <a :href="`tel:${r.phone}`" class="underline">{{ r.phone }}</a><span v-if="r.email"> · {{ r.email }}</span></p>
          <p v-if="r.pickup_zone || r.pickup_address" class="text-slate-600">📍 {{ [r.pickup_zone, r.pickup_address].filter(Boolean).join(', ') }}</p>
          <p v-if="r.waiting_orders" class="mt-1 inline-block rounded-md bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">📦 {{ r.waiting_orders }} colis {{ r.waiting_orders > 1 ? 'attendent' : 'attend' }} déjà<template v-if="r.decide_before"> · répondez avant le {{ dateTime(r.decide_before) }}, sinon {{ r.waiting_orders > 1 ? 'ils partent' : 'il part' }} chez une autre entreprise</template></p>
          <p v-if="r.activity?.orders_30d" class="text-slate-600">📈 Environ {{ r.activity.orders_30d }} commandes sur 30 jours dans sa boutique</p>
          <p v-if="r.store_url" class="text-slate-600">🛍️ <a :href="r.store_url" target="_blank" rel="noopener" class="underline">Voir sa boutique</a></p>
          <p v-if="r.message" class="text-slate-700 italic">« {{ r.message }} »</p>
          <p class="text-xs text-slate-600">Reçue le {{ dateTime(r.created_at) }}</p>
        </div>
        <div class="flex gap-2">
          <button class="btn-secondary" :disabled="busy === r.id" @click="decide(r, 'decline')">Refuser</button>
          <button class="btn-primary" :disabled="busy === r.id" @click="decide(r, 'accept')">Accepter</button>
        </div>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'
import { promptDialog } from '../composables/useDialog'

const emit = defineEmits(['accepted'])
const toasts = useToastStore()
const requests = ref([])
const busy = ref(null)

async function load() {
  try {
    const { data } = await http.get('/connect-requests')
    requests.value = data.data
  } catch {
    requests.value = []
  }
}

async function decide(request, action) {
  let reason = null
  if (action === 'decline') {
    // Le motif est transmis à l'e-commerçant (Jibiat Marchand) : facultatif
    reason = await promptDialog(`Refuser la demande de ${request.business_name} ? Motif pour l'e-commerçant (facultatif) :`, '', { okLabel: 'Refuser', danger: true })
    if (reason === null) return
  }
  busy.value = request.id
  try {
    await http.post(`/connect-requests/${request.id}/${action}`, action === 'decline' ? { reason: reason || null } : {})
    toasts.success(action === 'accept' ? `${request.business_name} est maintenant votre client.` : 'Demande refusée.')
    requests.value = requests.value.filter((r) => r.id !== request.id)
    if (action === 'accept') emit('accepted')
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'Action impossible.'))
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>
