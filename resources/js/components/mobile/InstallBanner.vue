<template>
  <!-- Fenêtre qui s'ouvre toute seule (popup), ou carte dans la page -->
  <BottomSheet v-if="popup" :open="visible && shown" :title="`Installer l'application ${app === 'livreur' ? 'livreur' : ''}`" @close="dismiss">
    <div class="space-y-4 text-left text-slate-900" :style="{ '--app-color': color }">
      <div class="flex items-center gap-3">
        <img :src="`/app-icons/${app}-192.png`" alt="" class="h-16 w-16 rounded-2xl shadow">
        <p class="text-[16px] text-slate-700">Ouvrez-la d'un geste depuis votre écran d'accueil, comme une vraie application, même avec une connexion faible.</p>
      </div>

      <ol v-if="mode === 'ios'" class="space-y-3">
        <li class="flex items-center gap-3 rounded-2xl bg-slate-50 p-3">
          <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-[var(--app-color)] text-white"><ShareIcon big /></span>
          <span class="text-[16px]">Touchez <b>Partager</b> {{ inSafari ? 'en bas de l\'écran' : 'dans la barre d\'adresse' }}.</span>
        </li>
        <li class="flex items-center gap-3 rounded-2xl bg-slate-50 p-3">
          <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-[var(--app-color)] text-[22px] font-bold text-white">＋</span>
          <span class="text-[16px]">Faites défiler et touchez <b>« Sur l'écran d'accueil »</b>.</span>
        </li>
        <li class="flex items-center gap-3 rounded-2xl bg-slate-50 p-3">
          <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-[var(--app-color)] text-[18px] font-bold text-white">✓</span>
          <span class="text-[16px]">Touchez <b>Ajouter</b>, puis ouvrez l'application depuis son icône.</span>
        </li>
        <li v-if="!inSafari" class="text-[14px] text-slate-600">Option absente ? Ouvrez cette page dans <b>Safari</b>.</li>
      </ol>
      <p v-else-if="mode === 'in-app'" class="text-[16px] text-slate-700">Cette page est ouverte dans une autre application. Ouvrez-la dans <b>Chrome</b> (menu <b>⋮</b> puis « Ouvrir dans Chrome ») pour l'installer.</p>
      <p v-else-if="mode === 'samsung'" class="text-[16px] text-slate-700">Touchez le menu <b>≡</b> en bas, puis <b>« Ajouter page à »</b> et <b>« Écran d'accueil »</b>.</p>
      <p v-else-if="mode === 'android'" class="text-[16px] text-slate-700">Touchez le menu <b>⋮</b> en haut à droite, puis <b>« Installer l'application »</b> (ou « Ajouter à l'écran d'accueil »).</p>

      <button v-if="mode === 'prompt'" type="button" class="w-full rounded-2xl py-3.5 text-[17px] font-bold text-white" :style="{ backgroundColor: 'var(--app-color)' }" @click="install">Installer</button>
      <button type="button" class="w-full rounded-2xl bg-slate-100 py-3 text-[16px] font-semibold text-slate-600" @click="dismiss">{{ mode === 'prompt' ? 'Plus tard' : 'J\'ai compris' }}</button>
    </div>
  </BottomSheet>

  <div v-else-if="visible" class="m-card p-4 flex items-start gap-3 text-left text-slate-900" :style="{ '--app-color': color }">
    <img :src="`/app-icons/${app}-192.png`" alt="" class="h-12 w-12 rounded-xl">
    <div class="flex-1 text-sm">
      <p class="font-semibold">Installer l'application {{ app === 'livreur' ? 'livreur' : '' }}</p>

      <p v-if="mode === 'prompt'" class="text-slate-600">Accès en un geste depuis l'écran d'accueil, même avec une connexion faible.</p>

      <template v-else-if="mode === 'ios'">
        <p class="text-slate-600">Sur iPhone, l'installation se fait depuis le menu de partage :</p>
        <ol class="mt-1 space-y-1 text-slate-700 list-decimal list-inside">
          <li>Touchez <strong>Partager</strong> <ShareIcon /> {{ inSafari ? 'en bas de l\'écran' : 'dans la barre d\'adresse' }}.</li>
          <li>Faites défiler et touchez <strong>« Sur l'écran d'accueil »</strong>.</li>
          <li>Touchez <strong>Ajouter</strong>, puis ouvrez l'application depuis son icône.</li>
        </ol>
        <p v-if="!inSafari" class="mt-1 text-xs text-slate-600">Option absente ? Ouvrez cette page dans <strong>Safari</strong>.</p>
      </template>

      <p v-else-if="mode === 'in-app'" class="text-slate-600">
        Cette page est ouverte dans une autre application. Ouvrez-la dans <strong>Chrome</strong> (menu <strong>⋮</strong> puis
        « Ouvrir dans Chrome » ou « Ouvrir dans le navigateur ») pour pouvoir l'installer.
      </p>

      <p v-else-if="mode === 'samsung'" class="text-slate-600">
        Touchez le menu <strong>≡</strong> en bas, puis <strong>« Ajouter page à »</strong> et <strong>« Écran d'accueil »</strong>.
      </p>

      <p v-else class="text-slate-600">
        Touchez le menu <strong>⋮</strong> en haut à droite, puis <strong>« Installer l'application »</strong>
        (ou « Ajouter à l'écran d'accueil »).
      </p>

      <div class="flex gap-2 mt-3">
        <button v-if="mode === 'prompt'" class="rounded-xl px-4 py-2 font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }" @click="install">Installer</button>
        <button class="rounded-xl px-4 py-2 font-medium text-slate-600 bg-slate-100" @click="dismiss">Plus tard</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, h, onBeforeUnmount, onMounted, ref } from 'vue'
