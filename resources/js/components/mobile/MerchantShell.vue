<template>
  <div class="min-h-dvh bg-[#f6f7f9] tap" :style="{ '--app-color': color }">
    <!-- En-tête blanc : initiales de la boutique (ou retour), titre, recherche, cloche -->
    <header class="sticky top-0 z-30 bg-white/95 backdrop-blur border-b border-slate-200 pt-safe">
      <div class="mx-auto max-w-lg h-14 px-2 flex items-center gap-1">
        <button v-if="route.meta.back" class="h-11 w-11 grid place-items-center rounded-full text-slate-700 active:bg-slate-100" aria-label="Retour" @click="goBack">
          <AppIcon name="left" class="h-6 w-6" :stroke="2.2" />
        </button>
        <RouterLink v-else :to="`${base}/profil`" class="ml-1.5 h-9 w-9 shrink-0 rounded-full grid place-items-center text-[13px] font-extrabold text-white" :style="{ backgroundColor: color }" aria-label="Mon profil">{{ initials }}</RouterLink>
        <h1 :class="['flex-1 truncate font-bold text-slate-900', route.meta.back ? 'text-[17px]' : 'pl-2 text-base']">{{ title }}</h1>
        <RouterLink v-if="!route.meta.back" :to="`${base}/courses?chercher=1`" class="h-11 w-11 grid place-items-center rounded-full text-slate-700 active:bg-slate-100" aria-label="Rechercher une course">
          <AppIcon name="search" class="h-[22px] w-[22px]" />
        </RouterLink>
        <RouterLink :to="`${base}/notifications`" class="relative h-11 w-11 grid place-items-center rounded-full text-slate-700 active:bg-slate-100" aria-label="Notifications">
          <AppIcon name="bell" class="h-[22px] w-[22px]" />
          <span v-if="notifications.unread" class="absolute top-1.5 right-1.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10.5px] font-extrabold grid place-items-center ring-2 ring-white">{{ notifications.unread > 9 ? '9+' : notifications.unread }}</span>
        </RouterLink>
      </div>
      <div v-if="!pwa.online" class="bg-amber-500 text-amber-950 text-center text-sm font-medium py-1.5">
        Hors ligne : les informations peuvent ne pas être à jour
      </div>
    </header>

    <main :class="['mx-auto max-w-lg px-4 pt-4', showTabs ? 'pb-40' : 'pb-8']">
      <RouterView />
    </main>

    <!-- Bouton « Nouvelle course », toujours au même endroit -->
    <RouterLink
      v-if="showFab"
      :to="`${base}/courses/nouvelle`"
      class="fixed right-4 z-30 bottom-[calc(80px+env(safe-area-inset-bottom))] flex items-center gap-2 rounded-full px-5 py-3.5 text-base font-bold text-white shadow-[0_10px_24px_rgba(0,0,0,.22)] active:scale-95 transition"
      :style="{ backgroundColor: color }"
    >
      <AppIcon name="plus" class="h-[22px] w-[22px]" :stroke="2.6" />Nouvelle course
    </RouterLink>

    <!-- Barre d'onglets (cachée pendant la saisie au clavier et sur les parcours à étapes) -->
    <nav v-show="showTabs" class="fixed bottom-0 inset-x-0 z-30 bg-white border-t border-slate-200 pb-safe">
      <div class="mx-auto max-w-lg flex h-16">
        <RouterLink
          v-for="tab in tabs"
          :key="tab.to"
          :to="tab.to"
          class="flex-1 flex flex-col items-center justify-center gap-0.5 text-xs font-semibold"
          :style="isActive(tab) ? { color } : {}"
          :class="isActive(tab) ? '' : 'text-slate-500'"
        >
          <span class="relative">
            <AppIcon :name="tab.icon" class="h-6 w-6" :stroke="isActive(tab) ? 2.3 : 1.9" />
            <span v-if="tab.badge" class="absolute -top-1 -right-2 min-w-4 h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-bold grid place-items-center">{{ tab.badge }}</span>
          </span>
          {{ tab.label }}
        </RouterLink>
      </div>
    </nav>

    <Toasts />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, watch, watchEffect } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AppIcon from './AppIcon.vue'
import Toasts from '../Toasts.vue'
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
  tabs: { type: Array, required: true }, // { to, label, icon, badge?, exact? }
  canCreate: { type: Boolean, default: false },
})

const route = useRoute()
const router = useRouter()
const notifications = useNotificationStore()

useRealtime()
if (!notifications.loaded) notifications.fetch().catch(() => {})

const title = computed(() => pageTitle.value || route.meta.title || props.appName)
watch(() => route.path, () => { pageTitle.value = '' })
const showTabs = computed(() => !keyboardOpen.value && !route.meta.hideTabs)
// Pas de bouton flottant sur les fiches et les parcours (ils ont leurs propres boutons en bas)
const showFab = computed(() => props.canCreate && showTabs.value && !route.meta.back)

// Couleur de l'application aussi sur <html> : les panneaux du bas (téléportés dans <body>) en héritent
watchEffect(() => document.documentElement.style.setProperty('--app-color', props.color))
onBeforeUnmount(() => document.documentElement.style.removeProperty('--app-color'))

function isActive(tab) {
  return tab.exact ? route.path === tab.to : route.path.startsWith(tab.to)
}

function goBack() {
  if (window.history.state?.back) router.back()
  else router.push(props.base)
}
</script>
