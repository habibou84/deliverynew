<template>
  <div class="flex h-dvh flex-col overflow-hidden bg-[#F4F6F8] tap" :style="{ '--app-color': color }">
    <!-- En-tête figé, comme Jibiat Marchand : les onglets des pages (à faire défiler du doigt) et la cloche, rien d'autre -->
    <header class="z-30 shrink-0 bg-[#F4F6F8] pt-safe">
      <div class="relative flex h-14 items-center">
        <nav ref="tabsEl" class="flex h-full min-w-0 flex-1 items-center gap-2 overflow-x-auto pl-4 pr-20 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" role="tablist" aria-label="Pages">
          <button
            v-for="(p, i) in pages"
            :key="p.key"
            type="button"
            role="tab"
            :aria-selected="i === current"
            :class="['relative mt-1 shrink-0 rounded-full px-3 py-1.5 text-[13.5px] font-extrabold transition', tabClass(p, i)]"
            @click="go(i)"
          >
            {{ p.emoji }} {{ p.label }}
            <span
              v-if="p.badge?.()"
              :class="['absolute -right-1.5 -top-2 min-w-[19px] rounded-full border-2 border-[#F4F6F8] px-1 text-center text-[11px] font-black leading-[15px] text-white', p.badge().level === 'red' ? 'swipe-urgent bg-rose-600' : 'bg-amber-500']"
            >{{ p.badge().n }}</span>
          </button>
        </nav>
        <!-- La cloche, par-dessus la fin des onglets (fondu) -->
        <div class="absolute inset-y-0 right-0 flex items-center bg-[#F4F6F8] pr-4 before:pointer-events-none before:absolute before:inset-y-0 before:-left-8 before:w-8 before:bg-gradient-to-r before:from-transparent before:to-[#F4F6F8]">
          <RouterLink :to="`${base}/notifications`" class="relative grid h-10 w-10 place-items-center rounded-full bg-white shadow-soft" :aria-label="actionCount ? `Notifications : ${actionCount} colis à régler` : 'Notifications'">
            <ColorIcon name="cloche" :size="22" :tile="false" />
            <span v-if="notifications.unread" class="absolute right-1.5 top-1.5 h-2.5 w-2.5 rounded-full bg-[#E54A6E] ring-2 ring-white" />
          </RouterLink>
        </div>
      </div>
      <div v-if="!pwa.online" class="bg-amber-500 py-1 text-center text-[13px] font-medium text-amber-950">Hors ligne : les informations peuvent ne pas être à jour</div>
    </header>

    <!-- Pages côte à côte : on glisse avec le doigt, ou on touche l'onglet -->
    <div ref="track" data-swipe-pages :class="{ 'swipe-peek': peek }" class="swipe-track flex min-h-0 flex-1 snap-x snap-mandatory overflow-x-auto overflow-y-hidden" @scroll.passive="onScroll" @touchstart.passive="touched = true; peek = false">
      <section
        v-for="(p, i) in pages"
        :key="p.key"
        class="relative h-full w-full shrink-0 snap-start snap-always overflow-y-auto overscroll-y-contain"
        :aria-hidden="i !== current"
        @touchstart.passive="pullStart($event, i)"
        @touchmove.passive="pullMove"
        @touchend="pullEnd"
        @touchcancel="pullCancel"
      >
        <!-- Tirer vers le bas, page tout en haut : actualiser la page -->
        <div v-if="i === current && (pull || reloading)" class="pointer-events-none absolute inset-x-0 top-0 z-10 flex justify-center" :style="{ transform: `translateY(${reloading ? 14 : Math.max(0, pull - 34)}px)` }" aria-live="polite">
          <span class="flex items-center gap-2 rounded-full bg-white px-3.5 py-2 text-[13.5px] font-bold text-slate-700 shadow-soft">
            <svg :class="['h-[18px] w-[18px] text-[var(--app-color)]', reloading ? 'animate-spin' : '']" :style="reloading ? {} : { transform: `rotate(${pull * 3}deg)` }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7" /><path d="M20 4v5h-5" /></svg>
            {{ reloading ? 'Actualisation…' : pull >= PULL_AT ? 'Relâchez pour actualiser' : 'Tirez pour actualiser' }}
          </span>
        </div>
        <div class="mx-auto max-w-lg px-4 pb-28 pt-1" :style="i === current && pull ? { transform: `translateY(${pull}px)`, transition: 'none' } : { transition: 'transform .2s ease' }">
          <component :is="p.component" v-if="seen.has(p.key)" :key="`${p.key}-${refresh[p.key] || 0}`" />
        </div>
      </section>
    </div>

    <!-- Écran ouvert depuis une page (course, relevé, réglage…) : fenêtre par-dessus, l'onglet reste derrière -->
    <Transition name="screen">
      <div v-if="screenOpen" class="fixed inset-0 z-40 flex flex-col justify-end bg-black/40" @click.self="close">
        <!-- Fenêtre qui monte du bas, ou plein écran (ex. « Voir + » : toutes les courses) -->
        <div
          :class="['flex flex-col bg-[#F4F6F8] shadow-2xl transition-transform', full ? 'h-dvh pt-safe' : 'max-h-[calc(100dvh-28px)] min-h-[60dvh] rounded-t-[26px]']"
          :style="drag && !full ? { transform: `translateY(${drag}px)`, transition: 'none' } : {}"
          role="dialog"
          aria-modal="true"
          :aria-label="screenTitle"
        >
          <div v-if="full" class="flex shrink-0 items-center gap-2 bg-white px-3 py-2.5">
            <button type="button" class="grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-slate-700" aria-label="Retour" @click="depth > 1 ? router.back() : close()">
              <AppIcon name="left" class="h-5 w-5" :stroke="2.4" />
            </button>
            <p class="min-w-0 flex-1 truncate px-1 text-[18px] font-extrabold">{{ screenTitle }}</p>
          </div>
          <div v-else class="shrink-0 rounded-t-[26px] bg-white px-3 pb-2 pt-2" @touchstart.passive="dragStart" @touchmove.passive="dragMove" @touchend="dragEnd">
            <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
            <div class="flex items-center gap-2">
              <button v-if="depth > 1" type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-slate-700" aria-label="Retour" @click="router.back()">
                <AppIcon name="left" class="h-5 w-5" :stroke="2.4" />
              </button>
              <p class="min-w-0 flex-1 truncate px-1 text-[16.5px] font-bold">{{ screenTitle }}</p>
              <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-slate-500" aria-label="Fermer" @click="close">✕</button>
            </div>
          </div>
          <div ref="screenBody" class="flex-1 overflow-y-auto overscroll-contain px-4 pb-10 pt-3" @touchstart.passive="full ? null : bodyDragStart($event)" @touchmove.passive="dragMove" @touchend="dragEnd">
            <RouterView />
          </div>
        </div>
      </div>
    </Transition>

    <!-- Indicateur : comment passer d'une page à l'autre (jusqu'au premier glissement du doigt) -->
    <Transition name="hint">
      <div v-if="hint && !screenOpen" class="pointer-events-none fixed inset-x-0 bottom-0 z-30 flex justify-center px-4 pb-[calc(env(safe-area-inset-bottom)+20px)]">
        <div class="pointer-events-auto flex items-center gap-3 rounded-[20px] bg-slate-900/95 py-3 pl-4 pr-3 text-white shadow-2xl" role="status">
          <span class="swipe-hand text-[28px] leading-none" aria-hidden="true">👆</span>
          <span class="min-w-0">
            <b class="block text-[15px] leading-tight">Glissez du doigt ← →</b>
            <span class="text-[13px] opacity-80">pour passer d'une page à l'autre</span>
            <span class="mt-1.5 flex gap-1" aria-hidden="true">
              <i v-for="(p, i) in pages" :key="p.key" :class="['block h-1.5 rounded-full transition-all', i === current ? 'w-4 bg-white' : 'w-1.5 bg-white/40']" />
            </span>
          </span>
          <button type="button" class="ml-1 shrink-0 rounded-full bg-white/15 px-3.5 py-2 text-[14px] font-bold" @click="hintDone">OK</button>
        </div>
      </div>
    </Transition>

    <Toasts />
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, watchEffect } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AppIcon from './AppIcon.vue'
import ColorIcon from './ColorIcon.vue'
import Toasts from '../Toasts.vue'
import { useNotificationStore } from '../../stores/notifications'
import { useRealtime } from '../../composables/useRealtime'
import { pwa } from '../../composables/usePwa'
import { pageTitle } from '../../composables/usePageTitle'

