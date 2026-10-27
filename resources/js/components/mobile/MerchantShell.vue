<template>
  <div class="min-h-dvh bg-[#F4F6F8] tap" :style="{ '--app-color': color }">
    <!-- En-tête : initiales de la boutique (ou retour), titre et date, recherche, cloche -->
    <header class="sticky top-0 z-30 bg-[#F4F6F8]/90 backdrop-blur pt-safe">
      <div class="mx-auto max-w-lg h-[60px] px-3 flex items-center gap-2">
        <button v-if="route.meta.back" class="h-10 w-10 shrink-0 grid place-items-center rounded-full bg-white shadow-soft text-slate-800 active:scale-95 transition" aria-label="Retour" @click="goBack">
          <AppIcon name="left" class="h-5 w-5" :stroke="2.4" />
        </button>
        <RouterLink v-else :to="`${base}/profil`" class="h-10 w-10 shrink-0 rounded-2xl bg-brand-grad grid place-items-center text-[13px] font-extrabold text-white" aria-label="Mon profil">{{ initials }}</RouterLink>
        <div class="min-w-0 flex-1 pl-1">
          <h1 class="truncate text-[17px] font-bold leading-tight text-slate-900">{{ title }}</h1>
          <p v-if="subtitle" class="truncate text-[12.5px] leading-tight text-slate-500 first-letter:uppercase">{{ subtitle }}</p>
        </div>
        <RouterLink v-if="!route.meta.back" :to="`${base}/courses?chercher=1`" class="h-10 w-10 shrink-0 grid place-items-center rounded-full bg-white shadow-soft text-slate-600 active:scale-95 transition" aria-label="Rechercher une course">
          <AppIcon name="search" class="h-5 w-5" :stroke="2.2" />
        </RouterLink>
        <!-- Cloche : le nombre ne compte que ce qui attend une décision ; un point pour le reste des nouveautés -->
        <RouterLink :to="`${base}/notifications`" class="relative h-10 w-10 shrink-0 grid place-items-center rounded-full bg-white shadow-soft active:scale-95 transition" :aria-label="bellLabel">
          <ColorIcon name="cloche" :size="24" :tile="false" />
          <span v-if="actionCount" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-[#E54A6E] text-white text-[10.5px] font-extrabold grid place-items-center ring-2 ring-white">{{ actionCount > 9 ? '9+' : actionCount }}</span>
          <span v-else-if="notifications.unread" class="absolute top-1.5 right-1.5 h-2.5 w-2.5 rounded-full bg-[#E54A6E] ring-2 ring-white" />
        </RouterLink>
      </div>
      <div v-if="!pwa.online" class="bg-amber-500 text-amber-950 text-center text-sm font-medium py-1.5">
        Hors ligne : les informations peuvent ne pas être à jour
      </div>
    </header>

    <main :class="['mx-auto max-w-lg px-4 pt-2', showTabs ? 'pb-32' : 'pb-8']">
      <RouterView />
    </main>

    <!-- Barre d'onglets (cachée pendant la saisie au clavier et sur les parcours à étapes) -->
    <nav v-show="showTabs" class="fixed bottom-0 inset-x-0 z-30 bg-white/95 backdrop-blur border-t border-slate-200/70 pb-safe">
      <div class="mx-auto max-w-lg flex h-[66px] px-1">
        <template v-for="tab in tabs" :key="tab.to">
        <!-- Bouton du milieu : nouvelle course, plusieurs courses, fichier Excel -->
        <div v-if="tab.primary" class="flex-1 flex items-start justify-center">
          <button
            type="button"
            class="-mt-5 h-[58px] w-[58px] rounded-[20px] bg-brand-grad grid place-items-center text-white shadow-[0_10px_24px_-8px_color-mix(in_srgb,var(--app-color)_70%,transparent)] active:scale-95 transition"
            :aria-label="tab.label"
            @click="menuOpen = true"
          >
            <AppIcon name="plus" class="h-7 w-7" :stroke="2.6" />
          </button>
        </div>
        <!-- Onglet actif : icône en couleur sur une pastille ; inactif : simple trait -->
        <RouterLink
          v-else
          :to="tab.to"
          class="flex-1 flex flex-col items-center justify-center gap-0.5 text-[11.5px] font-semibold"
          :style="isActive(tab) ? { color } : {}"
          :class="isActive(tab) ? '' : 'text-slate-500'"
        >
          <span :class="['relative h-8 w-14 grid place-items-center rounded-full transition-colors', isActive(tab) ? 'bg-brand-soft' : '']">
            <ColorIcon v-if="isActive(tab) && tab.color" :name="tab.color" :size="26" :tile="false" />
            <AppIcon v-else :name="tab.icon" class="h-6 w-6" :stroke="1.9" />
            <span v-if="tab.badge" class="absolute -top-1 right-1 min-w-4 h-4 px-1 rounded-full bg-[#E54A6E] text-white text-[10px] font-bold grid place-items-center">{{ tab.badge }}</span>
          </span>
          {{ tab.label }}
        </RouterLink>
        </template>
      </div>
    </nav>

    <BottomSheet v-if="primaryTab" :open="menuOpen" :title="primaryTab.sheetTitle || primaryTab.label" @close="menuOpen = false">
      <div class="space-y-2.5">
        <RouterLink
          v-for="(item, i) in primaryTab.menu"
          :key="item.to"
          :to="item.to"
          :class="['tap flex items-center gap-3.5 rounded-[20px] p-3 active:scale-[.99] transition', i === 0 ? 'bg-brand-soft ring-2 ring-[color-mix(in_srgb,var(--app-color)_30%,white)]' : 'bg-[#F7F8FA]']"
          @click="menuOpen = false"
        >
          <ColorIcon :name="item.color" :size="52" />
          <span class="min-w-0"><span class="block text-[15.5px] font-bold text-slate-900">{{ item.label }}</span><span class="text-[13px] text-slate-500">{{ item.text }}</span></span>
        </RouterLink>
      </div>
    </BottomSheet>

    <Toasts />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AppIcon from './AppIcon.vue'
import ColorIcon from './ColorIcon.vue'
import Toasts from '../Toasts.vue'
import BottomSheet from './BottomSheet.vue'
import { useNotificationStore } from '../../stores/notifications'
import { useRealtime } from '../../composables/useRealtime'
import { pwa } from '../../composables/usePwa'
import { keyboardOpen } from '../../composables/useKeyboard'
import { pageTitle } from '../../composables/usePageTitle'

const props = defineProps({
  base: { type: String, required: true },
  color: { type: String, required: true },
  appName: { type: String, required: true },
  initials: { type: String, default: '' },
  tabs: { type: Array, required: true }, // { to, label, icon, color?, badge?, exact?, primary?, sheetTitle?, menu? }
  // Nombre de colis qui attendent une décision (seul compteur affiché sur la cloche)
  actionCount: { type: Number, default: 0 },
})

const route = useRoute()
const router = useRouter()
const notifications = useNotificationStore()

useRealtime()
if (!notifications.loaded) notifications.fetch().catch(() => {})

const title = computed(() => pageTitle.value || route.meta.title || props.appName)
// Accueil : la date du jour sous le nom de la boutique
const subtitle = computed(() => (route.path === props.base ? new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }) : ''))
const bellLabel = computed(() => (props.actionCount ? `Notifications : ${props.actionCount} colis à régler` : 'Notifications'))
watch(() => route.path, () => { pageTitle.value = '' })
const showTabs = computed(() => !keyboardOpen.value && !route.meta.hideTabs)
const menuOpen = ref(false)
const primaryTab = computed(() => props.tabs.find((t) => t.primary && t.menu))

// Couleur de l'application aussi sur <html> : les panneaux du bas (téléportés dans <body>) en héritent
// Classe app-marchand aussi : les cartes et boutons des panneaux prennent le style doux de l'application
watchEffect(() => document.documentElement.style.setProperty('--app-color', props.color))
document.documentElement.classList.add('app-marchand')
onBeforeUnmount(() => {
  document.documentElement.style.removeProperty('--app-color')
  document.documentElement.classList.remove('app-marchand')
})

function isActive(tab) {
  return tab.exact ? route.path === tab.to : route.path.startsWith(tab.to)
}

function goBack() {
  if (window.history.state?.back) router.back()
  else router.push(props.base)
}
</script>
