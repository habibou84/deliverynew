<template>
  <section v-if="loaded && (data.awaiting.length || data.done.length)" class="card">
    <header class="p-4 border-b">
      <h2 class="font-semibold">🤝 Relais entre livreurs</h2>
      <p class="text-sm text-gray-600">Colis ramassés par un livreur et livrés par un autre : ils se les remettent avec un code à 4 chiffres. Sans relais, le livreur ne peut pas partir livrer.</p>
    </header>

    <div v-if="data.awaiting.length" class="divide-y">
      <div v-for="g in data.awaiting" :key="`${g.from.courier_id}-${g.to.courier_id}`" class="p-4 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <p class="font-medium">{{ g.from.name }} → {{ g.to.name }} · {{ g.parcels.length }} colis</p>
          <p class="text-sm text-gray-600">
            <a v-if="g.from.phone" :href="telLink(g.from.phone)" class="text-blue-600">{{ g.from.phone }}</a>
            <template v-if="g.to.phone"> · <a :href="telLink(g.to.phone)" class="text-blue-600">{{ g.to.phone }}</a></template>
            · <span :class="g.code_given_at ? 'text-emerald-700' : 'text-amber-700'">{{ g.code_given_at ? 'code affiché, en cours' : 'pas encore commencé' }}</span>
          </p>
          <p class="text-xs text-gray-500 truncate">{{ g.parcels.map((p) => p.tracking_code).join(', ') }}</p>
        </div>
        <button type="button" class="btn-secondary text-xs" @click="open(g)">Valider à leur place</button>
      </div>
    </div>
    <p v-else class="p-4 text-sm text-emerald-700">✅ Aucun relais en attente.</p>

    <details v-if="data.done.length" class="border-t">
      <summary class="cursor-pointer p-4 text-sm font-medium">Relais faits aujourd'hui ({{ data.done.length }})</summary>
      <ul class="px-4 pb-4 space-y-1 text-sm">
        <li v-for="r in data.done" :key="r.id">
          {{ time(r.confirmed_at) }} · {{ r.from.name }} → {{ r.to.name }} · {{ r.received }} colis
          <span v-if="r.missing" class="text-red-600">· {{ r.missing }} non reçu(s)</span>
          <span v-if="r.validated_by" class="text-gray-500">· validé par {{ r.validated_by }} ({{ r.reason }})</span>
        </li>
      </ul>
    </details>

    <Modal :open="!!target" title="Valider le relais à leur place" @close="target = null">
      <form v-if="target" class="space-y-3" @submit.prevent="validate">
        <p class="text-sm">Les {{ target.parcels.length }} colis passent de <b>{{ target.from.name }}</b> à <b>{{ target.to.name }}</b>. À faire seulement si le relais a bien eu lieu (téléphone en panne, pas de réseau) : appelez les deux livreurs avant.</p>
        <div>
          <label class="label" for="relay-reason">Motif *</label>
          <input id="relay-reason" v-model="reason" class="input" required minlength="3" maxlength="255" placeholder="Ex. téléphone de Koffi déchargé">
        </div>
        <p v-if="error" class="field-error">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="target = null">Annuler</button>
          <button class="btn-primary" :disabled="busy || reason.trim().length < 3">Valider le relais</button>
        </div>
      </form>
    </Modal>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import { useToastStore } from '../../stores/toasts'
import { telLink } from '../../utils/format'

/** Dispatch : relais à faire entre livreurs, relais du jour, validation à la place des livreurs */
const emit = defineEmits(['changed'])
const toasts = useToastStore()
const data = ref({ awaiting: [], done: [] })
const loaded = ref(false)
const target = ref(null)
const reason = ref('')
const error = ref('')
const busy = ref(false)
const time = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })

async function load() {
  try {
    data.value = (await http.get('/relays')).data.data
  } catch {
    // Sans droit de dispatch : le bloc reste caché
  } finally {
    loaded.value = true
  }
}

function open(g) {
  target.value = g
  reason.value = ''
  error.value = ''
}

async function validate() {
  busy.value = true
  error.value = ''
  try {
    const g = target.value
    await http.post('/relays/validate', { from_courier_id: g.from.courier_id, to_courier_id: g.to.courier_id, order_ids: g.parcels.map((p) => p.id), reason: reason.value })
    toasts.success(`🤝 Relais validé : ${g.parcels.length} colis chez ${g.to.name}.`)
    target.value = null
    await load()
    emit('changed')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(load)
defineExpose({ load })
</script>
