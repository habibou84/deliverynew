<template>
  <section v-if="settings" class="space-y-2">
    <h2 class="px-1 text-sm font-semibold text-slate-500 uppercase tracking-wide">Messages WhatsApp</h2>

    <div class="m-card divide-y">
      <button type="button" class="tap w-full p-4 flex items-center gap-3 text-left active:bg-slate-50" @click="openPhone">
        <span class="text-2xl">💬</span>
        <span class="flex-1">
          <span class="block text-sm text-slate-500">Envoyés au</span>
          <span class="block font-medium">{{ settings.messaging_phone || 'Aucun numéro' }}</span>
        </span>
        <span class="text-sm font-medium text-[var(--app-color)]">Modifier</span>
      </button>
    </div>

    <div class="m-card divide-y">
      <ToggleRow
        v-for="e in settings.events"
        :key="e.event"
        :model-value="e.whatsapp"
        :label="e.label"
        :description="HINTS[e.event]"
        @update:model-value="setEvent(e, $event)"
      />
    </div>

    <h2 class="px-1 pt-2 text-sm font-semibold text-slate-500 uppercase tracking-wide">Point d'activité</h2>
    <div class="m-card divide-y">
      <template v-for="(r, frequency) in settings.reports" :key="frequency">
        <ToggleRow
          :model-value="r.active"
          :label="frequency === 'daily' ? 'Point du jour' : 'Point de la semaine'"
          :description="frequency === 'daily' ? `Chaque soir à ${r.send_time}` : `Chaque ${WEEKDAYS[r.weekday - 1]} à ${r.send_time}, pour les 7 derniers jours`"
          @update:model-value="setReport(frequency, { active: $event })"
        />
        <div v-if="r.active" class="p-4 pt-2">
          <p class="m-label">Heure d'envoi</p>
          <ChoiceChips
            :model-value="r.send_time"
            :options="(frequency === 'daily' ? DAILY_TIMES : WEEKLY_TIMES).map((t) => ({ value: t, label: t.replace(':', ' h ') }))"
            :columns="3"
            label="Heure d'envoi"
            @update:model-value="setReport(frequency, { send_time: $event })"
          />
        </div>
      </template>
    </div>

    <BottomSheet :open="phoneSheet" title="Numéro WhatsApp" @close="phoneSheet = false">
      <form class="space-y-4" @submit.prevent="savePhone">
        <div>
          <label class="m-label" for="wa-number">Numéro qui reçoit les messages</label>
          <input id="wa-number" v-model="phone" class="m-input" type="tel" inputmode="tel" placeholder="07 00 00 00 00">
          <p class="text-sm text-slate-500 mt-1">Laissez vide pour utiliser le numéro principal de la boutique.</p>
        </div>
        <p v-if="phoneError" class="text-sm text-red-600">{{ phoneError }}</p>
        <button class="m-btn-primary" :disabled="saving">Enregistrer</button>
      </form>
    </BottomSheet>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from './BottomSheet.vue'
import ChoiceChips from './ChoiceChips.vue'
import ToggleRow from './ToggleRow.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const HINTS = {
  'order.confirmed': 'Quand une course est validée',
  'order.picked_up': 'Quand le livreur a votre colis',
  'order.delivered': 'Avec le montant encaissé',
  'order.incident': 'Client injoignable, refus, report…',
  'payout.paid': 'Quand votre argent est envoyé',
}
const WEEKDAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']
const DAILY_TIMES = ['18:00', '19:00', '20:00', '21:00', '22:00', '07:00']
const WEEKLY_TIMES = ['08:00', '09:00', '12:00']

const auth = useAuthStore()
const toasts = useToastStore()
const settings = ref(null)
const phoneSheet = ref(false)
const phone = ref('')
const phoneError = ref('')
const saving = ref(false)

const url = () => `/merchants/${auth.user.merchant_id}/notifications`

async function save(payload, success = 'Enregistré.') {
  saving.value = true
  try {
    settings.value = (await http.put(url(), payload)).data.data
    toasts.success(success)
    return true
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return false
  } finally {
    saving.value = false
  }
}

function setEvent(e, value) {
  e.whatsapp = value // réactif tout de suite, corrigé par la réponse en cas d'erreur
  save({ events: { [e.event]: value } }, value ? `« ${e.label} » activé.` : `« ${e.label} » désactivé.`)
}

function setReport(frequency, change) {
  const current = settings.value.reports[frequency]
  const next = { active: current.active, send_time: current.send_time, weekday: current.weekday, ...change }
  Object.assign(current, next)
  save({ reports: { [frequency]: next } })
}

function openPhone() {
  phone.value = settings.value.whatsapp_phone || ''
  phoneError.value = ''
  phoneSheet.value = true
}

async function savePhone() {
  phoneError.value = ''
  try {
    settings.value = (await http.put(url(), { whatsapp_phone: phone.value || null })).data.data
    phoneSheet.value = false
    toasts.success('Numéro enregistré.')
  } catch (e) {
    phoneError.value = apiErrorMessage(e)
  }
}

onMounted(async () => {
  try {
    settings.value = (await http.get(url())).data.data
  } catch {
    settings.value = null // hors ligne : la section est masquée
  }
})
</script>
