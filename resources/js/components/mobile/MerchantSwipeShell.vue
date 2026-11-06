<template>
  <div class="flex h-dvh flex-col overflow-hidden bg-[#F4F6F8] tap" :style="{ '--app-color': color }">
    <!-- En-tête : boutique, recherche, cloche ; dessous les onglets (on glisse d'une page à l'autre) -->
    <header class="z-30 shrink-0 bg-white pt-safe shadow-[0_1px_0_#e2e8f0]">
      <div class="flex h-[54px] items-center gap-2 px-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-2xl bg-brand-grad text-[12.5px] font-extrabold text-white" aria-hidden="true">{{ initials }}</span>
        <p class="min-w-0 flex-1 truncate text-[16.5px] font-bold text-slate-900">{{ appName }}</p>
        <RouterLink :to="`${base}/courses?chercher=1`" class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-600" aria-label="Rechercher une course">
          <AppIcon name="search" class="h-5 w-5" :stroke="2.2" />
        </RouterLink>
        <RouterLink :to="`${base}/notifications`" class="relative grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100" :aria-label="actionCount ? `Notifications : ${actionCount} colis à régler` : 'Notifications'">
          <ColorIcon name="cloche" :size="22" :tile="false" />
          <span v-if="notifications.unread" class="absolute right-1.5 top-1.5 h-2.5 w-2.5 rounded-full bg-[#E54A6E] ring-2 ring-white" />
        </RouterLink>
      </div>
      <nav ref="tabsEl" class="flex" aria-label="Pages">
        <button
          v-for="(p, i) in pages"
          :key="p.key"
          type="button"
          :class="['relative flex flex-1 flex-col items-center gap-0.5 border-b-[3px] pb-1.5 pt-0.5 text-[11.5px] font-bold transition-colors', i === current ? 'border-[var(--app-color)] text-[var(--app-color)]' : 'border-transparent text-slate-500']"
          :aria-current="i === current ? 'page' : undefined"
          @click="go(i)"
        >
          <ColorIcon v-if="p.icon" :name="p.icon" :size="26" :tile="false" />
          <span v-else class="text-[19px] leading-[26px]" aria-hidden="true">{{ p.emoji }}</span>
          {{ p.label }}
          <span v-if="p.badge?.()" :class="['absolute right-[18%] top-0 grid h-[17px] min-w-[17px] place-items-center rounded-full px-1 text-[10px] font-extrabold text-white', p.badge().level === 'red' ? 'swipe-urgent bg-[#E54A6E]' : 'bg-orange-500']">{{ p.badge().n }}</span>
        </button>
      </nav>
      <div v-if="!pwa.online" class="bg-amber-500 py-1 text-center text-[13px] font-medium text-amber-950">Hors ligne : les informations peuvent ne pas être à jour</div>
    </header>

    <!-- Pages côte à côte : on glisse avec le doigt, ou on touche l'onglet -->
    <div ref="track" class="swipe-track flex flex-1 snap-x snap-mandatory overflow-x-auto overflow-y-hidden" @scroll.passive="onScroll">
      <section v-for="(p, i) in pages" :key="p.key" class="h-full w-full shrink-0 snap-start snap-always overflow-y-auto overscroll-contain" :aria-hidden="i !== current">
        <div class="mx-auto max-w-lg px-4 pb-16 pt-3">
          <!-- Commandes : nouvelle course en haut -->
          <button v-if="p.key === 'orders' && canCreate" type="button" class="m-btn-primary mb-3.5 flex w-full items-center justify-center gap-2" @click="menuOpen = true">
            <AppIcon name="plus" class="h-5 w-5" :stroke="2.6" /> Nouvelle course
          </button>
          <component :is="p.component" v-if="seen.has(p.key)" :key="`${p.key}-${refresh[p.key] || 0}`" />
        </div>
      </section>
    </div>

    <!-- Écran ouvert depuis une page (course, relevé, réglage…) : fenêtre par-dessus, l'onglet reste derrière -->
    <Transition name="screen">
      <div v-if="screenOpen" class="fixed inset-0 z-40 flex flex-col justify-end bg-black/40" @click.self="close">
        <div
          class="flex max-h-[calc(100dvh-28px)] min-h-[60dvh] flex-col rounded-t-[26px] bg-[#F4F6F8] shadow-2xl transition-transform"
          :style="drag ? { transform: `translateY(${drag}px)`, transition: 'none' } : {}"
          role="dialog"
          aria-modal="true"
          :aria-label="screenTitle"
        >
          <div class="shrink-0 rounded-t-[26px] bg-white px-3 pb-2 pt-2" @touchstart.passive="dragStart" @touchmove.passive="dragMove" @touchend="dragEnd">
            <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
            <div class="flex items-center gap-2">
              <button v-if="depth > 1" type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-slate-700" aria-label="Retour" @click="router.back()">
                <AppIcon name="left" class="h-5 w-5" :stroke="2.4" />
              </button>
              <p class="min-w-0 flex-1 truncate px-1 text-[16.5px] font-bold">{{ screenTitle }}</p>
              <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-slate-500" aria-label="Fermer" @click="close">✕</button>
            </div>
          </div>
          <div ref="screenBody" class="flex-1 overflow-y-auto overscroll-contain px-4 pb-10 pt-3" @touchstart.passive="bodyDragStart" @touchmove.passive="dragMove" @touchend="dragEnd">
            <RouterView />
          </div>
        </div>
      </div>
    </Transition>

    <BottomSheet :open="menuOpen" title="Que voulez-vous envoyer ?" @close="menuOpen = false">
      <div class="space-y-2.5">
        <RouterLink
          v-for="(item, i) in newMenu"
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
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, watchEffect } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AppIcon from './AppIcon.vue'
import ColorIcon from './ColorIcon.vue'
import BottomSheet from './BottomSheet.vue'
import Toasts from '../Toasts.vue'
import { useNotificationStore } from '../../stores/notifications'
import { useRealtime } from '../../composables/useRealtime'
import { pwa } from '../../composables/usePwa'
import { pageTitle } from '../../composables/usePageTitle'

