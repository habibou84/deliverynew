<template>
  <div class="min-h-screen px-4 py-8 bg-slate-900">
    <div class="mx-auto w-full max-w-md">
      <div class="flex justify-center"><BrandLogo size="md" dark /></div>

      <div class="mt-6 rounded-3xl bg-white text-slate-900 shadow-2xl p-6 md:p-8 space-y-5">
        <p v-if="loading" class="text-center text-slate-500">Un instant…</p>

        <div v-else-if="invalid" class="text-center space-y-3">
          <p class="text-3xl" aria-hidden="true">⌛</p>
          <h1 class="text-xl font-bold">Invitation expirée</h1>
          <p class="text-sm text-slate-600">{{ invalid }}</p>
          <RouterLink to="/admin/connexion" class="inline-block text-sm underline text-slate-600">Aller à la connexion</RouterLink>
        </div>

        <form v-else class="space-y-4" @submit.prevent="accept">
          <div class="text-center space-y-1">
            <p class="text-3xl" aria-hidden="true">👋</p>
            <h1 class="text-xl font-bold">Bienvenue, {{ invitation.name }}</h1>
            <p class="text-sm text-slate-600">
              Votre compte <strong>{{ invitation.role_label }}</strong> chez <strong>{{ invitation.company }}</strong> est prêt ({{ invitation.phone }}).
            </p>
          </div>
          <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ error }}</p>

          <div class="rounded-2xl bg-slate-50 p-4 space-y-3">
            <label class="flex items-start gap-3 cursor-pointer">
              <input v-model="withPassword" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300">
              <span class="text-sm">
                <span class="font-semibold text-slate-700">Choisir un mot de passe maintenant (facultatif)</span>
                <span class="block text-slate-500">Sans mot de passe, vous vous connecterez par WhatsApp. Vous pourrez en définir un plus tard.</span>
              </span>
            </label>
            <template v-if="withPassword">
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Mot de passe (8 caractères minimum)</span>
                <input v-model="password" type="password" autocomplete="new-password" required minlength="8" :class="inputClass">
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Confirmez le mot de passe</span>
                <input v-model="passwordConfirmation" type="password" autocomplete="new-password" required minlength="8" :class="inputClass">
              </label>
            </template>
          </div>

          <button type="submit" :disabled="busy" class="w-full rounded-xl bg-slate-900 hover:bg-slate-700 py-3 font-semibold text-white shadow-sm disabled:opacity-60">
            {{ busy ? 'Ouverture…' : 'Ouvrir mon espace' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import { loadBranding } from '../../composables/useBranding'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const inputClass = 'mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-slate-500 focus:border-transparent'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const loading = ref(true)
const invalid = ref('')
const invitation = ref(null)
const withPassword = ref(false)
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const error = ref('')

async function accept() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/invitations/${route.params.token}/accept`, {
      password: withPassword.value ? password.value : null,
      password_confirmation: withPassword.value ? passwordConfirmation.value : null,
      device_name: navigator.userAgent.slice(0, 100),
    })
    await auth.adoptSession(data)
    toasts.success(`Bienvenue chez ${invitation.value.company} !`)
    router.replace(auth.homeRoute)
  } catch (e) {
    error.value = apiErrorMessage(e, "L'invitation n'a pas pu être ouverte.")
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  loadBranding()
  try {
    invitation.value = (await http.get(`/invitations/${route.params.token}`)).data.data
  } catch (e) {
    invalid.value = apiErrorMessage(e, "Cette invitation n'est plus valable.")
  } finally {
    loading.value = false
  }
})
</script>