/**
 * Application e-commerçant sur téléphone (comme Jibiat Marchand) : onglets en pastilles dans l'en-tête, pages à glisser du doigt
 * (Courses, Argent, Live, Boutique, Plus). Les autres écrans s'ouvrent dans une fenêtre par-dessus la page,
 * qu'on ferme d'un ✕, en touchant le fond ou en glissant vers le bas.
 */
const props = defineProps({
  base: { type: String, required: true },
  color: { type: String, required: true },
  pages: { type: Array, required: true }, // { key, label, path, component, emoji, badge?() }
  actionCount: { type: Number, default: 0 },
})

const route = useRoute()
const router = useRouter()
const notifications = useNotificationStore()
useRealtime()
if (!notifications.loaded) notifications.fetch().catch(() => {})

watchEffect(() => document.documentElement.style.setProperty('--app-color', props.color))
document.documentElement.classList.add('app-marchand')
// Pas d'actualisation du navigateur en tirant : sur les pages c'est l'application qui actualise, et une fenêtre
// ouverte se referme en la tirant vers le bas sans recharger la page derrière
const rootOverscroll = document.documentElement.style.overscrollBehaviorY
document.documentElement.style.overscrollBehaviorY = 'none'
document.body.style.overscrollBehaviorY = 'none'
onBeforeUnmount(() => {
  document.documentElement.style.overscrollBehaviorY = rootOverscroll
  document.body.style.overscrollBehaviorY = ''
  document.documentElement.style.removeProperty('--app-color')
  document.documentElement.classList.remove('app-marchand')
})