/**
 * Application e-commerçant sur téléphone (comme Jibiat Marchand) : onglets en haut, pages à glisser
 * (Commandes, Argent, Live, Boutique, Plus). Les autres écrans s'ouvrent dans une fenêtre par-dessus la page,
 * qu'on ferme d'un ✕, en touchant le fond ou en glissant vers le bas.
 */
const props = defineProps({
  base: { type: String, required: true },
  color: { type: String, required: true },
  appName: { type: String, required: true },
  initials: { type: String, default: '' },
  pages: { type: Array, required: true }, // { key, label, path, component, icon?, emoji?, badge?() }
  newMenu: { type: Array, default: () => [] },
  canCreate: Boolean,
  actionCount: { type: Number, default: 0 },
})

const route = useRoute()
const router = useRouter()
const notifications = useNotificationStore()
useRealtime()
if (!notifications.loaded) notifications.fetch().catch(() => {})

watchEffect(() => document.documentElement.style.setProperty('--app-color', props.color))
document.documentElement.classList.add('app-marchand')
onBeforeUnmount(() => {
  document.documentElement.style.removeProperty('--app-color')
  document.documentElement.classList.remove('app-marchand')
})

// ── Pages ──
const track = ref(null)
const tabsEl = ref(null)
const current = ref(0)
const seen = reactive(new Set())
const refresh = reactive({})
const pageIndex = (path) => props.pages.findIndex((p) => p.path === path)
const lastPage = ref(props.pages[0]?.path ?? props.base)

function go(i, smooth = true) {
  const el = track.value
  if (!el) return
  el.scrollTo({ left: i * el.clientWidth, behavior: smooth ? 'smooth' : 'auto' })
  setPage(i)
}
let scrollTimer
function onScroll() {
  clearTimeout(scrollTimer)
  scrollTimer = setTimeout(() => {
    const el = track.value
    setPage(Math.round(el.scrollLeft / Math.max(1, el.clientWidth)))
  }, 80)
}
function setPage(i) {
  const p = props.pages[i]
  if (!p) return
  current.value = i
  seen.add(p.key)
  lastPage.value = p.path
  // L'adresse suit la page (retour du téléphone, liens partagés)
  if (route.path !== p.path && !screenOpen.value) router.replace(p.path)
}

// ── Écran par-dessus : toute adresse qui n'est pas une des pages ──
const screenOpen = computed(() => pageIndex(route.path) === -1)
const screenTitle = computed(() => pageTitle.value || route.meta.title || '')
const depth = ref(0)
watch(() => route.fullPath, (to, from) => {
  pageTitle.value = ''
  const i = pageIndex(route.path)
  if (i !== -1) {
    // Retour sur une page : on referme la fenêtre et on rafraîchit la page qui était derrière
    if (depth.value) refresh[props.pages[i].key] = (refresh[props.pages[i].key] || 0) + 1
    depth.value = 0
    if (i !== current.value) nextTick(() => go(i, false))
  } else {
    depth.value = from && pageIndex(from.split('?')[0]) !== -1 ? 1 : depth.value + 1
  }
})
function close() {
  router.push(lastPage.value)
}

// Fermer en glissant vers le bas (poignée, ou contenu déjà tout en haut)
const drag = ref(0)
const screenBody = ref(null)
let startY = null
function dragStart(e) {
  startY = e.touches[0].clientY
}
function bodyDragStart(e) {
  startY = screenBody.value?.scrollTop > 0 ? null : e.touches[0].clientY
}
function dragMove(e) {
  if (startY === null) return
  drag.value = Math.max(0, e.touches[0].clientY - startY)
}
function dragEnd() {
  if (drag.value > 110) close()
  drag.value = 0
  startY = null
}

onMounted(async () => {
  const i = pageIndex(route.path)
  const start = i === -1 ? 0 : i
  props.pages.forEach((p, k) => { if (k === start) seen.add(p.key) })
  current.value = start
  lastPage.value = props.pages[start]?.path ?? props.base
  await nextTick()
  go(start, false)
})
</script>

<style>
.swipe-track { scrollbar-width: none; }
.swipe-track::-webkit-scrollbar { display: none; }
.swipe-urgent { animation: swipe-pulse 1.6s infinite; }
@keyframes swipe-pulse { 0% { box-shadow: 0 0 0 0 rgba(229,74,110,.55) } 70% { box-shadow: 0 0 0 7px rgba(229,74,110,0) } 100% { box-shadow: 0 0 0 0 rgba(229,74,110,0) } }
@media (prefers-reduced-motion: reduce) { .swipe-urgent { animation: none; } }
.screen-enter-active, .screen-leave-active { transition: background-color .2s ease; }
.screen-enter-active > div, .screen-leave-active > div { transition: transform .25s ease; }
.screen-enter-from, .screen-leave-to { background-color: transparent; }
.screen-enter-from > div, .screen-leave-to > div { transform: translateY(100%); }
</style>
