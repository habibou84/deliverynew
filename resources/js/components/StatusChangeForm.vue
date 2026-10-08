<template>
  <form class="space-y-3" @submit.prevent="submit">
    <div v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</div>

    <div v-if="choices.length > 1">
      <label class="label" for="to">Nouveau statut</label>
      <select id="to" v-model="form.status" class="input" required>
        <option v-for="s in choices" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option>
      </select>
    </div>

    <div v-if="showReason">
      <label class="label" for="reason">Motif {{ reasonRequired ? '*' : '' }}</label>
      <select id="reason" v-model="form.incident_reason_id" class="input" :required="reasonRequired">
        <option :value="null">{{ reasonRequired ? 'Choisir…' : 'Aucun' }}</option>
        <option v-for="r in reasons" :key="r.id" :value="r.id">{{ r.label }}</option>
      </select>
    </div>

    <div v-if="showDate">
      <label class="label" for="date">Nouvelle date de livraison *</label>
      <input id="date" v-model="form.rescheduled_to" type="date" :min="today()" class="input" required>
    </div>

    <template v-if="form.status === 'delivered'">
      <div>
        <label class="label" for="amount">Montant encaissé (F)</label>
        <input id="amount" v-model.number="form.collected_amount" type="number" min="0" class="input">
      </div>
      <div v-if="askCode">
        <label class="label" for="code">Code de livraison donné par le client</label>
        <input id="code" v-model="form.delivery_code" class="input" inputmode="numeric" maxlength="4" autocomplete="one-time-code">
      </div>
    </template>

    <div>
      <label class="label" for="note">{{ form.status === 'cancelled' ? 'Raison' : 'Note' }}</label>
      <textarea id="note" v-model="form.note" rows="2" class="input" />
    </div>

    <button type="submit" class="btn-primary w-full" :disabled="saving">{{ saving ? 'Envoi…' : 'Valider' }}</button>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { STATUS_LABELS, today } from '../utils/format'
import { needsDate, needsReason } from '../utils/workflow'

const props = defineProps({
  order: { type: Object, required: true },
  choices: { type: Array, required: true },
  askCode: { type: Boolean, default: false },
  position: { type: Object, default: null },
})
const emit = defineEmits(['done'])

const form = reactive({
  status: props.choices[0],
  incident_reason_id: null,
  rescheduled_to: null,
  collected_amount: props.order.amounts.cod_amount,
  delivery_code: '',
  note: '',
})
const allReasons = ref([])
const error = ref('')
const saving = ref(false)

const stage = computed(() => (['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress'].includes(props.order.status) ? 'pickup' : 'delivery'))
const reasons = computed(() => allReasons.value.filter((r) => r.applies_to === 'both' || r.applies_to === stage.value))
const reasonRequired = computed(() => needsReason(props.order.status, form.status))
const showReason = computed(() => reasonRequired.value || ['rescheduled', 'cancelled'].includes(form.status))
const selectedReason = computed(() => allReasons.value.find((r) => r.id === form.incident_reason_id))
const showDate = computed(() => needsDate(form.status) || selectedReason.value?.requires_date)

watch(() => props.choices, (c) => { if (!c.includes(form.status)) form.status = c[0] })

onMounted(async () => {
  const { data } = await http.get('/incident-reasons')
  allReasons.value = data.data
})

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const payload = { status: form.status, note: form.note || undefined }
    if (showReason.value && form.incident_reason_id) payload.incident_reason_id = form.incident_reason_id
    if (showDate.value) payload.rescheduled_to = form.rescheduled_to
    if (form.status === 'delivered') {
      payload.collected_amount = form.collected_amount
      if (form.delivery_code) payload.delivery_code = form.delivery_code
    }
    if (form.status === 'cancelled') payload.cancel_reason = form.note || undefined
    if (props.position) Object.assign(payload, props.position)
    const { data } = await http.post(`/orders/${props.order.id}/status`, payload)
    emit('done', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