// ── Pages ──
const track = ref(null)
const tabsEl = ref(null)
const current = ref(0)
const seen = reactive(new Set())
const refresh = reactive({})

// ── Tirer pour actualiser la page affichée ──
const PULL_AT = 64
const pull = ref(0)
const reloading = ref(false)
let pullY = null
let pullX = 0
let pullDir = null
function pullStart(e, i) {
  const section = e.currentTarget
  pullY = i === current.value && !reloading.value && section.scrollTop <= 0 ? e.touches[0].clientY : null
  pullX = e.touches[0].clientX
  pullDir = null
}
function pullMove(e) {
  if (pullY === null) return
  const dy = e.touches[0].clientY - pullY
  const dx = e.touches[0].clientX - pullX
  // Le geste de côté change de page : pas d'actualisation
  if (pullDir === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) pullDir = Math.abs(dy) > Math.abs(dx) && dy > 0 ? 'down' : 'other'
  if (pullDir !== 'down') return
  pull.value = Math.min(110, Math.max(0, dy * 0.5))
}
async function pullEnd() {
  const go = pull.value >= PULL_AT
  pullY = null
  pull.value = 0
  if (!go) return
  reloading.value = true
  const key = props.pages[current.value]?.key
  if (key) refresh[key] = (refresh[key] || 0) + 1
  notifications.fetch().catch(() => {})
  setTimeout(() => { reloading.value = false }, 700)
}
function pullCancel() {
  pullY = null
  pull.value = 0
}
const pageIndex = (path) => props.pages.findIndex((p) => p.path === path)
const lastPage = ref(props.pages[0]?.path ?? props.base)

