<template>
  <div v-if="order" class="space-y-4">
    <!-- Statut -->
    <section class="m-card p-4 space-y-4">
      <div class="flex items-center justify-between gap-2">
        <button class="font-mono text-sm text-slate-500" @click="copy(order.tracking_code)">{{ order.tracking_code }} ⧉</button>
        <StatusBadge :status="order.status" :label="order.status_label" />
      </div>
      <OrderProgress :status="order.status" :incident="order.last_incident?.label" :rescheduled-to="order.delivery.scheduled_date" :shipping="order.is_shipping" />

      <!-- Actions sur incident -->
      <div v-if="order.status === 'delivery_failed'" class="grid grid-cols-2 gap-2">
        <button class="m-btn-primary py-3" @click="sheet = 'reschedule'">📅 Reporter</button>
        <button class="m-btn-secondary py-3" @click="openEdit">✏️ Corriger l'adresse</button>
        <a :href="telLink(order.recipient.phone)" class="m-btn-secondary py-3">📞 Appeler le client</a>
        <button v-if="!order.return_requested" class="m-btn-secondary py-3" @click="sheet = 'return'">↩️ Retour</button>
      </div>
    </section>

    <!-- Code de livraison et suivi -->
    <section v-if="!isFinal" class="m-card p-4 space-y-3">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-slate-500">Code de livraison du client</p>
          <p class="font-mono text-2xl font-bold tracking-[0.3em]">{{ order.delivery_code }}</p>
        </div>
        <button class="rounded-xl bg-slate-100 px-3 py-2 text-sm font-medium" @click="copy(order.delivery_code)">Copier</button>
      </div>
      <a :href="whatsappShare" target="_blank" class="m-btn-success py-3">💬 Envoyer le suivi au client</a>
    </section>

    <!-- Livreur en route -->
    <section v-if="order.status === 'out_for_delivery' && order.delivery_courier" class="m-card p-4 flex items-center justify-between">
      <div>
        <p class="text-sm text-slate-500">Livreur en route</p>
        <p class="font-semibold">{{ order.delivery_courier.name }}</p>
      </div>
      <a :href="telLink(order.delivery_courier.phone)" class="rounded-full h-12 w-12 grid place-items-center text-white text-xl" :style="{ backgroundColor: 'var(--app-color)' }" aria-label="Appeler le livreur">📞</a>
    </section>

    <!-- Client -->
    <section class="m-card p-4 space-y-1">
      <div class="flex justify-between">
        <p class="text-sm text-slate-500">Client</p>
        <button v-if="canEdit" class="text-sm font-medium text-[var(--app-color)]" @click="openEdit">Modifier</button>
      </div>
      <p class="font-semibold text-lg">{{ order.recipient.name || '—' }}</p>
      <div class="flex gap-3 text-sm">
        <a :href="telLink(order.recipient.phone)" class="text-[var(--app-color)] font-medium">📞 {{ order.recipient.phone }}</a>
        <a v-if="order.recipient.phone2" :href="telLink(order.recipient.phone2)" class="text-[var(--app-color)]">{{ order.recipient.phone2 }}</a>
      </div>
      <p class="pt-1">📍 {{ order.delivery.zone_name }}<span v-if="order.delivery.address"> · {{ order.delivery.address }}</span></p>
      <p v-if="order.delivery.landmark" class="text-sm text-slate-500">Repère : {{ order.delivery.landmark }}</p>
      <p v-if="order.delivery.scheduled_date" class="text-sm text-slate-500">Prévue le {{ date(order.delivery.scheduled_date) }}</p>
    </section>

    <!-- Montants -->
    <section class="m-card p-4 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Articles</span><span>{{ money(order.amounts.items_amount) }}</span></div>
      <div class="flex justify-between">
        <span class="text-slate-500">Livraison ({{ order.amounts.fee_payer === 'recipient' ? 'client' : 'vous' }})</span><span>{{ money(order.amounts.total_fees) }}</span>
      </div>
      <div v-if="order.is_shipping" class="flex justify-between">
        <span class="text-slate-500">🚌 Expédition (vous)</span>
        <span>{{ order.shipping.fee !== null ? money(order.shipping.fee) : (order.shipping.fee_estimate ? '≈ ' + money(order.shipping.fee_estimate) : 'au réel') }}</span>
      </div>
      <div class="flex justify-between font-semibold text-base border-t pt-2"><span>À encaisser</span><span>{{ money(order.amounts.cod_amount) }}</span></div>
      <div v-if="order.amounts.collected_amount !== null" class="flex justify-between text-emerald-700 font-medium"><span>Encaissé</span><span>{{ money(order.amounts.collected_amount) }}</span></div>
    </section>

    <!-- Expédition -->
    <section v-if="order.is_shipping && order.shipping.carrier" class="m-card p-4 space-y-1 text-sm">
      <h2 class="font-semibold text-base">🚌 Colis expédié</h2>
      <p>Par <strong>{{ order.shipping.carrier }}</strong><span v-if="order.shipping.reference"> · ticket {{ order.shipping.reference }}</span></p>
      <p class="text-slate-500">Frais d'expédition de {{ money(order.shipping.fee) }} déduits de votre point.</p>
    </section>

    <!-- Autres actions -->
    <section v-if="!isFinal" class="space-y-2">
      <button class="m-btn-secondary" @click="sheet = 'note'">💬 Écrire au livreur / à l'agence</button>
      <button v-if="!['delivery_failed'].includes(order.status) && !beforePickup && !order.return_requested" class="m-btn-secondary" @click="sheet = 'return'">↩️ Demander le retour du colis</button>
      <button v-if="beforePickup" class="m-btn-secondary text-red-600" @click="sheet = 'cancel'">Annuler la course</button>
    </section>

    <!-- Historique -->
    <section class="m-card p-4">
      <h2 class="font-semibold mb-3">Historique</h2>
      <OrderTimeline :events="[...order.events].reverse()" />
    </section>

    <!-- Feuilles d'action -->
    <BottomSheet :open="sheet === 'reschedule'" title="Reporter la livraison" @close="sheet = null">
      <div class="space-y-3">
        <ChoiceChips v-model="reschedule.choice" :options="dateOptions" :columns="2" label="Nouvelle date" />
        <input v-if="reschedule.choice === 'other'" v-model="reschedule.date" type="date" :min="today()" class="m-input" aria-label="Date">
        <textarea v-model="reschedule.note" rows="2" class="m-input" placeholder="Message (facultatif) : ex. le client sera là après 17h" />
        <button class="m-btn-primary" :disabled="busy || !rescheduleDate" @click="doReschedule">Confirmer le report</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'return'" title="Demander le retour" @close="sheet = null">
      <div class="space-y-3">
        <p class="text-slate-600">Le colis vous sera rapporté. Des frais de retour peuvent s'appliquer.</p>
        <textarea v-model="noteText" rows="2" class="m-input" placeholder="Raison (facultatif)" />
        <button class="m-btn-primary" :disabled="busy" @click="doReturn">Demander le retour</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'cancel'" title="Annuler la course ?" @close="sheet = null">
      <div class="space-y-3">
        <textarea v-model="noteText" rows="2" class="m-input" placeholder="Raison (facultatif)" />
        <button class="m-btn-danger" :disabled="busy" @click="doCancel">Oui, annuler la course</button>
        <button class="m-btn-secondary" @click="sheet = null">Non, garder</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'note'" title="Envoyer un message" @close="sheet = null">
      <div class="space-y-3">
        <textarea v-model="noteText" rows="3" class="m-input" placeholder="Ex. : le client préfère être livré au bureau" />
        <button class="m-btn-primary" :disabled="busy || !noteText.trim()" @click="doNote">Envoyer</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'edit'" title="Modifier la livraison" @close="sheet = null">
      <div class="space-y-3">
        <div><label class="m-label">Téléphone</label><input v-model="edit.recipient_phone" class="m-input" inputmode="tel"></div>
        <div><label class="m-label">Autre numéro</label><input v-model="edit.recipient_phone2" class="m-input" inputmode="tel"></div>
        <div>
          <label class="m-label">Commune / quartier</label>
          <select v-model="edit.delivery_zone_id" class="m-input">
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
        </div>
        <div><label class="m-label">Adresse</label><input v-model="edit.delivery_address" class="m-input"></div>
        <div><label class="m-label">Repère</label><input v-model="edit.delivery_landmark" class="m-input"></div>
        <button class="m-btn-primary" :disabled="busy" @click="doEdit">Enregistrer</button>
      </div>
    </BottomSheet>
  </div>
  <p v-else class="text-center text-slate-400 py-10">{{ error || 'Chargement…' }}</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatusBadge from '../../components/StatusBadge.vue'
