<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">Mon compte</h1>

    <form v-if="account" class="card space-y-3 p-4" @submit.prevent="save">
      <h2 class="font-semibold">Identité et identifiants</h2>
      <p class="text-sm text-slate-600">Vous vous connectez avec le téléphone ou l'e-mail, puis le code de votre application d'authentification.</p>
      <div><label class="label" for="a-name">Nom</label><input id="a-name" v-model.trim="form.name" class="input" required></div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="a-phone">Téléphone</label><input id="a-phone" v-model.trim="form.phone" type="tel" class="input" required></div>
        <div><label class="label" for="a-email">E-mail (alertes de connexion)</label><input id="a-email" v-model.trim="form.email" type="email" class="input" placeholder="vous@gmail.com"></div>
      </div>
      <div v-if="credentialsChanged" class="max-w-xs">
        <label class="label" for="a-current">Votre mot de passe actuel</label>
        <input id="a-current" v-model="form.current_password" type="password" autocomplete="current-password" class="input" required>
        <p class="mt-1 text-xs text-slate-600">Demandé pour changer le téléphone ou l'e-mail.</p>
      </div>
      <p v-if="formError" class="field-error">{{ formError }}</p>
      <button class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </form>

    <form class="card space-y-3 p-4" @submit.prevent="changePassword">
      <h2 class="font-semibold">Mot de passe</h2>
      <p class="text-sm text-slate-600">Au moins 12 caractères. Vos autres appareils seront déconnectés et le code de l'application leur sera redemandé.</p>
      <div class="max-w-xs"><label class="label" for="p-current">Mot de passe actuel</label><input id="p-current" v-model="pwd.current_password" type="password" autocomplete="current-password" class="input" required></div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="p-new">Nouveau mot de passe</label><input id="p-new" v-model="pwd.password" type="password" autocomplete="new-password" minlength="12" class="input" required></div>
        <div><label class="label" for="p-confirm">Confirmer</label><input id="p-confirm" v-model="pwd.password_confirmation" type="password" autocomplete="new-password" minlength="12" class="input" required></div>
      </div>
      <p v-if="pwdError" class="field-error">{{ pwdError }}</p>
      <button class="btn-primary" :disabled="pwdSaving">{{ pwdSaving ? 'Changement…' : 'Changer le mot de passe' }}</button>
    </form>

    <div v-if="account" class="card space-y-3 p-4">
      <h2 class="font-semibold">Connexion en deux étapes</h2>
      <p class="text-sm text-slate-600">
        <template v-if="account.two_factor.enabled">✅ Application d'authentification configurée le {{ dateTime(account.two_factor.confirmed_at) }}.
          Codes de secours restants : <b>{{ account.two_factor.recovery_codes_left }}</b>.</template>
        <template v-else>Aucune application configurée : elle vous sera demandée à la prochaine connexion.</template>
      </p>
      <p v-if="account.two_factor.enabled && account.two_factor.recovery_codes_left <= 2" class="rounded-lg bg-amber-50 p-2 text-sm text-amber-900">⚠️ Il vous reste peu de codes de secours : créez-en de nouveaux.</p>

      <RecoveryCodes v-if="newCodes" :codes="newCodes" />

      <div v-if="setupKey" class="space-y-3 rounded-lg border border-slate-200 p-3">
        <p class="text-sm text-slate-600">Scannez ce QR code avec la nouvelle application, puis saisissez le code qu'elle affiche. L'ancienne application reste valable jusque-là.</p>
        <img v-if="setupQr" :src="setupQr" alt="QR code à scanner avec l'application d'authentification" class="h-44 w-44 rounded-lg border border-slate-200 bg-white p-1">
        <p class="text-xs text-slate-600">Clé : <span class="select-all font-mono">{{ setupKey.secret }}</span> · compte {{ setupKey.account }}</p>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="confirmSetup">
          <div><label class="label" for="tf-code">Code de la nouvelle application</label><input id="tf-code" v-model.trim="tf.code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="input w-40 font-mono" required></div>
          <button class="btn-primary" :disabled="tfBusy">Confirmer</button>
          <button type="button" class="btn-secondary" @click="setupKey = null">Annuler</button>
        </form>
      </div>
      <form v-else class="flex flex-wrap items-end gap-2" @submit.prevent>
        <div><label class="label" for="tf-pwd">Mot de passe actuel</label><input id="tf-pwd" v-model="tf.current_password" type="password" autocomplete="current-password" class="input"></div>
        <button type="button" class="btn-secondary" :disabled="tfBusy || !tf.current_password" @click="startSetup">Changer d'application</button>
        <button v-if="account.two_factor.enabled" type="button" class="btn-secondary" :disabled="tfBusy || !tf.current_password" @click="regenerate">Nouveaux codes de secours</button>
      </form>
      <p v-if="tfError" class="field-error">{{ tfError }}</p>
    </div>

    <div v-if="account" class="card space-y-3 p-4">
      <h2 class="font-semibold">Appareils retenus</h2>
      <p class="text-sm text-slate-600">Sur ces appareils, le code de l'application n'est pas redemandé pendant 30 jours.</p>
      <p v-if="!account.devices.length" class="text-sm text-slate-600">Aucun appareil retenu.</p>
      <ul v-else class="divide-y text-sm">
        <li v-for="d in account.devices" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <span class="min-w-0">
            <span class="block truncate">{{ deviceName(d.label) }}</span>
            <span class="text-xs text-slate-600">Retenu le {{ dateTime(d.created_at) }} · {{ d.ip }}<template v-if="d.last_used_at"> · dernière fois le {{ dateTime(d.last_used_at) }}</template></span>
          </span>
          <button type="button" class="text-red-600 underline" @click="forget(d.id)">Oublier</button>
        </li>
      </ul>
      <button v-if="account.devices.length > 1" type="button" class="btn-secondary" @click="forget(null)">Oublier tous les appareils</button>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'
