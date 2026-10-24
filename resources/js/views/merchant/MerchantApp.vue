<template>
  <MerchantShell base="/marchand" :color="spaceColor('marchand')" :app-name="shopName" :initials="initials" :tabs="tabs" />
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

// Bouton rond du milieu : une nouvelle course ou un fichier Excel
const tabs = computed(() => [
  { to: '/marchand', label: 'Accueil', icon: 'home', exact: true },
  { to: '/marchand/courses', label: 'Courses', icon: 'box', exact: true },
  ...(auth.can('orders.create') ? [{
    to: '/marchand/courses/nouvelle',
    label: 'Nouvelle course',
    primary: true,
    menu: [
      { to: '/marchand/courses/nouvelle', icon: 'plus', label: 'Une nouvelle course', text: 'Le client, la commune, le montant' },
      { to: '/marchand/courses/import', icon: 'upload', label: 'Importer un fichier Excel', text: 'Beaucoup de courses d\'un coup' },
    ],
  }] : []),
  ...(auth.can('finance.view') ? [{ to: '/marchand/paiements', label: 'Argent', icon: 'wallet' }] : []),
  { to: '/marchand/profil', label: 'Moi', icon: 'user' },
])

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
</script>
