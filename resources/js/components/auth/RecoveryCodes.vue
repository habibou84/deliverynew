<template>
  <div class="space-y-3">
    <p class="font-semibold text-slate-800">🗝️ Vos codes de secours</p>
    <p class="text-sm text-slate-600">
      Si vous perdez votre téléphone, chacun de ces codes remplace <b>une fois</b> le code de l'application.
      Notez-les ou enregistrez-les dans un endroit sûr : ils ne seront plus affichés.
    </p>
    <ul class="grid grid-cols-2 gap-2 rounded-lg bg-slate-100 p-3 text-center font-mono text-base text-slate-800">
      <li v-for="c in codes" :key="c" class="select-all">{{ c }}</li>
    </ul>
    <div class="flex flex-wrap gap-2">
      <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" @click="copy">📋 Copier</button>
      <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" @click="download">⬇️ Télécharger</button>
    </div>
  </div>
</template>

<script setup>
import { useToastStore } from '../../stores/toasts'

/** Codes de secours de la connexion en deux étapes (affichés une seule fois) */
const props = defineProps({ codes: { type: Array, required: true } })
const toasts = useToastStore()

const text = () => `Codes de secours Jibiat Console (chacun ne sert qu'une fois)\n\n${props.codes.join('\n')}\n`

async function copy() {
  try {
    await navigator.clipboard.writeText(text())
    toasts.success('Codes copiés.')
  } catch {
    toasts.error('Copie impossible : notez les codes à la main.')
  }
}

function download() {
  const url = URL.createObjectURL(new Blob([text()], { type: 'text/plain' }))
  const a = Object.assign(document.createElement('a'), { href: url, download: 'jibiat-console-codes-de-secours.txt' })
  a.click()
  URL.revokeObjectURL(url)
}
</script>
