<template>
  <div class="max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">Paramètres de l'entreprise</h1>

    <form v-if="form" class="card p-4 space-y-4" @submit.prevent="save">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="name">Nom</label><input id="name" v-model="form.name" class="input" required></div>
        <div><label class="label" for="phone">Téléphone</label><input id="phone" v-model="form.phone" class="input"></div>
        <div class="sm:col-span-2"><label class="label" for="address">Adresse</label><input id="address" v-model="form.address" class="input"></div>
      </div>

      <fieldset class="space-y-3 border-t pt-4">
        <legend class="font-semibold">Courses</legend>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.auto_confirm_orders" type="checkbox" class="mt-1">
          <span>Valider automatiquement les nouvelles courses<span class="block text-gray-500">Sinon, chaque course attend la validation d'un dispatcher.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.require_delivery_code" type="checkbox" class="mt-1">
          <span>Exiger le code de livraison du destinataire<span class="block text-gray-500">Le destinataire reçoit son code sur WhatsApp quand le livreur part livrer (Paramètres WhatsApp), ou par le marchand.</span></span>
        </label>
        <div>
          <label class="label" for="attempts">Nombre maximal de tentatives de livraison</label>
          <input id="attempts" v-model.number="form.default_max_attempts" type="number" min="1" max="10" class="input w-24">
        </div>
        <div>
          <label class="label" for="reminder">Relancer un problème signalé par un livreur et resté sans suite après (minutes)</label>
          <input id="reminder" v-model.number="form.field_alert_reminder_minutes" type="number" min="0" max="240" class="input w-24">
          <p class="text-xs text-gray-500 mt-1">Relance au dispatch après ce délai, puis aux administrateurs après trois fois ce délai. 0 = pas de relance. Répondre au livreur ou traiter la remontée arrête les relances.</p>
        </div>
        <div>
          <label class="label" for="hold-alert">Alerter quand un colis non livré reste chez un livreur depuis plus de (heures)</label>
          <input id="hold-alert" v-model.number="form.parcel_hold_alert_hours" type="number" min="0" max="168" class="input w-24">
          <p class="text-xs text-gray-500 mt-1">Alerte sonore au dispatch, une fois par colis, et colis signalé en rouge dans « Colis chez les livreurs ». 0 = jamais.</p>
        </div>
      </fieldset>

      <fieldset class="space-y-3 border-t pt-4">
        <legend class="font-semibold">Finance</legend>
        <div>
          <label class="label" for="returnfee">Frais facturés au marchand pour un colis retourné (% des frais de livraison)</label>
          <input id="returnfee" v-model.number="form.return_fee_percent" type="number" min="0" max="100" class="input w-24">
        </div>
      </fieldset>

      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary" :disabled="saving">Enregistrer</button>
    </form>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const FIELDS = ['name', 'phone', 'address', 'auto_confirm_orders', 'require_delivery_code', 'default_max_attempts', 'return_fee_percent', 'field_alert_reminder_minutes', 'parcel_hold_alert_hours']

const auth = useAuthStore()
const toasts = useToastStore()
const form = ref(null)
const error = ref('')
const saving = ref(false)

onMounted(async () => {
  const { data } = await http.get(`/companies/${auth.user.company_id}`)
  form.value = Object.fromEntries(FIELDS.map((f) => [f, data.data[f]]))
})

async function save() {
  saving.value = true
  error.value = ''
  try {
    const payload = Object.fromEntries(Object.entries(form.value).filter(([, v]) => v !== null && v !== ''))
    await http.patch(`/companies/${auth.user.company_id}`, payload)
    await auth.fetchUser()
    toasts.success('Paramètres enregistrés.')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
