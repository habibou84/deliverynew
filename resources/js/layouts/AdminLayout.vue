<template>
  <div class="flex h-screen bg-gray-100">
    <aside
      :class="[
        'fixed md:relative z-40 w-64 h-full bg-slate-900 text-white transition-all duration-300 flex flex-col print:hidden',
        sidebarOpen ? 'left-0' : '-left-64 md:left-0'
      ]"
    >
      <div class="p-4 text-lg font-bold border-b border-slate-700 flex items-center gap-2 min-w-0">
        <img v-if="branding.logo_url" :src="branding.logo_url" alt="" class="h-8 w-8 object-contain shrink-0 rounded bg-white p-0.5">
        <span v-else aria-hidden="true">🚚</span>
        <span class="truncate">{{ auth.user?.company?.name || 'Administration' }}</span>
      </div>
      <nav class="p-3 space-y-1 flex-1 overflow-y-auto" @click="sidebarOpen = false">
        <SidebarItem v-for="item in menu" :key="item.to" :icon="item.icon" :label="item.label" :to="item.to" :badge="item.badge || 0" />
      </nav>
    </aside>

    <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 z-30 md:hidden" @click="sidebarOpen = false" />

    <div class="flex-1 flex flex-col min-w-0">
      <header class="flex items-center justify-between bg-white shadow px-4 py-2 gap-2 print:hidden">
        <div class="flex items-center gap-2">
          <button class="md:hidden p-2 rounded bg-slate-900 text-white" aria-label="Menu" @click="sidebarOpen = true">☰</button>
          <form class="hidden md:block" @submit.prevent="quickSearch">
            <input v-model="search" type="search" placeholder="Code, téléphone…" class="input w-64" aria-label="Rechercher une course">
          </form>
        </div>
        <div class="flex items-center gap-3">
          <AlertSoundToggle v-if="isDispatch" />
          <NotificationBell />
          <span class="hidden sm:inline text-sm text-gray-600">
            {{ auth.user?.name }} <span class="text-gray-400">· {{ auth.user?.role_label }}</span>
          </span>
          <button class="btn-danger" @click="logout">Déconnexion</button>
        </div>
      </header>

      <div v-if="auth.user?.support_session" class="bg-red-600 text-white text-sm px-4 py-2 flex flex-wrap items-center justify-between gap-2" role="status">
        <span>
          🛟 Session d'assistance ouverte par {{ auth.user.support_session.opened_by || 'le super administrateur' }}
          avec le compte de {{ auth.user.name }}<template v-if="auth.user.support_session.expires_at"> · fin à {{ new Date(auth.user.support_session.expires_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}</template>
        </span>
        <button class="rounded bg-white/20 px-3 py-1 font-medium hover:bg-white/30" @click="logout">Terminer la session</button>
      </div>

      <!-- Abonnement : facture de la plateforme en retard (administrateurs) -->
      <RouterLink
        v-if="billingBanner"
        to="/admin/abonnement"
        :class="['block px-4 py-2 text-sm print:hidden', billingBanner.cls]"
      >
        {{ billingBanner.text }} <span class="font-medium underline">Voir mon abonnement</span>
      </RouterLink>

      <main class="flex-1 overflow-y-auto p-4 md:p-6 print:overflow-visible">
        <RouterView />
      </main>
    </div>
    <Toasts />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useRealtime } from '../composables/useRealtime'
import SidebarItem from '../components/SidebarItem.vue'
import NotificationBell from '../components/NotificationBell.vue'
import Toasts from '../components/Toasts.vue'
import AlertSoundToggle from '../components/AlertSoundToggle.vue'
import { useFieldReportStore } from '../stores/fieldReports'
import { useHeldParcelStore } from '../stores/heldParcels'
import { useDispatchCountStore } from '../stores/dispatchCounts'
import { branding, loadBranding } from '../composables/useBranding'

const sidebarOpen = ref(false)
const search = ref('')
const auth = useAuthStore()
const router = useRouter()

useRealtime()
loadBranding()

// Remontées terrain à traiter : badge du menu, rafraîchi chaque minute
// Colis restés trop longtemps chez un livreur : badge du menu
const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()
// Courses qui attendent un livreur au-delà du délai : badge du menu « Courses »
const dispatchCounts = useDispatchCountStore()
const isDispatch = computed(() => auth.can('orders.dispatch'))

// Bandeau d'impayé : échéance passée, alerte (J+7), création de courses suspendue (J+15)
const billingBanner = computed(() => ({
  due: { cls: 'bg-amber-50 text-amber-900', text: 'Une facture de votre abonnement est arrivée à échéance.' },
  warning: { cls: 'bg-amber-500 text-white', text: 'Facture d\'abonnement en retard : sans paiement, la création de courses sera bientôt suspendue.' },
  restricted: { cls: 'bg-red-600 text-white', text: 'Création de courses suspendue : une facture de votre abonnement est impayée. Tout le reste reste consultable.' },
})[auth.user?.company?.billing_state] ?? null)
let countsTimer
function fetchCounts() {
  fieldReports.fetchCounts().catch(() => {})
  heldParcels.fetchCounts().catch(() => {})
  dispatchCounts.fetchCounts().catch(() => {})
}
onMounted(() => {
  if (!isDispatch.value) return
  fetchCounts()
  countsTimer = setInterval(fetchCounts, 60000)
})
onBeforeUnmount(() => clearInterval(countsTimer))

const menu = computed(() => [
  { to: '/admin', icon: '🏠', label: 'Tableau de bord' },
  { to: '/admin/statistiques', icon: '📊', label: 'Statistiques', permission: 'orders.view' },
  { to: '/admin/courses', icon: '📦', label: 'Courses', permission: 'orders.view', badge: isDispatch.value ? dispatchCounts.counts.late.total : 0 },
  { to: '/admin/courses/nouvelle', icon: '➕', label: 'Nouvelle course', permission: 'orders.create' },
  { to: '/admin/marchands', icon: '🏪', label: 'E-commerçants', permission: 'merchants.view' },
  { to: '/admin/stock', icon: '🏬', label: 'Stock', permission: 'stock.manage', feature: 'stock' },
  { to: '/admin/terrain', icon: '📣', label: 'Remontées terrain', permission: 'orders.dispatch', badge: fieldReports.open.total },
  { to: '/admin/colis-livreurs', icon: '🎒', label: 'Colis chez les livreurs', permission: 'orders.dispatch', badge: heldParcels.counts.overdue },
  { to: '/admin/retours', icon: '📮', label: 'Retours marchands', permission: 'orders.dispatch' },
  { to: '/admin/carte', icon: '📍', label: 'Carte des livreurs', permission: 'orders.dispatch' },
  { to: '/admin/livreurs', icon: '🛵', label: 'Livreurs', permission: 'orders.dispatch' },
  { to: '/admin/utilisateurs', icon: '👥', label: 'Utilisateurs', permission: 'users.view' },
  { to: '/admin/zones', icon: '🗺️', label: 'Zones', permission: 'settings.manage' },
  { to: '/admin/tarifs', icon: '🏷️', label: 'Tarifs', permission: 'settings.manage' },
  { to: '/admin/paie-livreurs', icon: '🧾', label: 'Paie des livreurs', permission: 'settings.manage' },
  { to: '/admin/caisse', icon: '💰', label: 'Caisse', permission: 'finance.view' },
  { to: '/admin/messages', icon: '💬', label: 'Messages', permission: 'orders.dispatch' },
  { to: '/admin/integrations', icon: '🔌', label: 'Intégrations (API)', permission: 'integrations.manage', feature: 'api' },
  { to: '/admin/whatsapp', icon: '📱', label: 'WhatsApp', permission: 'settings.manage' },
  { to: '/admin/parametres', icon: '⚙️', label: 'Paramètres', permission: 'settings.manage' },
  // Seulement pour une entreprise abonnée à une formule (plateforme)
  ...(auth.user?.company?.plan ? [{ to: '/admin/abonnement', icon: '🧾', label: 'Mon abonnement', permission: 'settings.manage' }] : []),
].filter((item) => (!item.permission || auth.can(item.permission)) && (!item.feature || auth.hasFeature(item.feature))))

function quickSearch() {
  router.push({ path: '/admin/courses', query: { search: search.value } })
  search.value = ''
}

const logout = async () => {
  await auth.logout()
  router.push('/admin/connexion')
}
</script>
