<template>
  <!-- Téléphone : onglets en haut et pages à glisser (comme Jibiat Marchand) ; ordinateur : affichage habituel -->
  <MerchantSwipeShell
    v-if="phone"
    base="/marchand"
    :color="spaceColor('marchand')"
    :pages="pages"
    :new-menu="newMenu"
    :can-create="auth.can('orders.create')"
    :action-count="toDecide"
  />
  <MerchantShell v-else base="/marchand" :color="spaceColor('marchand')" :app-name="shopName" :initials="initials" :tabs="tabs" :action-count="toDecide" />
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import MerchantShell from '../../components/mobile/MerchantShell.vue'
import MerchantSwipeShell from '../../components/mobile/MerchantSwipeShell.vue'
import Home from './Home.vue'
import Payments from './Payments.vue'
import { h } from 'vue'
import LivePrepare from './live/LivePrepare.vue'
import BoutiqueHub from './BoutiqueHub.vue'
import MorePage from './MorePage.vue'
import { isPhone } from '../../composables/usePhone'
import http from '../../bootstrap/axios'
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

// ── Téléphone : pages à glisser, dans cet ordre ──
const phone = isPhone
const newMenu = [
  { to: '/marchand/courses/nouvelle', color: 'colis', label: 'Nouvelle course', text: 'Un client, une adresse, un montant' },
  { to: '/marchand/courses/nouvelle?coller=1', color: 'coller', label: 'Coller un message WhatsApp', text: 'Jibiat remplit la course pour vous' },
  { to: '/marchand/courses/plusieurs', color: 'colis', label: 'Plusieurs courses', text: 'Plusieurs clients d\'un coup, ou une liste collée' },
  { to: '/marchand/courses/import', color: 'excel', label: 'Importer un fichier Excel', text: 'Beaucoup de courses d\'un coup' },
]
const liveOn = ref(false)
// Onglet Live : la préparation (ou le bandeau du live en cours), sans rien de fixé en bas de l'écran
const LiveTab = { name: 'LiveTab', render: () => h(LivePrepare, { embedded: true }) }
const pages = computed(() => [
  { key: 'orders', label: 'Courses', path: '/marchand', component: Home, emoji: '📦', badge: () => (toDecide.value ? { n: toDecide.value > 9 ? '9+' : toDecide.value, level: 'red' } : null) },
  auth.can('finance.view') && { key: 'money', label: 'Argent', path: '/marchand/paiements', component: Payments, emoji: '💰' },
  auth.can('orders.create') && { key: 'live', label: 'Live', path: '/marchand/live', component: LiveTab, emoji: '🔴', badge: () => (liveOn.value ? { n: '●', level: 'red' } : null) },
  { key: 'shop', label: 'Boutique', path: '/marchand/ma-boutique', component: BoutiqueHub, emoji: '🏪' },
  { key: 'more', label: 'Plus', path: '/marchand/plus', component: MorePage, emoji: '☰' },
].filter(Boolean))
async function loadLive() {
  if (!phone.value || !auth.can('orders.create')) return
  try {
    liveOn.value = (await http.get('/live')).data.data.current?.status === 'live'
  } catch {
    // Sans réseau : pas de pastille
  }
}
onMounted(loadLive)

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
