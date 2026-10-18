<template>
  <form class="space-y-4" @submit.prevent="submit">
    <div v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2 space-y-2" role="alert">
      <p>{{ error }}</p>
      <!-- Compte d'un autre espace : lien vers sa page de connexion (sauf dans une application limitée à un espace) -->
      <RouterLink v-if="otherSpace" :to="otherSpace.to" class="inline-block rounded-lg bg-white px-3 py-1.5 font-semibold text-red-800 ring-1 ring-red-200">
        {{ otherSpace.label }} →
      </RouterLink>
    </div>

    <label class="block">
      <span class="text-sm font-medium text-slate-700">Téléphone ou e-mail</span>
      <input
        v-model.trim="login"
        type="text"
        autocomplete="username"
        inputmode="email"
        placeholder="07 00 00 00 00"
        required
        :class="['mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:border-transparent', ring]"
      >
    </label>

    <label class="block">
      <span class="text-sm font-medium text-slate-700">Mot de passe</span>
      <span class="relative mt-1 block">
        <input
          v-model="password"
          :type="showPassword ? 'text' : 'password'"
          autocomplete="current-password"
          required
          :class="['w-full rounded-xl border border-slate-300 bg-white px-4 py-3 pr-12 text-base focus:outline-none focus:ring-2 focus:border-transparent', ring]"
        >
        <button type="button" class="absolute inset-y-0 right-0 px-3 text-slate-500" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">
          {{ showPassword ? '🙈' : '👁️' }}
        </button>
      </span>
    </label>

    <p class="text-right -mt-2">
      <RouterLink :to="{ path: '/mot-de-passe-oublie', query: { espace: tone } }" class="text-sm text-slate-600 hover:underline">Mot de passe oublié ?</RouterLink>
    </p>

    <button type="submit" :disabled="loading" :class="['w-full rounded-xl py-3 font-semibold text-white shadow-sm disabled:opacity-60 transition', button]">
      {{ loading ? 'Connexion…' : 'Se connecter' }}
    </button>
  </form>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { apiErrorMessage } from '../../bootstrap/axios'
import { LOGIN_PAGES, SPACE_FROM_SERVER } from '../../bootstrap/session'
import { appLock } from '../../native'

const props = defineProps({
  // merchant (vert), staff (ardoise), courier (bleu)
  tone: { type: String, default: 'merchant' },
})

const TONES = {
  merchant: { ring: 'focus:ring-emerald-500', button: 'bg-emerald-600 hover:bg-emerald-700' },
  staff: { ring: 'focus:ring-slate-500', button: 'bg-slate-900 hover:bg-slate-700' },
  courier: { ring: 'focus:ring-blue-500', button: 'bg-blue-700 hover:bg-blue-800' },
}
const ring = computed(() => TONES[props.tone].ring)
const button = computed(() => TONES[props.tone].button)

const login = ref('')
const password = ref('')
const showPassword = ref(false)
const error = ref('')
const otherSpace = ref(null)
const loading = ref(false)
const SPACE_LABELS = { marchand: 'Aller à l\'espace e-commerçant', livreur: 'Aller à l\'espace livreur', admin: 'Aller à l\'espace équipe' }

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

async function submit() {
  error.value = ''
  otherSpace.value = null
  loading.value = true
  try {
    await auth.login({ login: login.value, password: password.value })
    // Chacun arrive dans son espace, quelle que soit la page de connexion utilisée
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith(auth.homeRoute) ? route.query.redirect : null
    router.replace(redirect || auth.homeRoute)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Connexion impossible. Vérifiez votre réseau.')
    const space = SPACE_FROM_SERVER[e.response?.data?.space]
    if (space && !appLock()) otherSpace.value = { to: LOGIN_PAGES[space], label: SPACE_LABELS[space] }
  } finally {
    loading.value = false
  }
}
</script>
