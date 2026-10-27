<template>
  <div class="space-y-4">
    <div class="m-card p-5 flex items-center gap-4">
      <div class="h-14 w-14 shrink-0 rounded-[18px] bg-brand-grad grid place-items-center text-xl font-bold text-white">
        {{ initials }}
      </div>
      <div class="min-w-0">
        <p class="font-semibold text-lg truncate">{{ auth.user?.name }}</p>
        <p class="text-sm text-slate-500">{{ localPhone(auth.user?.phone) }}</p>
        <p class="text-sm text-slate-500">{{ auth.user?.merchant?.business_name || auth.user?.company?.name }}</p>
      </div>
    </div>

    <InstallBanner :app="app" />

    <!-- E-commerçant : sa boutique, ses notifications, puis les outils avancés (usage rare) -->
    <template v-if="auth.user?.merchant_id">
      <h2 class="px-1 pt-1 text-[12.5px] font-bold text-slate-400 uppercase tracking-wider">Ma boutique</h2>
      <section class="rounded-[22px] bg-white shadow-soft divide-y divide-slate-100">
        <RouterLink v-for="row in shopRows" :key="row.to" :to="row.to" class="tap flex items-center gap-3 px-3.5 py-3 active:bg-slate-50 first:rounded-t-[22px] last:rounded-b-[22px]">
          <ColorIcon :name="row.icon" :size="40" />
          <span class="min-w-0 flex-1"><span class="block text-[14.5px] font-semibold">{{ row.label }}</span><span :class="['text-[12.5px]', row.warn ? 'text-amber-700' : 'text-slate-500']">{{ row.text }}</span></span>
          <span v-if="row.warn" class="shrink-0 rounded-full bg-[#FFECEF] px-2.5 py-0.5 text-[12px] font-semibold text-[#C02A4E]">À faire</span>
          <AppIcon name="right" class="h-4 w-4 shrink-0 text-slate-400" />
        </RouterLink>
      </section>
    </template>

    <PushSettings v-if="app === 'livreur'" />

    <WhatsAppPreferences v-if="auth.user?.merchant_id" />

    <template v-if="toolRows.length">
      <h2 class="px-1 pt-1 text-[12.5px] font-bold text-slate-400 uppercase tracking-wider">Outils avancés</h2>
      <section class="rounded-[22px] bg-white shadow-soft divide-y divide-slate-100">
        <RouterLink v-for="row in toolRows" :key="row.to" :to="row.to" class="tap flex items-center gap-3 px-3.5 py-3 active:bg-slate-50 first:rounded-t-[22px] last:rounded-b-[22px]">
          <ColorIcon :name="row.icon" :size="40" />
          <span class="min-w-0 flex-1 text-[14.5px] font-semibold">{{ row.label }}</span>
          <AppIcon name="right" class="h-4 w-4 shrink-0 text-slate-400" />
        </RouterLink>
      </section>
    </template>

    <h2 class="px-1 pt-1 text-[12.5px] font-bold text-slate-400 uppercase tracking-wider">Mon compte</h2>
    <PasswordForm class="m-card p-4" />

    <h2 class="px-1 pt-1 text-[12.5px] font-bold text-slate-400 uppercase tracking-wider">Aide</h2>
    <div class="m-card divide-y">
      <div class="p-4 flex justify-between text-sm"><span class="text-slate-500">Entreprise de livraison</span><span class="font-medium">{{ auth.user?.company?.name }}</span></div>
      <div class="p-4 flex justify-between text-sm"><span class="text-slate-500">Rôle</span><span class="font-medium">{{ auth.user?.role_label }}</span></div>
      <a v-if="companyPhone" :href="telLink(companyPhone)" class="p-4 flex justify-between text-sm active:bg-slate-50">
        <span class="text-slate-500">Contacter l'entreprise</span><span class="font-medium text-[var(--app-color)]">{{ companyPhone }}</span>
      </a>
    </div>

    <button class="m-btn-secondary text-red-600" @click="logout">Se déconnecter</button>
    <p class="text-center text-xs text-slate-400">Version {{ installedLabel }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/mobile/AppIcon.vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import WhatsAppPreferences from '../../components/mobile/WhatsAppPreferences.vue'
import PushSettings from '../../components/mobile/PushSettings.vue'
import PasswordForm from '../../components/PasswordForm.vue'
import { forgetPushDevice } from '../../composables/usePush'
import { useAuthStore } from '../../stores/auth'
import { pwa } from '../../composables/usePwa'
import { offlineQueue } from '../../composables/useOfflineQueue'
import { localPhone, telLink } from '../../utils/format'
import { appLock } from '../../native'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const app = computed(() => (route.path.startsWith('/livreur') ? 'livreur' : 'marchand'))
const companyPhone = computed(() => auth.user?.company?.phone)
const initials = computed(() => (auth.user?.name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase())
// Rangées « Ma boutique » et « Outils avancés » de l'e-commerçant, avec leur icône en couleur
const shopRows = computed(() => {
  const m = auth.user?.merchant
  return [
    auth.can('integrations.manage') && auth.hasFeature('shop') && { to: '/marchand/boutique', icon: 'boutique', label: 'Ma boutique en ligne', text: 'Un lien de commande à partager sur WhatsApp et les réseaux' },
    { to: '/marchand/lieu-de-ramassage', icon: 'lieu', label: 'Lieu de ramassage', warn: !m?.has_pickup_location, text: !m?.has_pickup_location ? 'Position à indiquer' : m.pickup_location_source === 'courier' ? 'Estimée par nos livreurs : à vérifier' : 'Position enregistrée' },
    auth.can('finance.view') && { to: '/marchand/moyens-de-paiement', icon: 'paiement', label: 'Comment être payé', text: 'Wave, Orange Money, MTN, Moov ou banque, avec lien et QR code' },
    auth.hasFeature('stock') && { to: '/marchand/stock', icon: 'stock', label: 'Mon stock', text: 'Vos produits et leurs quantités' },
    { to: '/marchand/statistiques', icon: 'stats', label: 'Mes statistiques', text: 'Taux de livraison, motifs d\'échec' },
  ].filter(Boolean)
})
const toolRows = computed(() => (auth.user?.merchant_id
  ? [
      auth.can('orders.create') && { to: '/marchand/courses/import', icon: 'excel', label: 'Importer des courses (Excel, CSV)' },
      auth.can('integrations.manage') && auth.hasFeature('api') && { to: '/marchand/integrations', icon: 'reglages', label: 'Intégrations (API, webhooks)' },
    ].filter(Boolean)
  : []))
const installedLabel = computed(() => (pwa.installed ? 'application installée' : 'web'))

async function logout() {
  // Actions faites hors connexion pas encore envoyées : elles attendraient la prochaine connexion
  if (offlineQueue.pending.length && !window.confirm(`${offlineQueue.pending.length} action(s) faite(s) hors connexion ne sont pas encore envoyées. Déconnecté, elles ne partiront que lorsque vous vous reconnecterez sur ce téléphone. Se déconnecter quand même ?`)) return
  await forgetPushDevice()
  // Application installée : sa propre page de connexion (l'e-commerçant reste dans /marchand)
  const login = appLock()?.login || (auth.role === 'courier' ? '/livreur/connexion' : '/')
  await auth.logout()
  router.push(login)
}
</script>