import { pwa, promptInstall } from '../../composables/usePwa'
import BottomSheet from './BottomSheet.vue'

const props = defineProps({ app: { type: String, required: true }, popup: Boolean })

const color = computed(() => (props.app === 'livreur' ? '#1d4ed8' : '#047857'))

// Icône « Partager » d'iOS : carré ouvert surmonté d'une flèche
const ShareIcon = (p) => h('svg', { viewBox: '0 0 24 24', class: p?.big !== undefined && p.big !== false ? 'h-6 w-6' : 'inline h-4 w-4 align-text-bottom', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'aria-label': 'icône Partager' }, [
  h('path', { d: 'M12 3v12M8 7l4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }),
])

const ua = window.navigator.userAgent
const isAndroid = /android/i.test(ua)
// Navigateurs intégrés (Facebook, Instagram, WebView d'une autre application) : installation impossible
const isInApp = /FBAN|FBAV|Instagram|Line\/|; wv\)/i.test(ua)
const isSamsung = /SamsungBrowser/i.test(ua)
const inSafari = pwa.isIos && !/CriOS|FxiOS|EdgiOS|OPiOS/i.test(ua)

/**
 * Comment installer sur cet appareil :
 *  - prompt : Chrome/Edge proposent l'installation (bouton « Installer ») ;
 *  - ios : pas d'invite sur iPhone, installation par le menu Partager ;
 *  - in-app, samsung, android : instructions du navigateur (Chrome n'envoie son
 *    invite qu'après un moment d'utilisation, et jamais dans certains navigateurs).
 */
const mode = computed(() => {
  if (pwa.canInstall) return 'prompt'
  if (pwa.isIos) return 'ios'
  if (!isAndroid) return null
  if (isInApp) return 'in-app'
  if (isSamsung) return 'samsung'
  return 'android'
})

const key = `install-dismissed-${props.app}`
const dismissed = ref(readDismissed())

function readDismissed() {
  try {
    return Number(localStorage.getItem(key) || 0) > Date.now()
  } catch {
    return false
  }
}

// Ordinateur sans invite d'installation : rien à proposer
const visible = computed(() => !pwa.installed && !dismissed.value && mode.value !== null)

// Fenêtre : ouverte quelques secondes après l'arrivée, pour ne pas couper l'écran au chargement
const shown = ref(false)
let timer = null
onMounted(() => { if (props.popup) timer = setTimeout(() => { shown.value = true }, 2500) })
onBeforeUnmount(() => clearTimeout(timer))

async function install() {
  if (await promptInstall()) dismiss()
}

function dismiss() {
  dismissed.value = true
  try {
    localStorage.setItem(key, String(Date.now() + 7 * 24 * 3600 * 1000)) // reproposé dans 7 jours
  } catch {
    // stockage indisponible (navigation privée) : masqué pour cette session seulement
  }
}
</script>
