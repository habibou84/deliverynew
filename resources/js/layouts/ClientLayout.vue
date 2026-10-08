<template>
  <div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside class="w-64 bg-green-800 text-white p-4">
      <h2 class="text-xl font-bold mb-6">Espace e-commerçant</h2>

      <ul class="space-y-2">
        <li>
          <a href="#" class="block p-2 rounded hover:bg-green-700">
            Nouvelle commande
          </a>
        </li>
        <li>
          <a href="#" class="block p-2 rounded hover:bg-green-700">
            Mes commandes
          </a>
        </li>
        <li>
          <a href="#" class="block p-2 rounded hover:bg-green-700">
            Paiements
          </a>
        </li>
        <li>
          <a href="#" class="block p-2 rounded hover:bg-green-700">
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
      <h1 class="text-2xl font-bold mb-4">Bienvenue {{ auth.user?.name }}</h1>
      <p>Ici vous pourrez créer et suivre vos courses de livraison.</p>
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
