<template>
  <div class="flex h-screen bg-gray-100">
    <aside
      :class="[
        'fixed md:relative z-40 w-64 h-full bg-slate-900 text-white transition-all duration-300 flex flex-col',
        sidebarOpen ? 'left-0' : '-left-64 md:left-0'
      ]"
    >
      <div class="p-4 text-lg font-bold border-b border-slate-700">
        🚚 {{ auth.user?.company?.name || 'Administration' }}
      </div>
      <nav class="p-3 space-y-1 flex-1 overflow-y-auto" @click="sidebarOpen = false">
        <SidebarItem v-for="item in menu" :key="item.to" :icon="item.icon" :label="item.label" :to="item.to" />
      </nav>
    </aside>

    <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 z-30 md:hidden" @click="sidebarOpen = false" />

    <div class="flex-1 flex flex-col min-w-0">
      <header class="flex items-center justify-between bg-white shadow px-4 py-2 gap-2">
        <div class="flex items-center gap-2">
          <button class="md:hidden p-2 rounded bg-slate-900 text-white" aria-label="Menu" @click="sidebarOpen = true">☰</button>
          <form class="hidden md:block" @submit.prevent="quickSearch">
            <input v-model="search" type="search" placeholder="Code, téléphone…" class="input w-64" aria-label="Rechercher une course">
          </form>
        </div>
        <div class="flex items-center gap-3">
          <NotificationBell />
          <span class="hidden sm:inline text-sm text-gray-600">
            {{ auth.user?.name }} <span class="text-gray-400">· {{ auth.user?.role_label }}</span>
          </span>
          <button class="btn-danger" @click="logout">Déconnexion</button>
        </div>
      </header>

      <main class="flex-1 overflow-y-auto p-4 md:p-6">
        <RouterView />
      </main>
    </div>
    <Toasts />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useRealtime } from '../composables/useRealtime'
import SidebarItem from '../components/SidebarItem.vue'
import NotificationBell from '../components/NotificationBell.vue'
import Toasts from '../components/Toasts.vue'

const sidebarOpen = ref(false)
const search = ref('')
const auth = useAuthStore()
const router = useRouter()

useRealtime()

const menu = computed(() => [
  { to: '/admin', icon: '🏠', label: 'Tableau de bord' },
  { to: '/admin/courses', icon: '📦', label: 'Courses', permission: 'orders.view' },
  { to: '/admin/courses/nouvelle', icon: '➕', label: 'Nouvelle course', permission: 'orders.create' },
  { to: '/admin/marchands', icon: '🏪', label: 'E-commerçants', permission: 'merchants.view' },
  { to: '/admin/livreurs', icon: '🛵', label: 'Livreurs', permission: 'orders.dispatch' },
  { to: '/admin/utilisateurs', icon: '👥', label: 'Utilisateurs', permission: 'users.view' },
  { to: '/admin/zones', icon: '🗺️', label: 'Zones', permission: 'settings.manage' },
  { to: '/admin/tarifs', icon: '🏷️', label: 'Tarifs', permission: 'settings.manage' },
  { to: '/admin/caisse', icon: '💰', label: 'Caisse', permission: 'finance.view' },
  { to: '/admin/parametres', icon: '⚙️', label: 'Paramètres', permission: 'settings.manage' },
].filter((item) => !item.permission || auth.can(item.permission)))

function quickSearch() {
  router.push({ path: '/admin/courses', query: { search: search.value } })
  search.value = ''
}

const logout = async () => {
  await auth.logout()
  router.push('/login')
}
</script>
