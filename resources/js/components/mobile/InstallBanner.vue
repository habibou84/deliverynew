<template>
  <div v-if="visible" class="m-card p-4 flex items-start gap-3">
    <img :src="`/icons/${app}-192.png`" alt="" class="h-12 w-12 rounded-xl">
    <div class="flex-1 text-sm">
      <p class="font-semibold">Installer l'application</p>
      <p v-if="pwa.canInstall" class="text-slate-600">Accès en un geste depuis l'écran d'accueil, même avec une connexion faible.</p>
      <p v-else class="text-slate-600">Sur iPhone : touchez <strong>Partager</strong> <span aria-hidden="true">⎋</span> puis <strong>« Sur l'écran d'accueil »</strong>.</p>
      <div class="flex gap-2 mt-3">
        <button v-if="pwa.canInstall" class="rounded-xl px-4 py-2 font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }" @click="install">Installer</button>
        <button class="rounded-xl px-4 py-2 font-medium text-slate-600 bg-slate-100" @click="dismiss">Plus tard</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { pwa, promptInstall } from '../../composables/usePwa'

const props = defineProps({ app: { type: String, required: true } })

const key = `install-dismissed-${props.app}`
const dismissed = ref(readDismissed())

function readDismissed() {
  try {
    return Number(localStorage.getItem(key) || 0) > Date.now()
  } catch {
    return false
  }
}

// Visible si l'app n'est pas installée et qu'on peut l'installer (Android) ou expliquer comment (iPhone)
const visible = computed(() => !pwa.installed && !dismissed.value && (pwa.canInstall || pwa.isIos))

async function install() {
  await promptInstall()
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
