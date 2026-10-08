<template>
  <MobileLayout base="/livreur" color="#1d4ed8" app-name="Mes missions" :tabs="tabs" />
</template>

<script setup>
import MobileLayout from '../../components/mobile/MobileLayout.vue'
import { RouteIcon, UserIcon, WalletIcon } from '../../components/mobile/icons'

const tabs = [
  { to: '/livreur', label: 'Missions', icon: RouteIcon, exact: true },
  { to: '/livreur/caisse', label: 'Caisse', icon: WalletIcon },
  { to: '/livreur/profil', label: 'Profil', icon: UserIcon },
]
</script>
