<template>
  <MobileLayout base="/livreur" color="#1d4ed8" app-name="Mes missions" :tabs="tabs" />
</template>

<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import http from '../../bootstrap/axios'
import MobileLayout from '../../components/mobile/MobileLayout.vue'
import { RouteIcon, UserIcon, WalletIcon } from '../../components/mobile/icons'
import { currentPosition } from '../../composables/useGeolocation'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

// Position envoyée toutes les 30 secondes pendant le service, quel que soit l'écran ouvert
// (carte des livreurs du dispatch)
let locationTimer
async function sendLocation() {
  if (!auth.user?.courier?.is_available || document.hidden) return
  const position = await currentPosition(5000, true)
  if (position) http.patch('/courier/status', position).catch(() => {})
}

onMounted(() => {
  sendLocation()
  locationTimer = setInterval(sendLocation, 30000)
})
onBeforeUnmount(() => clearInterval(locationTimer))

const tabs = [
  { to: '/livreur', label: 'Missions', icon: RouteIcon, exact: true },
  { to: '/livreur/caisse', label: 'Caisse', icon: WalletIcon },
  { to: '/livreur/profil', label: 'Profil', icon: UserIcon },
]
</script>
