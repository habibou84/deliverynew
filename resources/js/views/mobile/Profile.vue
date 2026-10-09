<template>
  <div class="space-y-4">
    <div class="m-card p-5 flex items-center gap-4">
      <div class="h-14 w-14 rounded-full grid place-items-center text-xl font-bold text-white" :style="{ backgroundColor: 'var(--app-color)' }">
        {{ initials }}
      </div>
      <div class="min-w-0">
        <p class="font-semibold text-lg truncate">{{ auth.user?.name }}</p>
        <p class="text-sm text-slate-500">{{ auth.user?.phone }}</p>
        <p class="text-sm text-slate-500">{{ auth.user?.merchant?.business_name || auth.user?.company?.name }}</p>
      </div>
    </div>

    <InstallBanner :app="app" />

    <RouterLink v-if="auth.user?.merchant_id" to="/marchand/stock" class="tap m-card p-4 flex justify-between items-center active:bg-slate-50">
      <span class="font-medium">📦 Mon stock</span><span class="text-slate-400">›</span>
    </RouterLink>
    <RouterLink v-if="auth.user?.merchant_id && auth.can('orders.create')" to="/marchand/courses/import" class="tap m-card p-4 flex justify-between items-center active:bg-slate-50">
      <span class="font-medium">📥 Importer des courses (Excel, CSV)</span><span class="text-slate-400">›</span>
    </RouterLink>
    <RouterLink v-if="auth.user?.merchant_id && auth.can('integrations.manage')" to="/marchand/integrations" class="tap m-card p-4 flex justify-between items-center active:bg-slate-50">
      <span class="font-medium">🔌 Intégrations (API, webhooks)</span><span class="text-slate-400">›</span>
    </RouterLink>

    <PushSettings v-if="app === 'livreur'" />

    <WhatsAppPreferences v-if="auth.user?.merchant_id" />

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
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import WhatsAppPreferences from '../../components/mobile/WhatsAppPreferences.vue'
import PushSettings from '../../components/mobile/PushSettings.vue'
import { forgetPushDevice } from '../../composables/usePush'
import { useAuthStore } from '../../stores/auth'
import { pwa } from '../../composables/usePwa'
import { telLink } from '../../utils/format'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const app = computed(() => (route.path.startsWith('/livreur') ? 'livreur' : 'marchand'))
const companyPhone = computed(() => auth.user?.company?.phone)
const initials = computed(() => (auth.user?.name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase())
const installedLabel = computed(() => (pwa.installed ? 'application installée' : 'web'))

async function logout() {
  await forgetPushDevice()
  const login = auth.role === 'courier' ? '/livreur/connexion' : '/'
  await auth.logout()
  router.push(login)
}
</script>