function go(i, smooth = true) {
  const el = track.value
  if (!el) return
  el.scrollTo({ left: i * el.clientWidth, behavior: smooth ? 'smooth' : 'auto' })
  setPage(i)
}
let scrollTimer
// ── Indicateur du geste : affiché tant que l'e-commerçant n'a pas glissé une fois ; les pages « bougent » un peu ──
const HINT_KEY = 'jibiat.swipe-hint-done'
const hint = ref(false)
const peek = ref(false)
let touched = false
function hintDone() {
  hint.value = false
  try {
    localStorage.setItem(HINT_KEY, '1')
  } catch {
    // Sans stockage : l'indicateur reviendra à la prochaine ouverture
  }
}
function showHint() {
  let done = false
  try {
    done = localStorage.getItem(HINT_KEY) === '1'
  } catch {
    done = false
  }
  if (done || props.pages.length < 2) return
  setTimeout(() => {
    hint.value = true
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return
    peek.value = true
    setTimeout(() => { peek.value = false }, 2600)
  }, 900)
}

function onScroll() {
  clearTimeout(scrollTimer)
  scrollTimer = setTimeout(() => {
    const el = track.value
    setPage(Math.round(el.scrollLeft / Math.max(1, el.clientWidth)))
  }, 80)
}
const tabClass = (p, i) => {
  const red = p.badge?.()?.level === 'red'
  if (i === current.value) return red ? 'bg-rose-800 text-white' : 'bg-slate-900 text-white'
  return red ? 'bg-rose-100 text-rose-900' : 'bg-white text-slate-600 shadow-soft'
}
function setPage(i) {
  const p = props.pages[i]
  if (!p) return
  if (touched && i !== current.value) hintDone()
  if (i !== current.value) tabsEl.value?.children[i]?.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' })
  current.value = i
  seen.add(p.key)
  lastPage.value = p.path
  // L'adresse suit la page (retour du téléphone, liens partagés)
  if (route.path !== p.path && !screenOpen.value) router.replace(p.path)
}

// ── Écran par-dessus : toute adresse qui n'est pas une des pages ──
const screenOpen = computed(() => pageIndex(route.path) === -1)
const screenTitle = computed(() => pageTitle.value || route.meta.title || '')
// Écran en plein écran (pas de fenêtre à glisser vers le bas) : « fullscreen » dans la route
const full = computed(() => !!route.meta.fullscreen)
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
  showHint()
})
</script>

<style>
.swipe-track { scrollbar-width: none; }
.swipe-track::-webkit-scrollbar { display: none; }
.swipe-urgent { animation: swipe-pulse 1.6s infinite; }
@keyframes swipe-pulse { 0% { box-shadow: 0 0 0 0 rgba(229,74,110,.55) } 70% { box-shadow: 0 0 0 7px rgba(229,74,110,0) } 100% { box-shadow: 0 0 0 0 rgba(229,74,110,0) } }
/* Indicateur du geste : la main glisse, les pages bougent un peu deux fois */
.swipe-hand { display: inline-block; animation: swipe-hand 1.8s ease-in-out infinite; }
@keyframes swipe-hand { 0%, 100% { transform: translateX(8px) rotate(-8deg) } 50% { transform: translateX(-10px) rotate(-14deg) } }
.swipe-peek > section { animation: swipe-peek 1.3s ease-in-out 2; }
@keyframes swipe-peek { 0%, 100% { transform: translateX(0) } 45% { transform: translateX(-56px) } }
.hint-enter-active, .hint-leave-active { transition: opacity .25s ease, transform .25s ease; }
.hint-enter-from, .hint-leave-to { opacity: 0; transform: translateY(16px); }
@media (prefers-reduced-motion: reduce) { .swipe-urgent, .swipe-hand, .swipe-peek > section { animation: none; } }
.screen-enter-active, .screen-leave-active { transition: background-color .2s ease; }
.screen-enter-active > div, .screen-leave-active > div { transition: transform .25s ease; }
.screen-enter-from, .screen-leave-to { background-color: transparent; }
.screen-enter-from > div, .screen-leave-to > div { transform: translateY(100%); }
</style>
