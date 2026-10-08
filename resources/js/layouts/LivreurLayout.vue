<template>
  <div class="min-h-screen bg-gray-100">
    <header class="bg-blue-900 text-white sticky top-0 z-30">
      <div class="max-w-xl mx-auto px-4 py-2 flex items-center gap-2">
        <RouterLink to="/livreur" class="font-bold">🛵 Mes missions</RouterLink>
        <div class="ml-auto flex items-center gap-1">
          <NotificationBell />
          <button class="text-sm bg-white/10 rounded px-3 py-1.5" @click="logout">Quitter</button>
        </div>
      </div>
    </header>
    <main class="max-w-xl mx-auto p-3">
      <RouterView />
    </main>
    <Toasts />
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useRealtime } from '../composables/useRealtime'
import NotificationBell from '../components/NotificationBell.vue'
import Toasts from '../components/Toasts.vue'

const auth = useAuthStore()
const router = useRouter()

useRealtime()

const logout = async () => {
  await auth.logout()
  router.push('/login')
}
</script>
