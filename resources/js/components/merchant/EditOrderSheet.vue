<template>
  <BottomSheet :open="open" title="Modifier la course" @close="$emit('close')">
    <form v-if="order" class="space-y-3" @submit.prevent="save">
      <div>
        <label class="m-label" for="me-phone">Téléphone du client</label>
        <input id="me-phone" v-model="form.recipient_phone" :class="['m-input', changed('recipient_phone') && hl]" inputmode="tel" required>
      </div>
      <div>
        <label class="m-label" for="me-phone2">Autre numéro</label>
        <input id="me-phone2" v-model="form.recipient_phone2" :class="['m-input', changed('recipient_phone2') && hl]" inputmode="tel">
      </div>
      <div>
        <label class="m-label" for="me-zone">Commune / quartier</label>
        <select id="me-zone" v-model="form.delivery_zone_id" :class="['m-input', changed('delivery_zone_id') && hl]">
          <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
        </select>
      </div>
      <div>
        <label class="m-label" for="me-address">Adresse</label>
        <input id="me-address" v-model="form.delivery_address" :class="['m-input', changed('delivery_address') && hl]">
      </div>
      <div>
        <label class="m-label" for="me-landmark">Repère</label>
        <input id="me-landmark" v-model="form.delivery_landmark" :class="['m-input', changed('delivery_landmark') && hl]">
      </div>
      <div>
        <label class="m-label" for="me-date">Jour de livraison</label>
        <input id="me-date" v-model="form.delivery_scheduled_date" type="date" :min="today" :class="['m-input', changed('delivery_scheduled_date') && hl]">
      </div>
      <!-- Montant : seulement avant le ramassage -->
      <div v-if="order.edit_scope === 'all'">
        <label class="m-label" for="me-amount">Montant des articles (F)</label>
        <input id="me-amount" v-model.number="form.items_amount" type="number" min="0" inputmode="numeric" :class="['m-input', changed('items_amount') && hl]">
      </div>
      <p v-else class="text-[12.5px] text-slate-500">🔒 Colis déjà récupéré : seule la livraison se modifie.</p>

      <div v-if="preview && (preview.fees.from !== preview.fees.to || preview.cod.from !== preview.cod.to)" class="rounded-2xl bg-[#EEF4FF] p-3 text-[13px] text-[#1E3A8A]">
        💰 <template v-if="preview.fees.from !== preview.fees.to">Livraison : {{ money(preview.fees.from) }} → <b>{{ money(preview.fees.to) }}</b><br></template>
        Le livreur encaissera <b>{{ money(preview.cod.to) }}</b>.
      </div>
      <p v-if="preview?.needs_approval" class="rounded-2xl bg-[#FFF7EB] p-3 text-[13px] text-[#7A4A00]">⏳ Votre agence valide cette modification avant qu'elle s'applique. Vous serez prévenu.</p>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="m-btn-primary" :disabled="saving || !dirty">{{ saving ? 'Un instant…' : (preview?.needs_approval ? 'Envoyer la modification' : 'Enregistrer') }}</button>
    </form>
  </BottomSheet>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../mobile/BottomSheet.vue'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

/**
 * Application e-commerçant : modifier la course (livraison, jour, montant avant le ramassage), nouveau prix affiché,
 * validation par l'agence si son réglage le demande.
 */
const props = defineProps({ open: Boolean, order: { type: Object, default: null } })
const emit = defineEmits(['close', 'saved'])
const toasts = useToastStore()
const zones = ref([])
const form = reactive({})
const initial = ref({})
const preview = ref(null)
const error = ref('')
const saving = ref(false)
const today = new Date().toISOString().slice(0, 10)
const hl = 'ring-2 ring-amber-400 bg-amber-50'

const norm = (v) => (v === '' || v === undefined ? null : v)
const changed = (key) => norm(form[key]) !== norm(initial.value[key])
const payload = computed(() => Object.fromEntries(Object.keys(initial.value).filter(changed).map((k) => [k, norm(form[k])])))
const dirty = computed(() => Object.keys(payload.value).length > 0)

watch(() => props.open, async (open) => {
  if (!open || !props.order) return
  const o = props.order
  error.value = ''
  preview.value = null
  initial.value = {
    recipient_phone: o.recipient.phone ?? '',
    recipient_phone2: o.recipient.phone2 ?? '',
    delivery_zone_id: o.delivery.zone_id,
    delivery_address: o.delivery.address ?? '',
    delivery_landmark: o.delivery.landmark ?? '',
    delivery_scheduled_date: o.delivery.scheduled_date ?? '',
    ...(o.edit_scope === 'all' ? { items_amount: o.amounts.items_amount } : {}),
  }
  Object.keys(form).forEach((k) => delete form[k])
  Object.assign(form, initial.value)
  if (!zones.value.length) zones.value = (await http.get('/zones')).data.data
})

let timer = null
watch(payload, (data) => {
  clearTimeout(timer)
  if (!props.open || !Object.keys(data).length) {
    preview.value = null
    return
  }
  timer = setTimeout(async () => {
    try {
      preview.value = (await http.post(`/orders/${props.order.id}/edit-preview`, data)).data.data
      error.value = ''
    } catch (e) {
      preview.value = null
      error.value = apiErrorMessage(e)
    }
  }, 400)
}, { deep: true })

async function save() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await http.patch(`/orders/${props.order.id}`, payload.value)
    toasts.success(data.data.pending_change ? 'Modification envoyée : votre agence la valide.' : 'Course modifiée.')
    emit('saved', data.data)
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
