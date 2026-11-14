<template>
  <!-- Réglages qui manquent pour que les courses soient assignées automatiquement : bandeau qui appelle à l'action -->
  <section v-if="items.length && !hidden" class="card border-l-4 border-orange-300 border-l-orange-500 bg-orange-50 p-3 sm:p-4" role="region" aria-label="Réglages à faire">
    <div class="flex flex-wrap items-start gap-3">
      <span class="text-2xl" aria-hidden="true">⚠️</span>
      <div class="min-w-[14rem] flex-1">
        <p class="font-semibold text-orange-900">
          {{ items.length }} réglage{{ items.length > 1 ? 's' : '' }} à faire pour que les courses soient assignées automatiquement aux livreurs
        </p>
        <p v-if="!expanded" class="text-sm text-orange-800"><b>{{ items[0].text }}</b> : {{ items[0].why }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="rounded-lg bg-orange-600 px-3 py-2 text-sm font-semibold text-white hover:bg-orange-700" @click="act(items[0])">{{ items[0].action }}</button>
        <button v-if="items.length > 1" type="button" class="rounded-lg border border-orange-300 bg-white px-3 py-2 text-sm text-orange-900" :aria-expanded="expanded" @click="expanded = !expanded">
          {{ expanded ? 'Masquer' : `Voir les ${items.length} réglages` }}
        </button>
        <button type="button" class="px-1 text-sm text-orange-500 hover:text-orange-700" title="Cacher jusqu'à demain" @click="later">Plus tard ✕</button>
      </div>
    </div>

    <ul v-if="expanded" class="mt-3 space-y-2">
      <li v-for="i in items" :key="i.key" class="flex flex-wrap items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm ring-1 ring-orange-200">
        <span class="min-w-[12rem] flex-1"><b class="text-orange-900">{{ i.text }}</b><span class="block text-slate-600">{{ i.why }}</span></span>
        <button type="button" class="rounded-lg border border-orange-300 px-3 py-1.5 font-medium text-orange-900 hover:bg-orange-100" @click="act(i)">{{ i.action }}</button>
      </li>
    </ul>

    <Teleport to="body">
      <div v-if="block" class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Réglage" @keydown.esc="block = null">
        <div class="absolute inset-0 bg-slate-900/30" @click="block = null" />
        <aside class="absolute inset-x-0 bottom-0 flex max-h-[88vh] flex-col rounded-t-3xl bg-white shadow-2xl md:inset-y-0 md:left-auto md:right-0 md:max-h-none md:w-[440px] md:rounded-none">
          <div class="flex items-start justify-between gap-2 border-b px-5 py-4">
            <div>
              <p class="text-xs font-medium uppercase tracking-wide text-slate-600">⚙️ Assignation automatique</p>
              <h2 class="text-lg font-bold">{{ current?.text }}</h2>
            </div>
            <button type="button" class="rounded-lg px-2 py-1 text-slate-600 hover:bg-slate-100" aria-label="Fermer" @click="block = null">✕</button>
          </div>
          <SettingsPanelBody :blocks="[block]" more="/admin/reglages/courses" @saved="saved" @close="block = null" />
        </aside>
      </div>
    </Teleport>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import SettingsPanelBody from './settings/SettingsPanelBody.vue'

const emit = defineEmits(['saved'])
const auth = useAuthStore()
const router = useRouter()
const items = ref([])
const expanded = ref(false)
const block = ref(null)
const hidden = ref(false)
const current = computed(() => items.value.find((i) => i.block === block.value))

// « Plus tard » : caché pour la journée, pour cet utilisateur
const today = new Date().toLocaleDateString('sv-SE')
const storeKey = computed(() => `auto-assign-later:${auth.user?.id}`)

function act(item) {
  if (item.block) block.value = item.block
  else if (item.to) router.push(item.to)
}

function later() {
  hidden.value = true
  try {
    localStorage.setItem(storeKey.value, today)
  } catch {
    // Stockage indisponible : caché jusqu'au prochain chargement
  }
}

async function load() {
  try {
    items.value = (await http.get('/reports/auto-assign-checks')).data.data
  } catch {
    items.value = []
  }
}

async function saved(form) {
  block.value = null
  await load()
  emit('saved', form)
}

onMounted(() => {
  if (!auth.can('settings.manage') || !auth.user?.company_id) return
  try {
    hidden.value = localStorage.getItem(storeKey.value) === today
  } catch {
    hidden.value = false
  }
  load()
})
</script>
