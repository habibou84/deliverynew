<template>
  <MobileLayout base="/marchand" :color="spaceColor('marchand')" :app-name="auth.user?.merchant?.business_name || 'Mes livraisons'" :tabs="tabs" />
</template>

<script setup>
import { computed } from 'vue'
import MobileLayout from '../../components/mobile/MobileLayout.vue'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import { useAuthStore } from '../../stores/auth'
import { BoxIcon, HomeIcon, UserIcon, WalletIcon } from '../../components/mobile/icons'

const auth = useAuthStore()

const tabs = computed(() => [
  { to: '/marchand', label: 'Accueil', icon: HomeIcon, exact: true },
  { to: '/marchand/courses', label: 'Courses', icon: BoxIcon, exact: true },
  { to: '/marchand/courses/nouvelle', label: 'Nouvelle course', primary: true },
  ...(auth.can('finance.view') ? [{ to: '/marchand/paiements', label: 'Paiements', icon: WalletIcon }] : []),
  { to: '/marchand/profil', label: 'Profil', icon: UserIcon },
])

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
</script>