import OrderTimeline from '../../components/OrderTimeline.vue'
import OrderProgress from '../../components/mobile/OrderProgress.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { orderChanges, lastOrderChange } from '../../composables/useRealtime'
import { date, money, telLink, today } from '../../utils/format'
import { BEFORE_PICKUP, FINAL } from '../../utils/workflow'

const route = useRoute()
const auth = useAuthStore()
const toasts = useToastStore()

const order = ref(null)
const error = ref('')
const sheet = ref(null)
const busy = ref(false)
const noteText = ref('')
const zones = ref([])
const edit = reactive({})
const reschedule = reactive({ choice: 'tomorrow', date: null, note: '' })

const isFinal = computed(() => FINAL.includes(order.value?.status))
const beforePickup = computed(() => BEFORE_PICKUP.includes(order.value?.status))
const canEdit = computed(() => !isFinal.value && order.value?.status !== 'out_for_delivery')

const dayOffset = (n) => {
  const d = new Date(Date.now() + n * 86400000)
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}
const dateOptions = [
  { value: 'today', label: "Aujourd'hui" },
  { value: 'tomorrow', label: 'Demain' },
  { value: 'after', label: 'Après-demain' },
  { value: 'other', label: 'Autre date' },
]
const rescheduleDate = computed(() => ({ today: dayOffset(0), tomorrow: dayOffset(1), after: dayOffset(2), other: reschedule.date }[reschedule.choice]))

