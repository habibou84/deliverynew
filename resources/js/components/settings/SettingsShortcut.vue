<template>
  <!-- Réglages de la page : résumé en étiquettes et bouton ⚙️ qui ouvre le panneau (à droite sur ordinateur, en bas sur téléphone) -->
  <div v-if="allowed" class="flex flex-wrap items-center gap-2 text-xs">
    <button
      v-for="[label, value] in summary" :key="label" type="button"
      class="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1 ring-1 ring-slate-200 hover:ring-slate-400"
      :title="`Régler : ${label}`" @click="open = true"
    >
      <span class="text-gray-500">{{ label }}</span> <strong>{{ value }}</strong>
    </button>
    <button type="button" class="btn-secondary ml-auto !py-1.5" :aria-expanded="open" @click="open = true">⚙️ Réglages</button>

    <Teleport to="body">
      <div v-if="open" class="fixed inset-0 z-50" role="dialog" aria-modal="true" :aria-label="`Réglages : ${title}`" @keydown.esc="close">
        <div class="absolute inset-0 bg-slate-900/30" @click="close" />
        <aside class="absolute inset-x-0 bottom-0 flex max-h-[88vh] flex-col rounded-t-3xl bg-white shadow-2xl md:inset-y-0 md:left-auto md:right-0 md:max-h-none md:w-[440px] md:rounded-none">
          <div class="mx-auto mt-2 h-1.5 w-10 rounded-full bg-slate-300 md:hidden" />
          <div class="flex items-start justify-between gap-2 border-b px-5 py-4">
            <div>
              <p class="text-xs font-medium uppercase tracking-wide text-gray-400">⚙️ Réglages de la page</p>
              <h2 class="text-lg font-bold">{{ title }}</h2>
              <p v-if="intro" class="text-xs text-gray-500">{{ intro }}</p>
            </div>
            <button ref="closeButton" type="button" class="rounded-lg px-2 py-1 text-gray-400 hover:bg-slate-100" aria-label="Fermer" @click="close">✕</button>
          </div>
          <SettingsPanelBody :blocks="blocks" :more="more" @dirty="dirty = $event" @saved="saved" @close="open = false" />
        </aside>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import http from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { BLOCKS, fieldsOf } from './blocks'
import SettingsPanelBody from './SettingsPanelBody.vue'

const props = defineProps({
  blocks: { type: Array, required: true },
  title: { type: String, required: true },
  intro: { type: String, default: '' },
  more: { type: String, default: '/admin/reglages' },
})

const auth = useAuthStore()
// Réglages réservés à qui peut les modifier, dans l'espace d'une entreprise
const allowed = computed(() => auth.can('settings.manage') && !!auth.user?.company_id)
const open = ref(false)
const dirty = ref(false)
const values = ref(null)
const closeButton = ref(null)

const summary = computed(() => (values.value ? props.blocks.flatMap((k) => BLOCKS[k].summary(values.value)) : []))

function close() {
  if (dirty.value && !window.confirm('Des modifications ne sont pas enregistrées. Fermer quand même ?')) return
  open.value = false
  dirty.value = false
}

const emit = defineEmits(['saved'])

function saved(form) {
  values.value = { ...values.value, ...form }
  dirty.value = false
  emit('saved', form)
}

watch(open, async (v) => {
  if (v) {
    await nextTick()
    closeButton.value?.focus()
  }
})

onMounted(async () => {
  if (!allowed.value) return
  try {
    const { data } = await http.get(`/companies/${auth.user.company_id}`)
    values.value = Object.fromEntries(fieldsOf(props.blocks).map((f) => [f, data.data[f]]))
  } catch {
    // Résumé indisponible : le bouton ⚙️ reste utilisable
  }
})
</script>
