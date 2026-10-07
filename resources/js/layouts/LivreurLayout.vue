<template>
  <div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside class="w-64 bg-blue-900 text-white p-4">
      <h2 class="text-xl font-bold mb-6">Espace Livreur</h2>

      <ul class="space-y-2">
        <li>
          <a href="#" class="block p-2 rounded hover:bg-blue-700">
            Mes courses
          </a>
        </li>
        <li>
          <a href="#" class="block p-2 rounded hover:bg-blue-700">
            Carte & Position
          </a>
        </li>
        <li>
          <a href="#" class="block p-2 rounded hover:bg-blue-700">
            Mon profil
          </a>
        </li>
      </ul>

      <button
        @click="logout"
        class="mt-6 w-full bg-red-500 hover:bg-red-600 p-2 rounded"
      >
        Déconnexion
      </button>
    </aside>

    <!-- Main -->
    <main class="flex-1 p-6 bg-gray-100">
      <h1 class="text-2xl font-bold mb-4">Bienvenue Livreur</h1>
      <p>Ici tu verras tes courses assignées, ton état et ta progression.</p>
    </main>
  </div>
</template>

<script setup>
import { useAuthStore } from '../stores/auth'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()

const logout = async () => {
  await auth.logout()
  router.push('/login')
}
</script>
