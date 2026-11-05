<template>
  <section v-if="items?.length" class="rounded-xl bg-blue-900 px-4 py-3 text-white print:border print:bg-white print:text-black" aria-label="À retenir">
    <p class="mb-1.5 text-xs font-extrabold uppercase tracking-wider text-blue-200 print:text-slate-600">📌 À retenir</p>
    <ul class="list-disc space-y-1 pl-5 text-[15px]">
      <li v-for="(line, i) in items" :key="i" :class="line.level === 'bad' ? 'marker:text-red-300' : line.level === 'good' ? 'marker:text-emerald-300' : ''">
        <template v-for="(part, j) in parts(line.text)" :key="j"><b v-if="part.bold">{{ part.text }}</b><template v-else>{{ part.text }}</template></template>
        <RouterLink v-if="line.to" :to="line.to" class="ml-1 font-semibold text-amber-200 hover:underline print:hidden">Voir ›</RouterLink>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { RouterLink } from 'vue-router'

/** « À retenir » en haut de chaque onglet : phrases calculées par le serveur (**gras**) */
defineProps({ items: { type: Array, default: () => [] } })

const parts = (text) => text.split('**').map((t, i) => ({ text: t, bold: i % 2 === 1 }))
</script>
