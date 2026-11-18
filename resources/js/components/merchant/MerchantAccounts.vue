<template>
  <!-- Comptes de connexion d'une boutique : numéro et mot de passe, réglés par l'agence -->
  <div class="space-y-3">
    <p class="text-sm text-slate-600">
      Numéro et mot de passe avec lesquels l'e-commerçant se connecte. Un nouveau mot de passe le déconnecte de tous ses appareils.
    </p>
    <p v-if="loading" class="text-sm text-slate-600">Chargement…</p>
    <p v-else-if="!accounts.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Aucun compte de connexion pour cette boutique. Créez-en un ci-dessous.</p>

    <form v-for="a in accounts" :key="a.id" class="space-y-2 rounded-xl border p-3" @submit.prevent="save(a)">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="font-semibold">{{ a.name }} <span class="text-xs font-normal text-slate-600">· {{ a.role === 'merchant_owner' ? 'gérant' : 'employé' }}</span></p>
        <p class="text-xs text-slate-600">
          {{ a.status === 'suspended' ? '⛔ suspendu · ' : '' }}{{ a.has_password ? 'mot de passe défini' : 'connexion par WhatsApp (pas de mot de passe)' }}
          · {{ a.last_login_at ? `dernière connexion ${dateTime(a.last_login_at)}` : 'jamais connecté' }}
        </p>
      </div>
      <div class="grid gap-2 sm:grid-cols-2">
        <div>
          <label class="label" :for="`acc-phone-${a.id}`">Téléphone de connexion</label>
          <input :id="`acc-phone-${a.id}`" v-model="a.edit.phone" class="input" required inputmode="tel" autocomplete="off">
        </div>
        <div>
          <label class="label" :for="`acc-pass-${a.id}`">Nouveau mot de passe</label>
          <input :id="`acc-pass-${a.id}`" v-model="a.edit.password" type="text" class="input" minlength="8" placeholder="Laisser vide pour ne pas changer" autocomplete="new-password">
        </div>
      </div>
      <p v-if="a.role === 'merchant_owner' && a.edit.phone !== a.phone" class="text-xs text-slate-600">Le numéro de la boutique sera aussi mis à jour s'il était le même.</p>
      <p v-if="a.error" class="field-error">{{ a.error }}</p>
      <div class="flex justify-end">
        <button class="btn-primary" :disabled="a.saving || (a.edit.phone === a.phone && !a.edit.password)">{{ a.saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </div>
    </form>

    <!-- Nouveau compte -->
    <details :open="!loading && !accounts.length" class="rounded-xl border p-3">
      <summary class="cursor-pointer text-sm font-semibold">+ Ajouter un compte de connexion</summary>
      <form class="mt-3 space-y-2" @submit.prevent="create">
        <div class="grid gap-2 sm:grid-cols-2">
          <div><label class="label" for="acc-new-name">Nom *</label><input id="acc-new-name" v-model="fresh.name" class="input" required></div>
          <div><label class="label" for="acc-new-phone">Téléphone *</label><input id="acc-new-phone" v-model="fresh.phone" class="input" required inputmode="tel"></div>
          <div><label class="label" for="acc-new-pass">Mot de passe * (8 caractères min.)</label><input id="acc-new-pass" v-model="fresh.password" type="text" class="input" minlength="8" required autocomplete="new-password"></div>
          <div>
            <label class="label" for="acc-new-role">Rôle</label>
            <select id="acc-new-role" v-model="fresh.role" class="input">
              <option value="merchant_owner">Gérant</option>
              <option value="merchant_staff">Employé</option>
            </select>
          </div>
        </div>
        <p v-if="fresh.error" class="field-error">{{ fresh.error }}</p>
        <div class="flex justify-end"><button class="btn-primary" :disabled="fresh.saving">Créer le compte</button></div>
      </form>
    </details>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const props = defineProps({ merchant: { type: Object, required: true } })
const emit = defineEmits(['changed'])
const toasts = useToastStore()

const accounts = ref([])
const loading = ref(true)
const fresh = reactive({ name: '', phone: '', password: '', role: 'merchant_owner', error: '', saving: false })

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/users', { params: { merchant_id: props.merchant.id, per_page: 100 } })
    accounts.value = data.data.map((u) => ({ ...u, edit: { phone: u.phone, password: '' }, error: '', saving: false }))
  } finally {
    loading.value = false
  }
}

async function save(a) {
  a.saving = true
  a.error = ''
  try {
    const payload = {}
    if (a.edit.phone !== a.phone) payload.phone = a.edit.phone
    if (a.edit.password) payload.password = a.edit.password
    await http.put(`/users/${a.id}`, payload)
    toasts.success(payload.password ? 'Compte mis à jour : l\'e-commerçant doit se reconnecter.' : 'Numéro mis à jour.')
    emit('changed')
    await load()
  } catch (e) {
    a.error = apiErrorMessage(e)
  } finally {
    a.saving = false
  }
}

async function create() {
  fresh.saving = true
  fresh.error = ''
  try {
    await http.post(`/merchants/${props.merchant.id}/users`, { name: fresh.name, phone: fresh.phone, password: fresh.password, role: fresh.role })
    toasts.success('Compte créé.')
    Object.assign(fresh, { name: '', phone: '', password: '', role: 'merchant_owner' })
    emit('changed')
    await load()
  } catch (e) {
    fresh.error = apiErrorMessage(e)
  } finally {
    fresh.saving = false
  }
}

onMounted(() => {
  fresh.name = props.merchant.contact_name || ''
  load()
})
</script>
