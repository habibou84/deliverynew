<template>
  <div class="max-w-4xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">WhatsApp</h1>
      <RouterLink to="/admin/messages" class="btn-secondary">Journal des messages</RouterLink>
    </div>

    <p v-if="loadError" class="field-error">{{ loadError }}</p>

    <template v-if="s">
      <div v-if="s.driver !== 'meta'" class="rounded-lg bg-amber-50 text-amber-900 p-4 text-sm">
        <strong>Mode simulation.</strong> Aucun message n'est réellement envoyé : ils sont enregistrés dans le
        <RouterLink to="/admin/messages" class="underline">journal des messages</RouterLink> et dans les logs.
        Pour envoyer pour de vrai, renseignez le numéro ci-dessous puis mettez <code>WHATSAPP_DRIVER=meta</code> dans le fichier <code>.env</code>.
      </div>

      <!-- Numéro -->
      <form class="card p-4 space-y-4" @submit.prevent="saveAccount">
        <div class="flex items-center justify-between gap-2">
          <h2 class="font-semibold">Numéro WhatsApp Business (API Cloud de Meta)</h2>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', s.account.status === 'connected' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700']">
            {{ s.account.status === 'connected' ? 'Configuré' : 'À configurer' }}
          </span>
        </div>
        <p class="text-sm text-gray-500">
          Ces informations se trouvent dans Meta for Developers, rubrique WhatsApp &gt; Configuration de l'API.
          Utilisez un jeton d'accès permanent (utilisateur système), pas le jeton temporaire de 24 h.
        </p>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label" for="wa-phone">Numéro affiché</label><input id="wa-phone" v-model="account.display_phone" class="input" placeholder="07 00 00 00 00"></div>
          <div><label class="label" for="wa-pnid">Identifiant du numéro (Phone number ID)</label><input id="wa-pnid" v-model="account.phone_number_id" class="input" inputmode="numeric"></div>
          <div><label class="label" for="wa-waba">Identifiant du compte WhatsApp Business (WABA ID)</label><input id="wa-waba" v-model="account.waba_id" class="input" inputmode="numeric"></div>
          <div>
            <label class="label" for="wa-token">Jeton d'accès</label>
            <input id="wa-token" v-model="account.access_token" type="password" autocomplete="off" class="input" :placeholder="s.account.has_access_token ? 'Enregistré (laisser vide pour le garder)' : ''">
          </div>
        </div>
        <p v-if="accountError" class="field-error">{{ accountError }}</p>
        <button class="btn-primary" :disabled="saving">Enregistrer le numéro</button>
      </form>

      <!-- Options -->
      <div class="card p-4 space-y-3">
        <h2 class="font-semibold">Envois</h2>
        <label class="flex items-start gap-2 text-sm">
          <input :checked="s.notify_recipients" type="checkbox" class="mt-1" @change="saveOption('notify_recipients', $event.target.checked)">
          <span>Prévenir le destinataire quand son colis part en livraison
            <span class="block text-gray-500">Message avec le montant à payer, le code de livraison et le lien de suivi.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input :checked="s.sms_fallback" type="checkbox" class="mt-1" @change="saveOption('sms_fallback', $event.target.checked)">
          <span>Envoyer un SMS si le message WhatsApp n'arrive pas
            <span class="block text-gray-500">
              Numéro sans WhatsApp ou échec définitif.
              <template v-if="s.sms_driver === 'none'">Les SMS sont désactivés sur ce serveur (<code>SMS_DRIVER=none</code>).</template>
              <template v-else-if="s.sms_driver === 'log'">SMS simulés sur ce serveur (<code>SMS_DRIVER=log</code>).</template>
            </span></span>
        </label>
        <p class="text-sm text-gray-500">Chaque marchand choisit les messages qu'il reçoit depuis son application (Profil).</p>
      </div>

      <!-- Test -->
      <form class="card p-4 space-y-3" @submit.prevent="sendTest">
        <h2 class="font-semibold">Message de test</h2>
        <p class="text-sm text-gray-500">Envoie le modèle « hello_world » de Meta, disponible sans approbation.</p>
        <div class="flex flex-wrap gap-2">
          <input v-model="testPhone" class="input w-56" placeholder="07 00 00 00 00" aria-label="Numéro de test" required>
          <button class="btn-secondary" :disabled="testing">Envoyer</button>
        </div>
        <p v-if="testResult" class="text-sm flex items-center gap-2">
          <MessageStatus :message="testResult" />
          <span v-if="testResult.error" class="text-red-700">{{ testResult.error }}</span>
        </p>
      </form>

      <!-- Webhook -->
      <div class="card p-4 space-y-2 text-sm">
        <h2 class="font-semibold">Accusés de réception (webhook)</h2>
        <p class="text-gray-500">Dans l'application Meta, rubrique WhatsApp &gt; Configuration, abonnez ce lien au champ <code>messages</code> :</p>
        <div class="flex gap-2">
          <input :value="s.webhook_url" readonly class="input font-mono text-xs" aria-label="Adresse du webhook">
          <button type="button" class="btn-secondary" @click="copy(s.webhook_url)">Copier</button>
        </div>
        <p :class="s.webhook_ready ? 'text-emerald-700' : 'text-amber-700'">
          {{ s.webhook_ready ? 'Secret de l\'application et jeton de vérification configurés.' : 'À compléter dans .env : WHATSAPP_APP_SECRET et WHATSAPP_VERIFY_TOKEN.' }}
        </p>
      </div>

      <!-- Modèles -->
      <div class="card p-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">Modèles de messages</h2>
          <button class="btn-secondary" :disabled="syncing" @click="sync">Vérifier l'approbation chez Meta</button>
        </div>
        <p class="text-sm text-gray-500">
          Hors conversation ouverte par le client, WhatsApp n'accepte que des modèles approuvés par Meta.
          Créez chacun d'eux dans le gestionnaire WhatsApp : catégorie <strong>Utilitaire</strong>, langue <strong>Français</strong>,
          nom et texte exactement comme ci-dessous. L'approbation prend de quelques minutes à 48 h.
        </p>
        <ul class="divide-y">
          <li v-for="t in s.templates" :key="t.name" class="py-3 space-y-1 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-mono font-medium">{{ t.name }}</span>
              <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', TEMPLATE_STATUS[t.status]?.[1] || 'bg-slate-100 text-slate-700']">
                {{ TEMPLATE_STATUS[t.status]?.[0] || t.status }}<template v-if="s.driver !== 'meta' && t.status !== 'unknown'"> (simulation)</template>
              </span>
            </div>
            <p class="text-gray-500">{{ t.description }}</p>
            <div class="flex gap-2 items-start">
              <p class="flex-1 rounded bg-slate-50 p-2 font-mono text-xs">{{ t.body }}</p>
              <button type="button" class="btn-secondary text-xs" @click="copy(t.body)">Copier</button>
            </div>
            <p class="text-xs text-gray-500">Exemples : {{ t.example.join(' · ') }}</p>
            <p v-if="t.rejected_reason" class="text-xs text-red-700">Motif du refus : {{ t.rejected_reason }}</p>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import MessageStatus from '../../components/MessageStatus.vue'
