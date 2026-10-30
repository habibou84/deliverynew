<template>
  <section class="card p-4 space-y-4">
    <div class="flex flex-wrap items-center gap-2">
      <div class="flex-1 min-w-[16rem]">
        <h2 class="font-semibold">Prix par destination (depuis partout)</h2>
        <p class="text-sm text-gray-600">
          Regroupez des lieux et donnez un prix au groupe : c'est le prix d'une livraison vers ces lieux, d'où que parte le
          colis. Une commune ajoutée entière couvre ses quartiers ; un quartier placé dans un autre groupe prend le prix de ce groupe.
        </p>
      </div>
      <button v-if="!editing" type="button" class="btn-primary" @click="startNew">+ Nouveau groupe</button>
    </div>

    <!-- Formulaire d'un groupe -->
    <div v-if="editing" class="space-y-3 rounded-lg border border-emerald-200 bg-emerald-50/50 p-3">
      <div class="flex flex-wrap gap-3">
        <div class="flex-1 min-w-[12rem]">
          <label class="label" for="group-name">Nom du groupe</label>
          <input id="group-name" v-model="editing.name" class="input" placeholder="Abidjan centre">
        </div>
        <div>
          <label class="label" for="group-price">Prix (F)</label>
          <input id="group-price" v-model.number="editing.price" type="number" min="0" inputmode="numeric" class="input w-32">
        </div>
      </div>
      <div>
        <label class="label" for="group-zone">Lieux</label>
        <div class="flex flex-wrap gap-1.5">
          <span v-for="z in picked" :key="z.id" :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-sm', z.parent_id ? 'bg-amber-100 text-amber-900' : 'bg-indigo-50 text-indigo-900']">
            {{ z.parent_id ? z.full_name : `${z.name} (toute la commune)` }}
            <button type="button" class="font-bold" :aria-label="`Retirer ${z.name}`" @click="remove(z.id)">×</button>
          </span>
        </div>
        <div class="relative mt-2">
          <input id="group-zone" v-model="query" class="input" placeholder="Ajouter une commune ou un quartier…" autocomplete="off" @keydown.enter.prevent="add(matches[0])">
          <ul v-if="query && matches.length" class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded border bg-white shadow">
            <li v-for="z in matches" :key="z.id">
              <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50" @click="add(z)">
                {{ z.full_name }}<span v-if="ownerOf(z.id)" class="text-xs text-gray-500"> · déjà dans « {{ ownerOf(z.id) }} »</span>
              </button>
            </li>
          </ul>
        </div>
        <p class="mt-1 text-xs text-gray-500">Un lieu déjà dans un autre groupe y est retiré pour venir ici.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-primary" :disabled="busy || !editing.name || editing.price === '' || !picked.length" @click="save">Enregistrer le groupe</button>
        <button type="button" class="btn-secondary" @click="editing = null">Annuler</button>
        <button v-if="editing.id" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="destroy">Supprimer le groupe</button>
      </div>
    </div>

    <p v-if="!state" class="text-sm text-gray-500">Chargement…</p>
    <p v-else-if="!state.groups.length && !editing" class="rounded-lg bg-slate-50 p-3 text-sm text-gray-600">
      Aucun groupe pour l'instant : les prix viennent seulement des trajets particuliers ci-dessous.
    </p>
    <div v-else-if="state.groups.length" class="divide-y rounded-lg border">
      <div v-for="g in state.groups" :key="g.id" class="flex flex-wrap items-center gap-3 p-3">
        <div class="min-w-[10rem] font-semibold">{{ g.name }}</div>
        <div class="flex flex-1 flex-wrap gap-1.5">
          <span v-for="z in g.zones" :key="z.id" :class="['rounded-full px-2.5 py-1 text-xs', z.is_neighborhood ? 'bg-amber-100 text-amber-900' : 'bg-indigo-50 text-indigo-900']">{{ z.is_neighborhood ? z.full_name : z.name }}</span>
        </div>
        <div class="w-24 text-right font-bold tabular-nums">{{ money(g.price) }}</div>
        <button type="button" class="btn-secondary" @click="edit(g)">Modifier</button>
      </div>
    </div>

    <p v-if="state?.missing.length" class="text-sm text-gray-700">
      <strong>Sans prix par destination :</strong>
      {{ state.missing.map((m) => m.quartiers ? `${m.name} (et ses ${m.quartiers} quartiers)` : m.name).join(', ') }}.
      <span class="text-gray-500">Un trajet particulier peut quand même leur donner un prix.</span>
    </p>

    <!-- Vérifier un prix -->
    <div class="space-y-2 rounded-lg bg-slate-50 p-3">
      <p class="text-sm font-semibold">Vérifier un prix</p>
      <div class="grid gap-2 sm:grid-cols-2">
        <div>
          <label class="label" for="check-from">Départ</label>
          <ZoneSelect id="check-from" v-model="check.from" :zones="activeZones" />
        </div>
        <div>
          <label class="label" for="check-to">Arrivée</label>
          <ZoneSelect id="check-to" v-model="check.to" :zones="activeZones" />
        </div>
      </div>
      <p v-if="checked" class="text-sm text-emerald-900">
        <strong class="text-lg">{{ money(checked.total) }}</strong>
        <span class="block">{{ checked.source === 'group' ? `Prix par destination : groupe « ${checked.group} »` : `Trajet particulier : ${checked.rule?.join(' → ')}` }}</span>
      </p>
      <p v-else-if="checkError" class="text-sm text-red-700">{{ checkError }}</p>
      <p class="text-xs text-gray-500">Ordre : trajet particulier (quartier, puis commune), puis prix par destination (quartier, puis commune), puis suppléments.</p>
    </div>
  </section>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import ZoneSelect from './ZoneSelect.vue'
