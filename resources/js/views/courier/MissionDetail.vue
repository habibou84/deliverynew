<template>
  <div v-if="mission" class="space-y-4 pb-24">
    <div class="flex items-center gap-2">
      <RouterLink to="/livreur" class="btn-secondary">←</RouterLink>
      <span class="font-mono text-sm">{{ order.tracking_code }}</span>
      <StatusBadge class="ml-auto" :status="order.status" :label="order.status_label" />
    </div>

    <div class="card p-4 space-y-2">
      <p class="text-xs uppercase text-gray-500">{{ mission.type_label }}</p>
      <template v-if="mission.type === 'pickup'">
        <p class="font-semibold text-lg">{{ order.merchant?.business_name }}</p>
        <p>{{ order.pickup.contact_name }}</p>
        <p>{{ order.pickup.zone_name }} · {{ order.pickup.address }}</p>
        <p v-if="order.pickup.landmark" class="text-gray-600">Repère : {{ order.pickup.landmark }}</p>
        <ContactButtons :phone="order.pickup.phone" :maps="mapsLink(order.pickup.lat, order.pickup.lng, order.pickup.address)" />
      </template>
      <template v-else>
        <p class="font-semibold text-lg">{{ order.recipient.name || 'Destinataire' }}</p>
        <p>{{ order.delivery.zone_name }} · {{ order.delivery.address }}</p>
        <p v-if="order.delivery.landmark" class="text-gray-600">Repère : {{ order.delivery.landmark }}</p>
        <p v-if="order.delivery.scheduled_date">Prévue le {{ date(order.delivery.scheduled_date) }} {{ order.delivery.time_slot ? `(${order.delivery.time_slot}h)` : '' }}</p>
        <ContactButtons :phone="order.recipient.phone" :phone2="order.recipient.phone2" :maps="mapsLink(order.delivery.lat, order.delivery.lng, order.delivery.address)" />
      </template>
    </div>

    <div class="card p-4 text-sm space-y-1">
      <p v-if="mission.type !== 'pickup'" class="text-lg">À encaisser : <strong>{{ money(order.amounts.cod_amount) }}</strong></p>
      <p>Colis : {{ order.package.description || '—' }}<span v-if="order.package.is_fragile" class="text-red-600 font-medium"> · FRAGILE</span><span v-if="order.package.is_express"> · Express</span></p>
      <p v-if="order.package.merchant_note" class="bg-amber-50 rounded p-2">📝 {{ order.package.merchant_note }}</p>
      <p v-if="order.attempts_count">Tentatives : {{ order.attempts_count }} / {{ order.max_attempts }}</p>
    </div>

    <div v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</div>

    <!-- Actions principales, en bas de l'écran -->
    <div class="fixed bottom-0 inset-x-0 bg-white border-t p-3 space-y-2 z-40">
      <template v-if="mission.status === 'assigned'">
        <div class="flex gap-2">
          <button class="btn-secondary flex-1" @click="refuse">Refuser</button>
          <button class="btn-success flex-1" @click="accept">Accepter</button>
        </div>
      </template>
      <template v-else-if="isActive">
        <button v-if="primary" :class="[primary.class, 'w-full py-3 text-base']" :disabled="busy" @click="primary.action()">{{ primary.label }}</button>
        <div class="flex gap-2">
          <button v-if="canReportIncident" class="btn-danger flex-1" @click="openIncident">Signaler un problème</button>
          <button class="btn-secondary flex-1" @click="noteOpen = true">Note</button>
          <label class="btn-secondary flex-1 cursor-pointer">
            📷 Photo
            <input type="file" accept="image/*" capture="environment" class="hidden" @change="uploadPhoto">
          </label>
        </div>
      </template>
      <p v-else class="text-center text-sm text-gray-500">Mission terminée.</p>
    </div>

    <Modal :open="statusModal.open" :title="statusModal.title" @close="statusModal.open = false">
      <StatusChangeForm
        v-if="statusModal.open"
        :order="order"
        :choices="statusModal.choices"
        :ask-code="!!auth.user?.company?.require_delivery_code"
        :position="position"
        @done="done"
      />
    </Modal>

    <Modal :open="noteOpen" title="Ajouter une note" @close="noteOpen = false">
      <form class="space-y-3" @submit.prevent="sendNote">
        <textarea v-model="noteText" rows="3" class="input" placeholder="Ex. : destinataire injoignable, rappeler après 17h" required />
        <p class="text-xs text-gray-500">La note est envoyée immédiatement à l'administrateur et au marchand.</p>
        <button class="btn-primary w-full">Envoyer</button>
      </form>
    </Modal>
  </div>
  <p v-else class="text-gray-500">Chargement…</p>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatusBadge from '../../components/StatusBadge.vue'