import { useToastStore } from '../../stores/toasts'

const TEMPLATE_STATUS = {
  approved: ['Approuvé', 'bg-emerald-100 text-emerald-800'],
  pending: ['En cours de validation', 'bg-amber-100 text-amber-800'],
  rejected: ['Refusé', 'bg-red-100 text-red-800'],
  paused: ['En pause', 'bg-amber-100 text-amber-800'],
  disabled: ['Désactivé', 'bg-red-100 text-red-800'],
  unknown: ['À créer chez Meta', 'bg-slate-100 text-slate-700'],
}

const toasts = useToastStore()
const s = ref(null)
const loadError = ref('')
const account = reactive({ display_phone: '', phone_number_id: '', waba_id: '', access_token: '' })
const accountError = ref('')
const saving = ref(false)
const syncing = ref(false)
const testing = ref(false)
const testPhone = ref('')
const testResult = ref(null)

function apply(data) {
  s.value = data
  Object.assign(account, {
    display_phone: data.account.display_phone || '',
    phone_number_id: data.account.phone_number_id || '',
    waba_id: data.account.waba_id || '',
    access_token: '',
  })
}

async function load() {
  try {
    apply((await http.get('/whatsapp/settings')).data.data)
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  }
}

async function saveAccount() {
  saving.value = true
  accountError.value = ''
  try {
    const payload = Object.fromEntries(Object.entries(account).map(([k, v]) => [k, v || null]))
    apply((await http.put('/whatsapp/settings', { account: payload })).data.data)
    toasts.success('Numéro enregistré.')
  } catch (e) {
    accountError.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function saveOption(key, value) {
  try {
    apply((await http.put('/whatsapp/settings', { [key]: value })).data.data)
    toasts.success('Réglage enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function sendTest() {
  testing.value = true
  testResult.value = null
  try {
    const { data } = await http.post('/whatsapp/test', { to: testPhone.value })
    testResult.value = data.data
    followTest(data.data.id)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    testing.value = false
  }
}

// L'envoi passe par la file d'attente : on suit le statut quelques secondes
async function followTest(id, tries = 6) {
  if (tries === 0 || testResult.value?.id !== id || testResult.value.status !== 'queued') return
  await new Promise((resolve) => setTimeout(resolve, 1500))
  try {
    const { data } = await http.get('/messages', { params: { per_page: 20 } })
    const found = data.data.find((m) => m.id === id)
    if (found && testResult.value?.id === id) testResult.value = found
  } catch {
    return
  }
  followTest(id, tries - 1)
}

async function sync() {
  syncing.value = true
  try {
    apply((await http.post('/whatsapp/templates/sync')).data.data)
    toasts.success('État des modèles mis à jour.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    syncing.value = false
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le texte à la main.')
  }
}

onMounted(load)
</script>