import { useToastStore } from '../stores/toasts'
import { money } from '../utils/format'

/**
 * Réglages › Tarifs : groupes de lieux et leur prix « depuis partout », lieux encore sans prix, vérification d'un prix.
 */
const props = defineProps({ gridId: { type: Number, required: true }, zones: { type: Array, required: true } })

const toasts = useToastStore()
const state = ref(null)
const editing = ref(null)
const query = ref('')
const busy = ref(false)
const check = reactive({ from: null, to: null })
const checked = ref(null)
const checkError = ref('')

const activeZones = computed(() => props.zones.filter((z) => z.is_active))
const picked = computed(() => (editing.value?.zone_ids ?? []).map((id) => props.zones.find((z) => z.id === id)).filter(Boolean))
const owners = computed(() => Object.fromEntries((state.value?.groups ?? []).flatMap((g) => g.zones.map((z) => [z.id, g]))))
const matches = computed(() => {
  const q = norm(query.value)
  if (!q) return []
  return activeZones.value.filter((z) => !editing.value.zone_ids.includes(z.id) && norm(z.full_name).includes(q)).slice(0, 12)
})

function norm(s) {
  return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

function ownerOf(id) {
  const g = owners.value[id]
  return g && g.id !== editing.value?.id ? g.name : null
}

async function load() {
  try {
    state.value = (await http.get(`/pricing-grids/${props.gridId}/groups`)).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function startNew() {
  editing.value = { id: null, name: '', price: '', zone_ids: [] }
}

function edit(g) {
  editing.value = { id: g.id, name: g.name, price: g.price, zone_ids: g.zones.map((z) => z.id) }
}

function add(z) {
  if (!z) return
  editing.value.zone_ids.push(z.id)
  query.value = ''
}

function remove(id) {
  editing.value.zone_ids = editing.value.zone_ids.filter((x) => x !== id)
}

async function save() {
  busy.value = true
  const { id, ...body } = editing.value
  try {
    if (id) await http.put(`/pricing-grids/${props.gridId}/groups/${id}`, body)
    else await http.post(`/pricing-grids/${props.gridId}/groups`, body)
    editing.value = null
    toasts.success('Groupe enregistré.')
    await load()
    runCheck()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function destroy() {
  if (!window.confirm(`Supprimer le groupe « ${editing.value.name} » ? Ses lieux n'auront plus ce prix.`)) return
  busy.value = true
  try {
    await http.delete(`/pricing-grids/${props.gridId}/groups/${editing.value.id}`)
    editing.value = null
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function runCheck() {
  checked.value = null
  checkError.value = ''
  if (!check.from || !check.to) return
  try {
    checked.value = (await http.get(`/pricing-grids/${props.gridId}/check`, { params: { pickup_zone_id: check.from, delivery_zone_id: check.to } })).data.data
  } catch (e) {
    checkError.value = apiErrorMessage(e)
  }
}

watch(() => [check.from, check.to], runCheck)
watch(() => props.gridId, () => {
  editing.value = null
  load()
  runCheck()
}, { immediate: true })

defineExpose({ reload: () => { load(); runCheck() } })
</script>
