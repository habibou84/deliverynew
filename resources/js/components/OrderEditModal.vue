<template>
  <Modal :open="open" :title="`Modifier la course ${order?.tracking_code ?? ''}`" @close="$emit('close')">
    <form v-if="order" class="space-y-3" @submit.prevent="save">
      <p class="text-xs text-gray-500">{{ order.status_label }}<template v-if="courierName"> · {{ courierName }}</template></p>

      <!-- Livraison : modifiable tant que le livreur n'est pas en route vers le client -->
      <fieldset class="rounded-lg border p-3 space-y-2">
        <legend class="px-1 text-sm font-semibold">📍 Livraison</legend>
        <div class="grid gap-2 sm:grid-cols-2">
          <EditField v-model="form.recipient_name" label="Nom du client" :before="initial.recipient_name" />
          <EditField v-model="form.recipient_phone" label="Téléphone du client" :before="initial.recipient_phone" required />
          <EditField v-model="form.recipient_phone2" label="Autre numéro" :before="initial.recipient_phone2" />
          <div>
            <label class="label" for="edit-zone">Commune / quartier</label>
            <select id="edit-zone" v-model="form.delivery_zone_id" :class="['input', changed('delivery_zone_id') && 'ring-2 ring-amber-400 bg-amber-50']">
              <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
            </select>
            <p v-if="changed('delivery_zone_id')" class="text-xs text-gray-400 line-through">{{ zoneName(initial.delivery_zone_id) }}</p>
          </div>
          <EditField v-model="form.delivery_address" label="Adresse" :before="initial.delivery_address" />
          <EditField v-model="form.delivery_landmark" label="Repère" :before="initial.delivery_landmark" />
          <EditField v-model="form.delivery_scheduled_date" type="date" :min="today" label="Jour de livraison" :before="initial.delivery_scheduled_date" />
          <EditField v-model="form.delivery_time_slot" label="Créneau (ex. 14-18)" :before="initial.delivery_time_slot" maxlength="20" />
        </div>
      </fieldset>

      <!-- Colis, argent et ramassage : seulement avant le ramassage -->
      <fieldset :class="['rounded-lg border p-3 space-y-2', !beforePickup && 'bg-slate-50 text-gray-400']" :disabled="!beforePickup">
        <legend class="px-1 text-sm font-semibold">📦 Colis et argent <span v-if="!beforePickup" class="ml-1 text-xs font-medium">🔒 colis déjà récupéré</span></legend>
        <div v-if="beforePickup" class="grid gap-2 sm:grid-cols-2">
          <EditField v-model.number="form.items_amount" type="number" min="0" label="Montant des articles (F)" :before="initial.items_amount" />
          <div>
            <label class="label" for="edit-payer">Frais de livraison payés par</label>
            <select id="edit-payer" v-model="form.fee_payer" :class="['input', changed('fee_payer') && 'ring-2 ring-amber-400 bg-amber-50']">
              <option value="recipient">Le client</option>
              <option value="merchant">L'e-commerçant</option>
            </select>
          </div>
          <div>
            <label class="label" for="edit-size">Taille</label>
            <select id="edit-size" v-model="form.package_size" :class="['input', changed('package_size') && 'ring-2 ring-amber-400 bg-amber-50']">
              <option :value="null">—</option>
              <option v-for="s in ['S', 'M', 'L', 'XL']" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <EditField v-model.number="form.weight_kg" type="number" min="0" step="0.1" label="Poids (kg)" :before="initial.weight_kg" />
          <label class="flex items-center gap-2 text-sm"><input v-model="form.is_fragile" type="checkbox"> Fragile</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.is_express" type="checkbox"> Express</label>
          <div class="sm:col-span-2"><EditField v-model="form.description" label="Contenu du colis" :before="initial.description" /></div>
        </div>
      </fieldset>

      <fieldset v-if="!order.from_warehouse" :class="['rounded-lg border p-3 space-y-2', !beforePickup && 'bg-slate-50 text-gray-400']" :disabled="!beforePickup">
        <legend class="px-1 text-sm font-semibold">🏪 Ramassage <span v-if="!beforePickup" class="ml-1 text-xs font-medium">🔒 colis déjà récupéré</span></legend>
        <div v-if="beforePickup" class="grid gap-2 sm:grid-cols-2">
          <div>
            <label class="label" for="edit-pzone">Zone</label>
            <select id="edit-pzone" v-model="form.pickup_zone_id" :class="['input', changed('pickup_zone_id') && 'ring-2 ring-amber-400 bg-amber-50']">
              <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
            </select>
          </div>
          <EditField v-model="form.pickup_address" label="Adresse" :before="initial.pickup_address" />
          <EditField v-model="form.pickup_contact_name" label="Contact" :before="initial.pickup_contact_name" />
          <EditField v-model="form.pickup_phone" label="Téléphone" :before="initial.pickup_phone" />
        </div>
      </fieldset>

      <!-- Nouveau prix avant d'enregistrer -->
      <div v-if="preview && (preview.fees.from !== preview.fees.to || preview.cod.from !== preview.cod.to)" class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
        💰 <template v-if="preview.fees.from !== preview.fees.to">Livraison : <b>{{ money(preview.fees.from) }} → {{ money(preview.fees.to) }}</b>. </template>
        Le livreur encaissera <b>{{ money(preview.cod.to) }}</b><template v-if="preview.cod.from !== preview.cod.to"> au lieu de {{ money(preview.cod.from) }}</template>.
      </div>
      <p v-if="preview?.needs_approval" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">⏳ Votre agence valide cette modification avant qu'elle s'applique. Vous serez prévenu.</p>

      <label v-if="isStaff && courierName" class="flex items-center gap-2 text-sm">
        <input v-model="notifyCourier" type="checkbox"> Prévenir le livreur {{ courierName }} (notification sur son téléphone)
      </label>

      <p v-if="error" class="field-error">{{ error }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$emit('close')">Annuler</button>
        <button class="btn-primary" :disabled="saving || !dirty">{{ saving ? 'Enregistrement…' : (preview?.needs_approval ? 'Envoyer la modification' : 'Enregistrer les changements') }}</button>
      </div>
    </form>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import Modal from './Modal.vue'
import EditField from './EditField.vue'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toasts'
import { money } from '../utils/format'

/**
 * « Modifier la course » : livraison, colis et argent, ramassage (selon l'avancement), champs changés surlignés,
 * nouveau prix avant d'enregistrer, validation par l'agence pour l'e-commerçant si le réglage le demande.
 */
const props = defineProps({ open: Boolean, order: { type: Object, default: null } })
const emit = defineEmits(['close', 'saved'])

const auth = useAuthStore()
const toasts = useToastStore()
const isStaff = computed(() => !auth.user?.merchant_id)
const zones = ref([])
const form = reactive({})
const initial = ref({})
const preview = ref(null)
const error = ref('')
const saving = ref(false)
const notifyCourier = ref(true)
const today = new Date().toISOString().slice(0, 10)

const beforePickup = computed(() => props.order?.edit_scope === 'all')
const courier = computed(() => props.order?.delivery_courier ?? props.order?.pickup_courier ?? null)
const courierName = computed(() => courier.value?.name ?? '')
const zoneName = (id) => zones.value.find((z) => z.id === id)?.full_name ?? ''

function valuesOf(o) {
  return {
    recipient_name: o.recipient.name ?? '',
    recipient_phone: o.recipient.phone ?? '',
    recipient_phone2: o.recipient.phone2 ?? '',
    delivery_zone_id: o.delivery.zone_id,
    delivery_address: o.delivery.address ?? '',
    delivery_landmark: o.delivery.landmark ?? '',
    delivery_scheduled_date: o.delivery.scheduled_date ?? '',
    delivery_time_slot: o.delivery.time_slot ?? '',
    ...(o.edit_scope === 'all' ? {
      items_amount: o.amounts.items_amount,
      fee_payer: o.amounts.fee_payer,
      package_size: o.package.size ?? null,
      weight_kg: o.package.weight_kg ?? '',
      is_fragile: !!o.package.is_fragile,
      is_express: !!o.package.is_express,
      description: o.package.description ?? '',
      ...(o.from_warehouse ? {} : {
        pickup_zone_id: o.pickup.zone_id,
        pickup_address: o.pickup.address ?? '',
        pickup_contact_name: o.pickup.contact_name ?? '',
        pickup_phone: o.pickup.phone ?? '',
      }),
    } : {}),
  }
}

const norm = (v) => (v === '' || v === undefined ? null : v)
const changed = (key) => norm(form[key]) !== norm(initial.value[key])
// Seuls les champs changés sont envoyés (un champ vidé devient null)
const payload = computed(() => Object.fromEntries(Object.keys(initial.value).filter(changed).map((k) => [k, norm(form[k])])))
const dirty = computed(() => Object.keys(payload.value).length > 0)

watch(() => props.open, async (open) => {
  if (!open || !props.order) return
  error.value = ''
  preview.value = null
  notifyCourier.value = true
  initial.value = valuesOf(props.order)
  Object.keys(form).forEach((k) => delete form[k])
  Object.assign(form, initial.value)
  if (!zones.value.length) zones.value = (await http.get('/zones')).data.data
})

// Aperçu du prix et de la validation, un instant après la dernière frappe
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
    const body = { ...payload.value, ...(isStaff.value && courierName.value ? { notify_courier: notifyCourier.value } : {}) }
    const { data } = await http.patch(`/orders/${props.order.id}`, body)
    toasts.success(data.data.pending_change ? 'Modification envoyée à l\'agence pour validation.' : 'Course modifiée.')
    emit('saved', data.data)
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