const whatsappShare = computed(() => {
  const o = order.value
  const text = `Bonjour, votre commande ${auth.user?.merchant?.business_name || ''} sera livrée bientôt.\nSuivi : ${window.location.origin}/suivi/${o.tracking_code}\nCode de livraison à donner au livreur : ${o.delivery_code}`
  return `https://wa.me/${o.recipient.phone.replace(/\D/g, '')}?text=${encodeURIComponent(text)}`
})

async function load() {
  try {
    order.value = (await http.get(`/orders/${route.params.id}`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function run(action, success) {
  busy.value = true
  try {
    const { data } = await action()
    if (data?.data?.id) order.value = data.data
    else await load()
    sheet.value = null
    noteText.value = ''
    toasts.success(success)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const doReschedule = () => run(() => http.post(`/orders/${order.value.id}/status`, {
  status: 'rescheduled', rescheduled_to: rescheduleDate.value, note: reschedule.note || undefined,
}), 'Livraison reportée.')
const doReturn = () => run(() => http.post(`/orders/${order.value.id}/return-request`, { note: noteText.value || undefined }), 'Retour demandé.')
const doCancel = () => run(() => http.post(`/orders/${order.value.id}/status`, { status: 'cancelled', cancel_reason: noteText.value || undefined }), 'Course annulée.')
const doNote = () => run(() => http.post(`/orders/${order.value.id}/notes`, { note: noteText.value }), 'Message envoyé.')

async function openEdit() {
  if (!zones.value.length) zones.value = (await http.get('/zones')).data.data
  Object.assign(edit, {
    recipient_phone: order.value.recipient.phone,
    recipient_phone2: order.value.recipient.phone2 || '',
    delivery_zone_id: order.value.delivery.zone_id,
    delivery_address: order.value.delivery.address || '',
    delivery_landmark: order.value.delivery.landmark || '',
  })
  sheet.value = 'edit'
}

const doEdit = () => run(
  () => http.patch(`/orders/${order.value.id}`, Object.fromEntries(Object.entries(edit).filter(([, v]) => v !== ''))),
  'Livraison modifiée.',
)

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    toasts.push(text)
  }
}

watch(orderChanges, () => {
  if (lastOrderChange.value?.order?.id === order.value?.id) load()
})
onMounted(load)
</script>
