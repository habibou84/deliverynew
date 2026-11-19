<template>
  <!-- Compte de connexion du livreur : nom, téléphone, e-mail, mot de passe, lien de connexion, suspendre / réactiver -->
  <div class="space-y-4">
    <p v-if="!user" class="text-sm text-slate-600">Chargement…</p>
    <template v-else>
      <form class="space-y-3" @submit.prevent="save">
        <div v-if="error" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
        <div><label class="label" for="ca-name">Nom *</label><input id="ca-name" v-model="form.name" class="input" required></div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label class="label" for="ca-phone">Téléphone de connexion *</label><input id="ca-phone" v-model="form.phone" class="input" required inputmode="tel"></div>
          <div><label class="label" for="ca-email">E-mail</label><input id="ca-email" v-model="form.email" type="email" class="input"></div>
        </div>
        <div>
          <label class="label" for="ca-password">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
          <div class="flex">
            <input id="ca-password" v-model="form.password" :type="showPassword ? 'text' : 'password'" class="input rounded-r-none" autocomplete="new-password" placeholder="8 caractères min.">
            <button type="button" class="rounded-r-lg border border-l-0 border-slate-300 px-3" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">{{ showPassword ? '🙈' : '👁️' }}</button>
          </div>
          <p class="mt-1 text-xs text-slate-600">Le livreur se connecte avec ce téléphone et ce mot de passe. Après un changement de mot de passe, il devra se reconnecter sur son téléphone.</p>
        </div>
        <button class="btn-primary w-full" :disabled="saving || !changed">{{ saving ? 'Enregistrement…' : 'Enregistrer le compte' }}</button>
      </form>

      <div class="space-y-2 rounded-lg border p-3">
        <p class="font-medium">Lien de connexion</p>
        <p class="text-sm text-slate-600">Mot de passe oublié ? Envoyez-lui un lien personnel : il arrive connecté dans son application et choisit un nouveau mot de passe s'il veut.</p>
        <button v-if="!invitation" type="button" class="btn-secondary" :disabled="busy" @click="invite">Préparer le lien</button>
        <template v-else>
          <a :href="invitation.whatsapp_link" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] py-3 font-semibold text-white hover:bg-[#1ebe5b]">💬 Envoyer par WhatsApp</a>
          <div class="flex gap-2">
            <input :value="invitation.url" readonly class="input flex-1 text-xs" aria-label="Lien de connexion" @focus="$event.target.select()">
            <button type="button" class="btn-secondary" @click="copy">Copier</button>
          </div>
          <p class="text-xs text-slate-600">Il ne sert qu'une fois et expire le {{ dateTime(invitation.expires_at) }}.</p>
        </template>
      </div>

      <div v-if="user.abilities?.access" class="flex flex-wrap items-center gap-3 rounded-lg border p-3">
        <p class="min-w-0 flex-1 text-sm">
          Compte <strong :class="user.status === 'active' ? 'text-emerald-700' : 'text-red-700'">{{ user.status === 'active' ? 'actif' : 'suspendu' }}</strong>
          <span class="block text-xs text-slate-600">{{ user.status === 'active' ? 'Suspendu, il ne peut plus se connecter ni recevoir de missions.' : 'Réactivé, il peut de nouveau se connecter.' }}</span>
        </p>
        <button type="button" :class="user.status === 'active' ? 'btn-secondary text-red-700' : 'btn-primary'" :disabled="busy" @click="toggleStatus">
          {{ user.status === 'active' ? 'Suspendre le compte' : 'Réactiver le compte' }}
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { confirmDialog } from '../composables/useDialog'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'

const props = defineProps({ userId: { type: Number, required: true } })
const emit = defineEmits(['saved'])
const toasts = useToastStore()
const user = ref(null)
const form = reactive({ name: '', phone: '', email: '', password: '' })
const error = ref('')
const saving = ref(false)
const busy = ref(false)
const showPassword = ref(false)
const invitation = ref(null)

const changed = computed(() => !!user.value && (form.name !== user.value.name || form.phone !== user.value.phone || (form.email || null) !== (user.value.email || null) || !!form.password))

async function load() {
  error.value = ''
  invitation.value = null
  try {
    user.value = (await http.get(`/users/${props.userId}`)).data.data
    Object.assign(form, { name: user.value.name, phone: user.value.phone, email: user.value.email || '', password: '' })
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    // Seuls les champs modifiés partent
    const payload = {}
    if (form.name !== user.value.name) payload.name = form.name
    if (form.phone !== user.value.phone) payload.phone = form.phone
    if ((form.email || null) !== (user.value.email || null)) payload.email = form.email || null
    if (form.password) payload.password = form.password
    await http.patch(`/users/${props.userId}`, payload)
    toasts.success('Compte du livreur enregistré.')
    await load()
    emit('saved')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function invite() {
  busy.value = true
  try {
    invitation.value = (await http.post(`/users/${props.userId}/invitation`)).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e, "Le lien n'a pas pu être préparé."))
  } finally {
    busy.value = false
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(invitation.value.url)
    toasts.success('Lien copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le lien.')
  }
}

async function toggleStatus() {
  const suspend = user.value.status === 'active'
  if (suspend && !await confirmDialog(`Suspendre le compte de ${user.value.name} ? Il ne pourra plus se connecter.`)) return
  busy.value = true
  try {
    await http.patch(`/users/${props.userId}`, { status: suspend ? 'suspended' : 'active' })
    toasts.success(suspend ? 'Compte suspendu.' : 'Compte réactivé.')
    await load()
    emit('saved')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

watch(() => props.userId, load, { immediate: true })
</script>
