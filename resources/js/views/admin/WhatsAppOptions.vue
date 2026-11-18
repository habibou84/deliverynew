<template>
  <div class="max-w-4xl space-y-4">
    <div>
      <h1 class="text-xl font-bold">WhatsApp</h1>
      <p class="text-sm text-slate-600">Le numéro de l'entreprise, les messages qui partent tout seuls, et comment ils partent.</p>
    </div>
    <div><WhatsAppTabs /></div>

    <p class="text-sm text-slate-600">Ces réglages valent pour tous les messages automatiques à la fois.</p>
    <p v-if="loadError" class="field-error">{{ loadError }}</p>
    <p v-else-if="!s" class="card p-6 text-center text-slate-600">Chargement…</p>

    <section v-if="s" class="card divide-y overflow-hidden">
      <!-- SMS de secours -->
      <div class="flex flex-wrap items-center gap-4 px-5 py-4">
        <SwitchButton :on="s.sms_fallback" label="SMS si le WhatsApp n'arrive pas" :disabled="busy.sms_fallback" @toggle="save('sms_fallback', !s.sms_fallback)" />
        <div class="min-w-64 flex-1">
          <p class="font-semibold">SMS si le WhatsApp n'arrive pas</p>
          <p class="text-sm text-slate-600">
            Numéro sans WhatsApp ou échec définitif : le même message part par SMS. Jamais pour une réponse écrite par l'équipe.
            <template v-if="s.sms_driver === 'none'"> Les SMS sont désactivés sur ce serveur.</template>
            <template v-else-if="s.sms_driver === 'log'"> SMS simulés sur ce serveur.</template>
          </p>
        </div>
      </div>

      <!-- Notification avant WhatsApp -->
      <div class="flex flex-wrap items-center gap-4 px-5 py-4">
        <SwitchButton :on="s.merchant_push_first" label="Notification avant WhatsApp" :disabled="!s.push_enabled || busy.merchant_push_first" @toggle="save('merchant_push_first', !s.merchant_push_first)" />
        <div class="min-w-64 flex-1">
          <p class="font-semibold">Notification sur le téléphone des e-commerçants avant WhatsApp</p>
          <p class="text-sm text-slate-600">
            Gratuit. WhatsApp ne sert qu'aux e-commerçants qui n'ont pas activé les notifications, ou si la notification n'arrive pas.
            <template v-if="!s.push_enabled"> Notifications non configurées sur ce serveur.</template>
            <template v-else-if="s.merchants_total"> {{ s.merchants_with_push }} e-commerçant{{ s.merchants_with_push > 1 ? 's' : '' }} sur {{ s.merchants_total }} les reçoivent déjà.</template>
          </p>
        </div>
      </div>

      <!-- Robot de courses -->
      <div class="flex flex-wrap items-center gap-4 px-5 py-4">
        <SwitchButton v-if="auth.hasFeature('whatsapp_bot')" :on="s.whatsapp_orders" label="Courses par WhatsApp" :disabled="busy.whatsapp_orders" @toggle="save('whatsapp_orders', !s.whatsapp_orders)" />
        <div class="min-w-64 flex-1">
          <p class="font-semibold">Courses par WhatsApp</p>
          <p class="text-sm text-slate-600">
            Les e-commerçants créent leurs courses en écrivant au numéro de l'entreprise : le robot demande ce qui manque, montre le prix et crée la course après confirmation.
            {{ s.ai_parser ? 'Il comprend aussi les messages libres (Claude).' : 'Il comprend les messages au format « Nom : … », numéros, montants et communes.' }}
          </p>
          <p v-if="!auth.hasFeature('whatsapp_bot')" class="mt-1 text-sm text-amber-800">
            Pas inclus dans votre formule.
            <RouterLink v-if="auth.user?.company?.plan" to="/admin/abonnement" class="font-semibold underline">Voir mon abonnement</RouterLink>
          </p>
        </div>
        <RouterLink v-if="auth.hasFeature('whatsapp_bot')" to="/admin/whatsapp/simulateur" class="btn-secondary">Essayer le robot</RouterLink>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import SwitchButton from '../../components/ui/SwitchButton.vue'
import WhatsAppTabs from '../../components/WhatsAppTabs.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

/**
 * WhatsApp › Options d'envoi : ce qui vaut pour tous les messages (SMS de secours, notification avant WhatsApp,
 * robot de création de courses).
 */
const auth = useAuthStore()
const toasts = useToastStore()
const s = ref(null)
const loadError = ref('')
const busy = reactive({})

async function save(key, value) {
  busy[key] = true
  try {
    s.value = (await http.put('/whatsapp/settings', { [key]: value })).data.data
    toasts.success('Réglage enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy[key] = false
  }
}

onMounted(async () => {
  try {
    s.value = (await http.get('/whatsapp/settings')).data.data
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  }
})
</script>
