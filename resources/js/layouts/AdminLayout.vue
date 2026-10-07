<template>
  <div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <aside
      :class="[
        'fixed md:relative z-40 w-64 h-full bg-slate-900 text-white transition-all duration-300',
        sidebarOpen ? 'left-0' : '-left-64'
      ]"
    >
      <div class="p-4 text-xl font-bold border-b border-slate-700">
        🚚 Delivery Admin
      </div>

      <nav class="p-4 space-y-2">
        <SidebarItem icon="🏠" label="Dashboard" />
        <SidebarItem icon="📦" label="Courses" />
        <SidebarItem icon="👥" label="Clients" />
        <SidebarItem icon="🚚" label="Livreurs" />
        <SidebarItem icon="💰" label="Comptabilité" />
        <SidebarItem icon="🗺️" label="Carte Live" />
        <SidebarItem icon="🔔" label="Notifications" />
        <SidebarItem icon="⚙️" label="Paramètres" />
      </nav>
    </aside>

    <!-- Overlay mobile -->
    <div
      v-if="sidebarOpen"
      @click="sidebarOpen = false"
      class="fixed inset-0 bg-black/40 z-30 md:hidden"
    ></div>

    <!-- Main content -->
    <div class="flex-1 flex flex-col">
      <!-- Topbar -->
      <header class="flex items-center justify-between bg-white shadow px-4 py-2">
        <div class="flex items-center gap-2">
          <button
            class="md:hidden p-2 rounded bg-slate-900 text-white"
            @click="sidebarOpen = true"
          >
            ☰
          </button>
          <h1 class="font-bold text-lg">Administration</h1>
        </div>

        <div class="flex items-center gap-4">
          <input
            type="text"
            placeholder="Rechercher..."
            class="hidden md:block border rounded px-2 py-1"
          />
          <span class="text-sm text-gray-600">{{ auth.user?.name }}</span>
          <button
            @click="logout"
            class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded"
          >
            Déconnexion
          </button>
        </div>
      </header>

      <!-- Page content -->
      <main class="flex-1 overflow-y-auto p-6">
        <!-- Exemple de dashboard -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
          <StatCard title="Total Courses" value="128" icon="📦" />
          <StatCard title="En cours" value="12" icon="⏳" />
          <StatCard title="Livrées" value="95" icon="✅" />
          <StatCard title="Recettes" value="350 000 FCFA" icon="💰" />
          <StatCard title="Encaissements" value="280 000 FCFA" icon="🏦" />
        </div>

        <div class="bg-white p-6 rounded shadow">
          <h2 class="text-xl font-bold mb-2">Bienvenue dans l’interface Admin</h2>
          <p class="text-gray-600">
            Ici tu piloteras toutes les courses, les livreurs, les clients, les paiements
            et la carte en temps réel.
          </p>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useRouter } from 'vue-router'

const sidebarOpen = ref(false)
const auth = useAuthStore()
const router = useRouter()

const logout = async () => {
  await auth.logout()
  router.push('/login')
}
</script>

<!-- Composants locaux -->
<script>
export default {
  components: {
    SidebarItem: {
      props: ['icon', 'label'],
      template: `
        <a href="#" class="flex items-center gap-2 p-2 rounded hover:bg-slate-700">
          <span>{{ icon }}</span>
          <span>{{ label }}</span>
        </a>
      `
    },
    StatCard: {
      props: ['title', 'value', 'icon'],
      template: `
        <div class="bg-white p-4 rounded shadow flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-500">{{ title }}</p>
            <p class="text-xl font-bold">{{ value }}</p>
          </div>
          <div class="text-3xl">{{ icon }}</div>
        </div>
      `
    }
  }
}
</script>
