<template>
  <MerchantShell base="/marchand" :color="spaceColor('marchand')" :app-name="shopName" :initials="initials" :tabs="tabs" :can-create="auth.can('orders.create')" />
</template>

<script setup>
import { computed } from 'vue'
import MerchantShell from '../../components/mobile/MerchantShell.vue'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

const shopName = computed(() => auth.user?.merchant?.business_name || 'Mes livraisons')
// « Boutique Chic Abidjan » → « BC »
const initials = computed(() => shopName.value.split(/\s+/).filter((w) => /^[\p{L}\d]/u.test(w)).slice(0, 2).map((w) => w[0].toUpperCase()).join(''))

// Le bouton flottant « Nouvelle course » remplace l'ancien bouton rond du milieu ;
// coller un message est en haut de la nouvelle course, l'import Excel dans « Moi »
const tabs = computed(() => [
  { to: '/marchand', label: 'Accueil', icon: 'home', exact: true },
  { to: '/marchand/courses', label: 'Courses', icon: 'box', exact: true },
  ...(auth.can('finance.view') ? [{ to: '/marchand/paiements', label: 'Argent', icon: 'wallet' }] : []),
  { to: '/marchand/profil', label: 'Moi', icon: 'user' },
])

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
</script>
