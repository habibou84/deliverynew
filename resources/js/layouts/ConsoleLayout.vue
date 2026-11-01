<template>
  <div class="min-h-screen bg-slate-100">
    <header class="bg-slate-950 text-white print:hidden">
      <div class="mx-auto max-w-6xl px-4 py-3 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-4">
          <RouterLink to="/console" class="font-bold">🛰️ Console de la plateforme</RouterLink>
          <nav class="flex flex-wrap gap-1 text-sm">
            <RouterLink
              v-for="item in menu" :key="item.to" :to="item.to" class="rounded px-3 py-1.5 hover:bg-white/10"
              :exact-active-class="item.exact ? 'bg-white/15' : undefined" :active-class="item.exact ? undefined : 'bg-white/15'"
            >{{ item.label }}</RouterLink>
          </nav>
        </div>
        <!-- Téléphone : recherche sur toute la largeur, puis cloche, compte et déconnexion (rien ne dépasse de l'écran) -->
        <div class="flex w-full min-w-0 flex-wrap items-center gap-2 text-sm sm:w-auto sm:gap-3">
          <ConsoleSearch class="w-full sm:w-auto" />
          <NotificationBell class="[&>button]:hover:bg-white/10" />
          <RouterLink to="/console/compte" class="min-w-0 flex-1 truncate text-slate-300 hover:text-white hover:underline sm:flex-none" title="Mon compte">👤 {{ auth.user?.name }}<span class="hidden sm:inline"> · {{ ROLE_LABELS[auth.role] }}</span></RouterLink>
          <button class="shrink-0 rounded bg-white/10 px-3 py-1.5 hover:bg-white/20" @click="logout">Déconnexion</button>
        </div>
      </div>
    </header>
    <main class="mx-auto max-w-6xl p-4 md:p-6">
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import ConsoleSearch from '../components/console/ConsoleSearch.vue'
import NotificationBell from '../components/NotificationBell.vue'
import { CONSOLE_ROLES } from '../roles'
import { useAuthStore } from '../stores/auth'
import { ROLE_LABELS } from '../utils/format'

const auth = useAuthStore()
const router = useRouter()

// Menu selon le rôle (Propriétaire, Support, Comptable) ; le serveur refuse de toute façon le reste
const ITEMS = [
  { to: '/console', label: 'Tableau de bord', exact: true, roles: CONSOLE_ROLES.team },
  { to: '/console/entreprises', label: 'Entreprises', roles: CONSOLE_ROLES.team },
  { to: '/console/formules', label: 'Formules', roles: CONSOLE_ROLES.team },
  { to: '/console/premium', label: 'Cartes Premium', roles: CONSOLE_ROLES.team },
  { to: '/console/jibiat-ia', label: 'Jibiat IA', roles: CONSOLE_ROLES.team },
  { to: '/console/applications', label: 'Applications à leur nom', roles: CONSOLE_ROLES.team },
  { to: '/console/facturation', label: 'Facturation', roles: CONSOLE_ROLES.accounting },
  { to: '/console/paiements', label: 'Paiements Wave', roles: CONSOLE_ROLES.accounting },
  { to: '/console/whatsapp', label: 'WhatsApp', roles: CONSOLE_ROLES.support },
  { to: '/console/sante', label: 'Santé', roles: CONSOLE_ROLES.support },
  { to: '/console/journal', label: 'Journal', roles: CONSOLE_ROLES.owner },
  { to: '/console/equipe', label: 'Équipe', roles: CONSOLE_ROLES.owner },
]
const menu = computed(() => ITEMS.filter((i) => i.roles.includes(auth.role)))

async function logout() {
  await auth.logout()
  router.push('/admin/connexion')
}
</script>
