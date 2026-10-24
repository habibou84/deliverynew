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

    <main :class="['mx-auto max-w-lg px-4 pt-4', showTabs ? 'pb-28' : 'pb-8']">
      <RouterView />
    </main>

    <!-- Barre d'onglets (cachée pendant la saisie au clavier et sur les parcours à étapes) -->
    <nav v-show="showTabs" class="fixed bottom-0 inset-x-0 z-30 bg-white border-t border-slate-200 pb-safe">
      <div class="mx-auto max-w-lg flex h-16">
        <template v-for="tab in tabs" :key="tab.to">
        <!-- Bouton rond du milieu : créer, coller un message, importer -->
        <div v-if="tab.primary" class="flex-1 flex items-center justify-center">
          <button
            type="button"
            class="h-12 w-12 rounded-full grid place-items-center text-white shadow-md active:scale-95 transition"
            :style="{ backgroundColor: color }"
            :aria-label="tab.label"
            @click="menuOpen = true"
          >
            <AppIcon name="plus" class="h-7 w-7" :stroke="2.6" />
          </button>
        </div>
        <RouterLink
          v-else
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
        </template>
      </div>
    </nav>

    <BottomSheet v-if="primaryTab" :open="menuOpen" :title="primaryTab.label" @close="menuOpen = false">
      <div class="space-y-2">
        <RouterLink
          v-for="(item, i) in primaryTab.menu"
          :key="item.to"
          :to="item.to"
          :class="['tap flex items-center gap-3 rounded-2xl p-4 ring-1', i === 0 ? 'text-white ring-transparent' : 'bg-white ring-slate-200']"
          :style="i === 0 ? { backgroundColor: color } : {}"
          @click="menuOpen = false"
        >
          <span :class="['h-10 w-10 shrink-0 rounded-xl grid place-items-center', i === 0 ? 'bg-white/20' : 'bg-slate-100 text-slate-700']"><AppIcon :name="item.icon" class="h-[22px] w-[22px]" /></span>
          <span><span class="block font-semibold">{{ item.label }}</span><span :class="['text-sm', i === 0 ? 'opacity-85' : 'text-slate-500']">{{ item.text }}</span></span>
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
  tabs: { type: Array, required: true }, // { to, label, icon, badge?, exact?, primary?, menu? }
})

const route = useRoute()
const router = useRouter()
const notifications = useNotificationStore()

useRealtime()
if (!notifications.loaded) notifications.fetch().catch(() => {})

const title = computed(() => pageTitle.value || route.meta.title || props.appName)
watch(() => route.path, () => { pageTitle.value = '' })
const showTabs = computed(() => !keyboardOpen.value && !route.meta.hideTabs)
const menuOpen = ref(false)
const primaryTab = computed(() => props.tabs.find((t) => t.primary && t.menu))

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