import Modal from '../../components/Modal.vue'
import StatusChangeForm from '../../components/StatusChangeForm.vue'
import { useToastStore } from '../../stores/toasts'
import { useAuthStore } from '../../stores/auth'
import { currentPosition } from '../../composables/useGeolocation'
import { date, mapsLink, money, telLink, whatsappLink } from '../../utils/format'

// Boutons d'appel, WhatsApp et itinéraire
const ContactButtons = defineComponent({
  props: { phone: String, phone2: String, maps: String },
  setup(props) {
    const link = (href, label, cls = 'btn-secondary flex-1') => href && h('a', { href, class: cls, target: href.startsWith('http') ? '_blank' : undefined }, label)
    return () => h('div', { class: 'flex flex-wrap gap-2 pt-2' }, [
      link(telLink(props.phone), '📞 Appeler'),
      link(telLink(props.phone2), '📞 2e numéro'),
      link(whatsappLink(props.phone), '💬 WhatsApp'),
      link(props.maps, '🗺️ Itinéraire'),
    ].filter(Boolean))
  },
})

const route = useRoute()
const router = useRouter()
const toasts = useToastStore()
const auth = useAuthStore()

const mission = ref(null)
const order = computed(() => mission.value?.order)
const error = ref('')
const busy = ref(false)
const position = ref(null)
const statusModal = reactive({ open: false, title: '', choices: [] })
const noteOpen = ref(false)
const noteText = ref('')

const isActive = computed(() => ['accepted', 'in_progress'].includes(mission.value?.status))
const canReportIncident = computed(() => ['pickup_assigned', 'pickup_in_progress', 'out_for_delivery'].includes(order.value?.status))

// Prochaine étape selon le type de mission et l'avancement
const primary = computed(() => {
  const s = order.value.status
  const t = mission.value.type
  if (t === 'pickup' && s === 'pickup_assigned') return { label: 'Je pars au ramassage', class: 'btn-primary', action: () => move('pickup_in_progress') }
  if (t === 'pickup' && ['pickup_assigned', 'pickup_in_progress'].includes(s)) return { label: '✅ Colis récupéré', class: 'btn-success', action: () => move('picked_up') }
  if (t === 'delivery' && ['delivery_assigned', 'picked_up', 'at_hub', 'delivery_failed', 'rescheduled'].includes(s)) return { label: '🚚 Je pars livrer', class: 'btn-primary', action: () => move('out_for_delivery') }
  if (t === 'delivery' && s === 'out_for_delivery') return { label: '✅ Colis livré', class: 'btn-success', action: () => openStatus('Confirmer la livraison', ['delivered']) }
  if (t === 'return' && s === 'return_assigned') return { label: 'Je pars rendre le colis', class: 'btn-primary', action: () => move('returning') }
  if (t === 'return' && ['return_assigned', 'returning'].includes(s)) return { label: '✅ Colis rendu au marchand', class: 'btn-success', action: () => move('returned') }
  return null
})

async function load() {
  const { data } = await http.get('/courier/missions')
  mission.value = data.data.find((m) => m.id === Number(route.params.id)) ?? null
  // Mission terminée (ou retirée) : retour à la liste
  if (!mission.value) router.replace('/livreur')
}

async function move(status) {
  busy.value = true
  error.value = ''
  try {
    const pos = await currentPosition()
    const { data } = await http.post(`/orders/${order.value.id}/status`, { status, ...(pos || {}) })
    afterMove(data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

function afterMove(updated) {
  toasts.success(`${updated.tracking_code} : ${updated.status_label}`)
  load()
}

async function openStatus(title, choices) {
  position.value = await currentPosition()
  statusModal.title = title
  statusModal.choices = choices
  statusModal.open = true
}

function openIncident() {
  const choices = mission.value.type === 'pickup' ? ['confirmed'] : ['delivery_failed', 'rescheduled']
  openStatus('Signaler un problème', choices)
}

function done(updated) {
  statusModal.open = false
  afterMove(updated)
}

async function accept() {
  await http.post(`/courier/assignments/${mission.value.id}/accept`)
  load()
}

async function refuse() {
  const reason = window.prompt('Pourquoi refusez-vous cette mission ?')
  if (!reason) return
  await http.post(`/courier/assignments/${mission.value.id}/refuse`, { reason })
  toasts.push('Mission refusée.')
  router.replace('/livreur')
}

async function sendNote() {
  const pos = await currentPosition()
  await http.post(`/orders/${order.value.id}/notes`, { note: noteText.value, ...(pos || {}) })
  noteText.value = ''
  noteOpen.value = false
  toasts.success('Note envoyée.')
}

async function uploadPhoto(e) {
  const file = e.target.files[0]
  if (!file) return
  const form = new FormData()
  form.append('file', file)
  form.append('type', mission.value.type === 'pickup' ? 'photo_pickup' : 'photo_delivery')
  try {
    await http.post(`/orders/${order.value.id}/attachments`, form)
    toasts.success('Photo envoyée.')
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  }
  e.target.value = ''
}

onMounted(load)
</script>
