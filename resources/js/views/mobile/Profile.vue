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

    <!-- Livreur : le téléphone sonne comme un appel pour une nouvelle mission (application Android) -->
    <RouterLink v-if="auth.user?.role === 'courier'" to="/livreur/sonnerie" class="m-card tap p-4 flex items-center justify-between gap-3 active:bg-slate-50">
      <span>
        <span class="block font-semibold">🔔 Sonnerie des missions</span>
        <span class="block text-sm text-slate-500">Le téléphone sonne comme un appel pour chaque nouvelle mission</span>
      </span>
      <span class="text-slate-400" aria-hidden="true">›</span>
    </RouterLink>

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

    <!-- Suppression du compte (exigée par Google Play) : explication, confirmation, puis compte fermé tout de suite -->
    <section v-if="canDelete" class="rounded-[22px] bg-white shadow-soft p-4 space-y-3">
      <button v-if="!deleting" type="button" class="w-full text-center text-sm font-semibold text-red-600" @click="deleting = true">Supprimer mon compte</button>
      <template v-else>
        <h3 class="font-bold text-red-700">Supprimer mon compte</h3>
        <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
          <li>Votre compte est fermé tout de suite, sur tous vos téléphones.</li>
          <li>{{ auth.user?.company?.name || 'Votre entreprise de livraison' }} est prévenue et peut le rouvrir à votre demande pendant 30 jours.</li>
          <li>Après 30 jours, votre nom, votre numéro, votre e-mail et votre mot de passe sont effacés. Les courses et la comptabilité restent, sous un nom anonyme.</li>
        </ul>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="understood" type="checkbox" class="mt-1 h-4 w-4">
          <span>J'ai compris : je veux supprimer mon compte.</span>
        </label>
        <p v-if="deleteError" class="text-sm text-red-700" role="alert">{{ deleteError }}</p>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="m-btn-secondary" :disabled="sending" @click="deleting = false; understood = false">Annuler</button>
          <button type="button" class="m-btn-danger" :disabled="!understood || sending" @click="deleteAccount">{{ sending ? '…' : 'Supprimer' }}</button>
        </div>
        <a :href="siteUrl('/suppression-du-compte')" target="_blank" rel="noopener" class="block text-center text-xs text-slate-500 underline">En savoir plus</a>
      </template>
    </section>
    <p class="text-center text-xs text-slate-400">Version {{ installedLabel }}</p>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
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
import { appLock, siteUrl } from '../../native'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'

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
// Suppression du compte : e-commerçant (propriétaire ou employé) et livreur
const canDelete = computed(() => ['merchant_owner', 'merchant_staff', 'courier'].includes(auth.role))
const deleting = ref(false)
const understood = ref(false)
const sending = ref(false)
const deleteError = ref('')
const toasts = useToastStore()

async function deleteAccount() {
  sending.value = true
  deleteError.value = ''
  try {
    const { data } = await http.post('/account/deletion', { confirm: true })
    await forgetPushDevice().catch(() => {})
    const login = appLock()?.login || (auth.role === 'courier' ? '/livreur/connexion' : '/')
    // Connexion déjà coupée par le serveur : on oublie seulement la session de ce téléphone
    auth.clear()
    toasts.success(data.data.message)
    router.push(login)
  } catch (e) {
    deleteError.value = apiErrorMessage(e)
  } finally {
    sending.value = false
  }
}

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
