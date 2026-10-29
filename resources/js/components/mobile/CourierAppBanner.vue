<template>
  <!-- Application Android : nouvelle version à installer -->
  <a v-if="update" :href="update.page_url" target="_blank" rel="noopener" class="m-card mb-3 flex items-center gap-3 p-3 text-sm">
    <span class="text-2xl" aria-hidden="true">⬇️</span>
    <span class="flex-1"><strong class="block">Nouvelle version de l'application</strong>Version {{ update.version_name }} : touchez pour l'installer.</span>
  </a>
  <!-- Navigateur d'un téléphone Android : l'application fait sonner le téléphone pour chaque mission -->
  <div v-else-if="suggest" class="m-card mb-3 flex items-center gap-3 p-3 text-sm">
    <span class="text-2xl" aria-hidden="true">🔔</span>
    <a :href="suggest.page_url" target="_blank" rel="noopener" class="flex-1">
      <strong class="block">Installez l'application Jibiat Livreur</strong>Le téléphone sonne comme un appel à chaque nouvelle mission.
    </a>
    <button type="button" class="h-10 w-10 shrink-0 rounded-full text-slate-500 active:bg-slate-100" aria-label="Masquer" @click="dismiss">✕</button>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { App } from '@capacitor/app'
import { Capacitor } from '@capacitor/core'
import http from '../../bootstrap/axios'
import { nativeApp } from '../../native'

/**
 * Application Android des livreurs : mise à jour annoncée dans l'application, installation proposée dans le
 * navigateur d'un téléphone Android (masquable une semaine).
 */
const KEY = 'courier-app-suggest-hidden-until'
const update = ref(null)
const suggest = ref(null)

onMounted(async () => {
  const native = nativeApp === 'livreur' && Capacitor.isNativePlatform()
  if (!native && !/Android/i.test(navigator.userAgent)) return
  let latest
  try {
    latest = (await http.get('/courier/app')).data.data
  } catch {
    return
  }
  if (!latest) return

  if (native) {
    const info = await App.getInfo().catch(() => null)
    if (info && Number(info.build) < latest.version_code) update.value = latest
    return
  }
  let hiddenUntil = 0
  try {
    hiddenUntil = Number(localStorage.getItem(KEY) || 0)
  } catch {
    hiddenUntil = 0
  }
  if (Date.now() > hiddenUntil) suggest.value = latest
})

function dismiss() {
  suggest.value = null
  try {
    localStorage.setItem(KEY, String(Date.now() + 7 * 86400000))
  } catch {
    // stockage indisponible : le bandeau reviendra
  }
}
</script>
