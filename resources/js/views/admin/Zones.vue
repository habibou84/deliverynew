<template>
  <div class="space-y-4 max-w-3xl">
    <h1 class="text-xl font-bold">Zones de livraison</h1>
    <p class="text-sm text-gray-600">Communes et, si besoin, quartiers. Un quartier sans tarif propre utilise le tarif de sa commune.</p>

    <form class="card p-4 flex flex-wrap gap-2 items-end" @submit.prevent="create">
      <div class="flex-1 min-w-40"><label class="label">Nom</label><input v-model="draft.name" class="input" required placeholder="Ex. : Angré"></div>
      <div class="flex-1 min-w-40">
        <label class="label">Dans la commune</label>
        <select v-model="draft.parent_id" class="input">
          <option :value="null">— (c'est une commune)</option>
          <option v-for="z in communes" :key="z.id" :value="z.id">{{ z.name }}</option>
        </select>
      </div>
      <button class="btn-primary">Ajouter</button>
      <p v-if="error" class="field-error w-full">{{ error }}</p>
    </form>

    <div class="card divide-y">
      <div v-for="z in zones" :key="z.id" :class="['flex items-center justify-between p-3 text-sm', z.parent_id ? 'pl-8' : 'font-medium']">
        <span :class="z.is_active ? '' : 'line-through text-gray-400'">{{ z.parent_id ? '↳ ' : '' }}{{ z.name }}</span>
        <button class="text-blue-600 text-xs" @click="toggle(z)">{{ z.is_active ? 'Désactiver' : 'Réactiver' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'

const zones = ref([])
const draft = reactive({ name: '', parent_id: null })
const error = ref('')
const communes = computed(() => zones.value.filter((z) => !z.parent_id))

async function load() {
  zones.value = (await http.get('/zones', { params: { include_inactive: 1 } })).data.data
}

async function create() {
  error.value = ''
  try {
    await http.post('/zones', draft)
    draft.name = ''
    load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function toggle(zone) {
  await http.patch(`/zones/${zone.id}`, { is_active: !zone.is_active })
  load()
}

onMounted(load)
</script>
