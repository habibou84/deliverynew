<template>
  <div class="mx-auto max-w-5xl space-y-4">
    <div>
      <h1 class="text-xl font-bold">Équipe de la console</h1>
      <p class="text-sm text-gray-600">Chaque personne a son propre compte et ne voit que ce que son rôle permet. Toutes leurs actions sont au journal.</p>
    </div>

    <div class="grid gap-3 text-sm sm:grid-cols-3">
      <div v-for="r in ROLES" :key="r.value" class="card p-3">
        <p class="font-semibold">{{ r.icon }} {{ r.label }}</p>
        <p class="text-gray-600">{{ r.text }}</p>
      </div>
    </div>

    <div class="card p-4">
      <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p class="font-semibold">Comptes</p>
        <button class="btn-primary" @click="showInvite = !showInvite">+ Inviter une personne</button>
      </div>

      <form v-if="showInvite" class="mb-4 space-y-3 rounded-lg bg-slate-50 p-3" @submit.prevent="invite">
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label class="label" for="t-name">Nom</label><input id="t-name" v-model.trim="form.name" class="input" required></div>
          <div>
            <label class="label" for="t-role">Rôle</label>
            <select id="t-role" v-model="form.role" class="input"><option v-for="r in ROLES" :key="r.value" :value="r.value">{{ r.icon }} {{ r.label }}</option></select>
          </div>
          <div><label class="label" for="t-phone">Téléphone</label><input id="t-phone" v-model.trim="form.phone" type="tel" class="input" required></div>
          <div><label class="label" for="t-email">E-mail (reçoit le code de connexion)</label><input id="t-email" v-model.trim="form.email" type="email" class="input" required></div>
        </div>
        <p class="text-xs text-gray-600">La personne reçoit un lien personnel (valable 7 jours) pour choisir son mot de passe. Ensuite, un code lui est envoyé par e-mail à chaque connexion.</p>
        <p v-if="error" class="field-error">{{ error }}</p>
        <button class="btn-primary" :disabled="busy">{{ busy ? 'Invitation…' : 'Inviter' }}</button>
      </form>

      <div v-if="link" class="mb-4 space-y-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">
        <p>✓ Invitation prête{{ link.mailed ? ', envoyée par e-mail' : '' }}. Lien valable jusqu'au {{ dateTime(link.expires_at) }} :</p>
        <p class="break-all font-mono text-xs">{{ link.url }}</p>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="btn-secondary" @click="copy(link.url)">📋 Copier le lien</button>
          <a :href="link.whatsapp_link" target="_blank" rel="noopener" class="btn-secondary">💬 Envoyer par WhatsApp</a>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[40rem] text-sm">
          <thead class="text-left text-gray-500"><tr><th class="py-1">Nom</th><th>Rôle</th><th>État</th><th>Dernière connexion</th><th></th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="m in members" :key="m.id">
              <td class="py-2"><span class="font-medium">{{ m.name }}</span><span v-if="m.me" class="text-gray-500"> (vous)</span><span class="block text-xs text-gray-500">{{ m.phone }} · {{ m.email || 'sans e-mail' }}</span></td>
              <td>
                <span v-if="m.me">{{ roleOf(m.role)?.icon }} {{ roleOf(m.role)?.label }}</span>
                <select v-else :value="m.role" class="input !w-auto !py-1" :aria-label="`Rôle de ${m.name}`" @change="changeRole(m, $event.target.value)">
                  <option v-for="r in ROLES" :key="r.value" :value="r.value">{{ r.icon }} {{ r.label }}</option>
                </select>
              </td>
              <td>
                <span v-if="m.status !== 'active'" class="text-red-600">Suspendu</span>
                <span v-else-if="m.invited" class="text-amber-700">Invitation {{ m.invitation_expires_at ? 'envoyée' : 'expirée' }}</span>
                <span v-else-if="!m.email" class="text-amber-700">Actif, sans e-mail</span>
                <span v-else class="text-emerald-700">✓ Actif</span>
              </td>
              <td>{{ m.last_login_at ? dateTime(m.last_login_at) : '—' }}</td>
              <td class="space-x-2 whitespace-nowrap text-right">
                <template v-if="!m.me">
                  <button v-if="m.invited" type="button" class="text-blue-600 underline" @click="reinvite(m)">Nouveau lien</button>
                  <button type="button" :class="m.status === 'active' ? 'text-red-600 underline' : 'text-emerald-700 underline'" @click="toggle(m)">{{ m.status === 'active' ? 'Suspendre' : 'Réactiver' }}</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="mt-3 text-xs text-gray-500">Seul un Propriétaire peut changer un rôle. Il reste toujours au moins un Propriétaire actif. Un rôle changé ou un compte suspendu est déconnecté partout.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const ROLES = [
  { value: 'super_admin', icon: '👑', label: 'Propriétaire', text: 'Tout : formules, Wave, WhatsApp, journal, équipe de la console.' },
  { value: 'platform_support', icon: '🛟', label: 'Support', text: 'Entreprises, assistance, recherche, santé, conversations WhatsApp. Pas de formules, de factures ni de Wave.' },
  { value: 'platform_accountant', icon: '🧾', label: 'Comptable', text: 'Factures, paiements Wave à valider, tableau de bord. Lecture seule ailleurs.' },
]
const roleOf = (value) => ROLES.find((r) => r.value === value)

const toasts = useToastStore()
const members = ref([])
const showInvite = ref(false)
const form = reactive({ name: '', role: 'platform_support', phone: '', email: '' })
const busy = ref(false)
const error = ref('')
const link = ref(null)

async function load() {
  members.value = (await http.get('/console/team')).data.data
}

async function invite() {
  busy.value = true
  error.value = ''
  try {
    link.value = (await http.post('/console/team', form)).data.data
    Object.assign(form, { name: '', phone: '', email: '' })
    showInvite.value = false
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function reinvite(member) {
  try {
    link.value = (await http.post(`/console/team/${member.id}/invitation`)).data.data
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function update(member, payload, message) {
  try {
    members.value = (await http.patch(`/console/team/${member.id}`, payload)).data.data
    toasts.success(message)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    await load()
  }
}

function changeRole(member, role) {
  if (!window.confirm(`Donner le rôle « ${roleOf(role)?.label} » à ${member.name} ? Il sera déconnecté et devra se reconnecter.`)) {
    load()
    return
  }
  update(member, { role }, 'Rôle changé.')
}

function toggle(member) {
  const next = member.status === 'active' ? 'suspended' : 'active'
  if (next === 'suspended' && !window.confirm(`Suspendre ${member.name} ? Il ne pourra plus se connecter à la console.`)) return
  update(member, { status: next }, next === 'active' ? 'Compte réactivé.' : 'Compte suspendu.')
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Lien copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le lien à la main.')
  }
}

onMounted(async () => {
  try {
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
})
</script>