import { confirmDialog } from '../../composables/useDialog'
import QRCode from 'qrcode'
import RecoveryCodes from '../../components/auth/RecoveryCodes.vue'

const auth = useAuthStore()
const toasts = useToastStore()
const account = ref(null)
const form = reactive({ name: '', phone: '', email: '', current_password: '' })
const pwd = reactive({ current_password: '', password: '', password_confirmation: '' })
const saving = ref(false)
const pwdSaving = ref(false)
const formError = ref('')
const pwdError = ref('')
const tf = reactive({ current_password: '', code: '' })
const tfBusy = ref(false)
const tfError = ref('')
const setupKey = ref(null)
const setupQr = ref('')
const newCodes = ref(null)

const digits = (v) => String(v ?? '').replace(/\D/g, '').slice(-10)
const credentialsChanged = computed(() => account.value
  && (digits(form.phone) !== digits(account.value.phone) || (form.email || '') !== (account.value.email || '')))

function fill(data) {
  account.value = data
  Object.assign(form, { name: data.name, phone: data.phone, email: data.email ?? '', current_password: '' })
}

// Nom lisible d'un navigateur (texte envoyé par le navigateur à la connexion)
function deviceName(label) {
  const text = String(label || '')
  const browser = ['Edg', 'Chrome', 'Firefox', 'Safari'].find((b) => text.includes(b)) ?? 'Navigateur'
  const system = ['Android', 'iPhone', 'Windows', 'Mac OS', 'Linux'].find((s) => text.includes(s)) ?? ''
  return `${browser === 'Edg' ? 'Edge' : browser}${system ? ` · ${system}` : ''}`
}

async function save() {
  saving.value = true
  formError.value = ''
  try {
    fill((await http.put('/console/account', { ...form, email: form.email || null })).data.data)
    if (auth.user) auth.user.name = account.value.name
    toasts.success('Compte enregistré.')
  } catch (e) {
    formError.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function changePassword() {
  pwdSaving.value = true
  pwdError.value = ''
  try {
    await http.put('/auth/password', pwd)
    Object.assign(pwd, { current_password: '', password: '', password_confirmation: '' })
    fill((await http.get('/console/account')).data.data)
    toasts.success('Mot de passe changé.')
  } catch (e) {
    pwdError.value = apiErrorMessage(e)
  } finally {
    pwdSaving.value = false
  }
}

// Connexion en deux étapes : changer d'application (nouvelle clé), nouveaux codes de secours
async function startSetup() {
  tfBusy.value = true
  tfError.value = ''
  newCodes.value = null
  try {
    setupKey.value = (await http.post('/console/account/two-factor/setup', { current_password: tf.current_password })).data.data
    setupQr.value = await QRCode.toDataURL(setupKey.value.uri, { margin: 1, width: 352 }).catch(() => '')
    Object.assign(tf, { current_password: '', code: '' })
  } catch (e) {
    tfError.value = apiErrorMessage(e)
  } finally {
    tfBusy.value = false
  }
}

async function confirmSetup() {
  tfBusy.value = true
  tfError.value = ''
  try {
    const { data } = await http.post('/console/account/two-factor/confirm', { code: tf.code })
    fill(data.data)
    newCodes.value = data.recovery_codes
    setupKey.value = null
    tf.code = ''
    toasts.success('Nouvelle application activée.')
  } catch (e) {
    tfError.value = apiErrorMessage(e)
  } finally {
    tfBusy.value = false
  }
}

async function regenerate() {
  if (!await confirmDialog('Créer de nouveaux codes de secours ? Les anciens ne marcheront plus.')) return
  tfBusy.value = true
  tfError.value = ''
  try {
    const { data } = await http.post('/console/account/two-factor/recovery-codes', { current_password: tf.current_password })
    fill(data.data)
    newCodes.value = data.recovery_codes
    tf.current_password = ''
  } catch (e) {
    tfError.value = apiErrorMessage(e)
  } finally {
    tfBusy.value = false
  }
}

async function forget(id) {
  if (!await confirmDialog(id ? 'Oublier cet appareil ? Le code lui sera redemandé.' : 'Oublier tous les appareils ? Le code sera redemandé partout, ici compris.')) return
  try {
    fill((await http.delete(id ? `/console/account/devices/${id}` : '/console/account/devices')).data.data)
    toasts.success('Appareil oublié.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(async () => {
  try {
    fill((await http.get('/console/account')).data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
})
</script>
