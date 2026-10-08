<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-100 px-4">
    <form
      class="w-full max-w-sm bg-white rounded-xl shadow p-6 space-y-4"
      @submit.prevent="submit"
    >
      <div class="text-center">
        <div class="text-4xl">🚚</div>
        <h1 class="text-xl font-bold mt-2">Connexion</h1>
      </div>

      <div
        v-if="error"
        class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2"
        role="alert"
      >
        {{ error }}
      </div>

      <label class="block">
        <span class="text-sm text-gray-700">Téléphone ou e-mail</span>
        <input
          v-model.trim="login"
          type="text"
          autocomplete="username"
          inputmode="email"
          placeholder="07 00 00 00 00"
          required
          class="mt-1 w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-400"
        >
      </label>

      <label class="block">
        <span class="text-sm text-gray-700">Mot de passe</span>
        <input
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
          class="mt-1 w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-400"
        >
      </label>

      <button
        type="submit"
        :disabled="loading"
        class="w-full bg-slate-900 hover:bg-slate-700 disabled:opacity-60 text-white rounded py-2 font-medium"
      >
        {{ loading ? 'Connexion…' : 'Se connecter' }}
      </button>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { apiErrorMessage } from '../bootstrap/axios'

const login = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const submit = async () => {
  error.value = ''
  loading.value = true

  try {
    await auth.login({ login: login.value, password: password.value })
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
    router.replace(redirect || auth.homeRoute)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Connexion impossible. Vérifiez votre réseau.')
  } finally {
    loading.value = false
  }
}
</script>
