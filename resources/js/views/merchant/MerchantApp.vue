<template>
  <MerchantShell base="/marchand" :color="spaceColor('marchand')" :app-name="shopName" :initials="initials" :tabs="tabs" :action-count="toDecide" />
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import MerchantShell from '../../components/mobile/MerchantShell.vue'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import { useAuthStore } from '../../stores/auth'
import { cachedGet } from '../../composables/useCachedApi'
import { orderChanges } from '../../composables/useRealtime'

const auth = useAuthStore()

const shopName = computed(() => auth.user?.merchant?.business_name || 'Mes livraisons')
// « Boutique Chic Abidjan » → « BC »
const initials = computed(() => shopName.value.split(/\s+/).filter((w) => /^[\p{L}\d]/u.test(w)).slice(0, 2).map((w) => w[0].toUpperCase()).join(''))

// Bouton du milieu : une nouvelle course, un message WhatsApp collé, plusieurs courses ou un fichier Excel.
// Une seule icône (le colis) pour les courses ; « color » est l'icône en couleur de l'onglet actif.
const tabs = computed(() => [
  { to: '/marchand', label: 'Accueil', icon: 'home', color: 'accueil', exact: true },
  { to: '/marchand/courses', label: 'Courses', icon: 'box', color: 'colis', exact: true },
  ...(auth.can('orders.create') ? [{
    to: '/marchand/courses/nouvelle',
    label: 'Nouvelle course',
    sheetTitle: 'Que voulez-vous envoyer ?',
    primary: true,
    menu: [
      { to: '/marchand/courses/nouvelle', color: 'colis', label: 'Nouvelle course', text: 'Un client, une adresse, un montant' },
      { to: '/marchand/courses/nouvelle?coller=1', color: 'coller', label: 'Coller un message WhatsApp', text: 'Jibiat remplit la course pour vous' },
      { to: '/marchand/courses/plusieurs', color: 'colis', label: 'Plusieurs courses', text: 'Plusieurs clients d\'un coup, ou une liste collée' },
      { to: '/marchand/courses/import', color: 'excel', label: 'Importer un fichier Excel', text: 'Beaucoup de courses d\'un coup' },
    ],
  }] : []),
  ...(auth.can('finance.view') ? [{ to: '/marchand/paiements', label: 'Argent', icon: 'wallet', color: 'argent' }] : []),
  { to: '/marchand/profil', label: 'Moi', icon: 'user', color: 'profil' },
])

// Colis qui attendent une décision : seul nombre affiché sur la cloche
const toDecide = ref(0)
async function loadToDecide() {
  try {
    const { data } = await cachedGet('/orders', { params: { queue: 'to_decide', per_page: 1 } })
    toDecide.value = data.meta?.total ?? data.data.length
  } catch {
    // Hors ligne : la cloche garde son dernier nombre
  }
}
watch(orderChanges, loadToDecide)
onMounted(loadToDecide)

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
</script>
