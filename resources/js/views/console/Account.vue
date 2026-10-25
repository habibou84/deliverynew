<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">Mon compte</h1>

    <form v-if="account" class="card space-y-3 p-4" @submit.prevent="save">
      <h2 class="font-semibold">Identité et identifiants</h2>
      <p class="text-sm text-gray-600">Vous vous connectez avec le téléphone ou l'e-mail. Le <b>code de connexion</b> arrive à cet e-mail.</p>
      <div><label class="label" for="a-name">Nom</label><input id="a-name" v-model.trim="form.name" class="input" required></div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="a-phone">Téléphone</label><input id="a-phone" v-model.trim="form.phone" type="tel" class="input" required></div>
        <div><label class="label" for="a-email">E-mail (code de connexion)</label><input id="a-email" v-model.trim="form.email" type="email" class="input" placeholder="vous@gmail.com"></div>
      </div>
      <p v-if="!account.email" class="rounded-lg bg-amber-50 p-2 text-sm text-amber-900">⚠️ Aucun e-mail : le code de connexion ne peut pas vous être envoyé par e-mail.</p>
      <div v-if="credentialsChanged" class="max-w-xs">
        <label class="label" for="a-current">Votre mot de passe actuel</label>
        <input id="a-current" v-model="form.current_password" type="password" autocomplete="current-password" class="input" required>
        <p class="mt-1 text-xs text-gray-500">Demandé pour changer le téléphone ou l'e-mail.</p>
      </div>
      <p v-if="formError" class="field-error">{{ formError }}</p>
      <button class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </form>

    <form class="card space-y-3 p-4" @submit.prevent="changePassword">
      <h2 class="font-semibold">Mot de passe</h2>
      <p class="text-sm text-gray-600">Au moins 12 caractères. Vos autres appareils seront déconnectés et le code de connexion leur sera redemandé.</p>
      <div class="max-w-xs"><label class="label" for="p-current">Mot de passe actuel</label><input id="p-current" v-model="pwd.current_password" type="password" autocomplete="current-password" class="input" required></div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="p-new">Nouveau mot de passe</label><input id="p-new" v-model="pwd.password" type="password" autocomplete="new-password" minlength="12" class="input" required></div>
        <div><label class="label" for="p-confirm">Confirmer</label><input id="p-confirm" v-model="pwd.password_confirmation" type="password" autocomplete="new-password" minlength="12" class="input" required></div>
      </div>
      <p v-if="pwdError" class="field-error">{{ pwdError }}</p>
      <button class="btn-primary" :disabled="pwdSaving">{{ pwdSaving ? 'Changement…' : 'Changer le mot de passe' }}</button>
    </form>

    <div v-if="account" class="card space-y-3 p-4">
      <h2 class="font-semibold">Appareils retenus</h2>
      <p class="text-sm text-gray-600">Sur ces appareils, le code de connexion n'est pas redemandé pendant 30 jours.</p>
      <p v-if="!account.devices.length" class="text-sm text-gray-500">Aucun appareil retenu.</p>
      <ul v-else class="divide-y text-sm">
        <li v-for="d in account.devices" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <span class="min-w-0">
            <span class="block truncate">{{ deviceName(d.label) }}</span>
            <span class="text-xs text-gray-500">Retenu le {{ dateTime(d.created_at) }} · {{ d.ip }}<template v-if="d.last_used_at"> · dernière fois le {{ dateTime(d.last_used_at) }}</template></span>
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

const auth = useAuthStore()
const toasts = useToastStore()
const account = ref(null)
const form = reactive({ name: '', phone: '', email: '', current_password: '' })
const pwd = reactive({ current_password: '', password: '', password_confirmation: '' })
const saving = ref(false)
const pwdSaving = ref(false)
const formError = ref('')
const pwdError = ref('')

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

async function forget(id) {
  if (!window.confirm(id ? 'Oublier cet appareil ? Le code lui sera redemandé.' : 'Oublier tous les appareils ? Le code sera redemandé partout, ici compris.')) return
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
