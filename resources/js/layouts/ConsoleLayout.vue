<template>
  <div class="min-h-screen bg-slate-100">
    <header class="bg-slate-950 text-white">
      <div class="mx-auto max-w-6xl px-4 py-3 flex flex-wrap items-center justify-between gap-2">
        <RouterLink to="/console" class="font-bold">🛰️ Console de la plateforme</RouterLink>
        <div class="flex items-center gap-3 text-sm">
          <span class="text-slate-300">{{ auth.user?.name }}</span>
          <button class="rounded bg-white/10 px-3 py-1.5 hover:bg-white/20" @click="logout">Déconnexion</button>
        </div>
      </div>
    </header>
    <main class="mx-auto max-w-6xl p-4 md:p-6">
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  router.push('/admin/connexion')
}
</script>
