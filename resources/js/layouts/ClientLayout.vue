<template>
  <div class="min-h-screen bg-gray-100">
    <header class="bg-green-800 text-white">
      <div class="max-w-6xl mx-auto px-4 py-2 flex items-center gap-3">
        <span class="font-bold">🏪 {{ auth.user?.merchant?.business_name || 'Espace e-commerçant' }}</span>
        <nav class="flex gap-1 text-sm overflow-x-auto">
          <RouterLink to="/marchand" exact-active-class="bg-green-700" class="px-3 py-1.5 rounded hover:bg-green-700">Mon point</RouterLink>
          <RouterLink to="/marchand/courses" exact-active-class="bg-green-700" class="px-3 py-1.5 rounded hover:bg-green-700">Mes courses</RouterLink>
          <RouterLink v-if="auth.can('finance.view')" to="/marchand/paiements" active-class="bg-green-700" class="px-3 py-1.5 rounded hover:bg-green-700">Paiements</RouterLink>
          <RouterLink to="/marchand/courses/nouvelle" exact-active-class="bg-green-700" class="px-3 py-1.5 rounded hover:bg-green-700 whitespace-nowrap">+ Nouvelle course</RouterLink>
        </nav>
        <div class="ml-auto flex items-center gap-2">
          <NotificationBell />
          <button class="text-sm bg-white/10 hover:bg-white/20 rounded px-3 py-1.5" @click="logout">Déconnexion</button>
        </div>
      </div>
    </header>
    <main class="max-w-6xl mx-auto p-4">
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
