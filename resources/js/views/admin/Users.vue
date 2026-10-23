<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Utilisateurs</h1>
      <div class="flex gap-2">
        <select v-model="role" class="input w-auto" @change="load">
          <option value="">Tous les rôles</option>
          <option v-for="r in assignable" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        <button v-if="auth.can('users.manage')" class="btn-primary" @click="openForm()">+ Nouveau</button>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Nom</th><th class="p-2">Téléphone</th><th class="p-2">E-mail</th><th class="p-2">Rôle</th><th class="p-2">Statut</th><th class="p-2">Dernière connexion</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="u in users" :key="u.id">
            <td class="p-2 font-medium">{{ u.name }}</td>
            <td data-label="Téléphone" class="p-2">{{ u.phone }}</td>
            <td data-label="E-mail" class="p-2">{{ u.email || '—' }}</td>
            <td data-label="Rôle" class="p-2">{{ u.role_label }}</td>
            <td data-label="Statut" class="p-2">
              <span :class="u.status === 'active' ? 'text-emerald-700' : 'text-red-600'">{{ u.status === 'active' ? 'Actif' : 'Suspendu' }}</span>
              <span v-if="u.has_password === false" class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800" title="Se connecte par WhatsApp ou par lien d'invitation">sans mot de passe</span>
            </td>
            <td data-label="Dernière connexion" class="p-2 text-xs text-gray-500">{{ dateTime(u.last_login_at) }}</td>
            <td class="p-2 text-right whitespace-nowrap space-x-3">
              <template v-if="auth.can('users.manage') && u.id !== auth.user.id && !u.role?.startsWith('merchant')">
                <button class="text-emerald-700" :disabled="inviting === u.id" title="Lien de connexion à envoyer par WhatsApp" @click="invite(u)">{{ inviting === u.id ? '…' : 'Inviter' }}</button>
                <button class="text-blue-600" @click="openForm(u)">Modifier</button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal :open="form.open" :title="form.id ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div v-if="form.error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ form.error }}</div>
        <div><label class="label">Nom *</label><input v-model="form.data.name" class="input" required></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Téléphone *</label><input v-model="form.data.phone" class="input" required inputmode="tel"></div>
          <div><label class="label">E-mail</label><input v-model="form.data.email" type="email" class="input"></div>
        </div>
        <div>
          <label class="label">Rôle *</label>
          <select v-model="form.data.role" class="input" required>
            <option v-for="r in assignable" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </div>
        <div v-if="form.id">
          <label class="label">Statut</label>
          <select v-model="form.data.status" class="input">
            <option value="active">Actif</option>
            <option value="suspended">Suspendu (déconnecté de tous ses appareils)</option>
          </select>
        </div>
        <div>
          <label class="label">{{ form.id ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe (facultatif, 8 caractères min.)' }}</label>
          <input v-model="form.data.password" type="text" class="input" autocomplete="new-password" :placeholder="form.id ? '' : 'Vide : un lien d\'invitation à envoyer par WhatsApp'">
          <p v-if="!form.id" class="mt-1 text-xs text-gray-500">Sans mot de passe, vous recevez un lien personnel à envoyer par WhatsApp : la personne arrive connectée et choisit son mot de passe si elle veut.</p>
        </div>
        <button class="btn-primary w-full" :disabled="form.saving">{{ form.id || form.data.password ? 'Enregistrer' : 'Créer et préparer l\'invitation' }}</button>
      </form>
    </Modal>

    <!-- Lien d'invitation : l'administrateur l'envoie depuis son propre WhatsApp (aucun SMS) -->
    <Modal :open="!!invitation" title="Invitation à envoyer" @close="invitation = null">
      <div v-if="invitation" class="space-y-3 text-sm">
        <p>Envoyez ce lien à <strong>{{ invitation.user.name }}</strong> ({{ invitation.user.phone }}). Il est personnel, ne sert qu'une fois et expire le {{ dateTime(invitation.expires_at) }}. En l'ouvrant, la personne arrive connectée dans son espace.</p>
        <a :href="invitation.whatsapp_link" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 w-full rounded-xl bg-[#25D366] hover:bg-[#1ebe5b] py-3 font-semibold text-white">
          💬 Envoyer par WhatsApp
        </a>
        <div class="flex gap-2">
          <input :value="invitation.url" readonly class="input flex-1 text-xs" @focus="$event.target.select()">
          <button type="button" class="btn-secondary" @click="copy(invitation.url)">Copier</button>
        </div>
        <p class="text-xs text-gray-500">Pas de WhatsApp ? Donnez-lui le lien autrement, ou renseignez un mot de passe dans « Modifier ».</p>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const users = ref([])
const assignable = ref([])
const role = ref('')
const form = reactive({ open: false, id: null, data: {}, error: '', saving: false })
const invitation = ref(null)
const inviting = ref(null)

async function load() {
  users.value = (await http.get('/users', { params: { role: role.value || undefined, per_page: 100 } })).data.data
}

function openForm(user = null) {
  form.id = user?.id ?? null
  form.data = {
    name: user?.name ?? '',
    phone: user?.phone ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'courier',
    status: user?.status ?? 'active',
    password: '',
  }
  form.error = ''
  form.open = true
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const payload = Object.fromEntries(Object.entries(form.data).filter(([k, v]) => v !== '' && !(k === 'status' && !form.id)))
    if (!form.data.email && form.id) payload.email = null
    if (form.id) {
      await http.patch(`/users/${form.id}`, payload)
    } else {
      const { data } = await http.post('/users', payload)
      // Créé sans mot de passe : le lien d'invitation est prêt à envoyer
      if (data.invitation) invitation.value = { ...data.invitation, user: data.data }
    }
    form.open = false
    toasts.success('Utilisateur enregistré.')
    load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

async function invite(user) {
  inviting.value = user.id
  try {
    const { data } = await http.post(`/users/${user.id}/invitation`)
    invitation.value = { ...data.data, user }
  } catch (e) {
    toasts.error(apiErrorMessage(e, "L'invitation n'a pas pu être préparée."))
  } finally {
    inviting.value = null
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Lien copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le lien et copiez-le.')
  }
}

onMounted(async () => {
  load()
  assignable.value = (await http.get('/roles')).data.data
})
</script>
