<template>
  <!-- Colis en retour : les courses du même e-commerçant qui attendent le même article -->
  <section v-if="candidates.length" id="reassign" class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200 space-y-3">
    <div>
      <p class="font-semibold text-emerald-900">Réaffecter à une autre commande</p>
      <p class="text-sm text-emerald-900/90">Ce colis peut partir chez un client qui a commandé le même article, au lieu de revenir à la boutique : pas de retour ni de nouveau ramassage.</p>
    </div>
    <div v-for="c in candidates" :key="c.id" class="rounded-xl bg-white p-3 ring-1 ring-emerald-100 space-y-2">
      <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
        <p class="min-w-0"><b>{{ c.recipient_name || 'Client' }}</b><span class="text-slate-600"> · {{ c.zone }}</span></p>
        <span class="font-mono text-xs text-slate-600">{{ c.tracking_code }}</span>
      </div>
      <p class="text-sm text-slate-700">{{ c.content || '—' }}<span class="text-slate-500"> · {{ c.status_label }}</span></p>
      <div v-if="confirming === c.id" class="flex flex-wrap gap-2">
        <button type="button" class="btn-success" :disabled="busy" @click="reassign(c)">{{ busy ? '…' : `Oui, envoyer à ${c.recipient_name || 'ce client'}` }}</button>
        <button type="button" class="btn-secondary" @click="confirming = null">Annuler</button>
      </div>
      <button v-else type="button" class="btn-secondary w-full sm:w-auto" @click="confirming = c.id">Envoyer ce colis à {{ c.recipient_name || 'ce client' }}</button>
    </div>
    <p class="text-xs text-emerald-900/80">Le livreur colle ensuite la nouvelle étiquette sur le colis.</p>
  </section>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'

/**
 * Réaffectation d'un colis en retour (refusé, tentatives épuisées…) à une course du même e-commerçant qui attend le
 * même article. Rien n'est affiché sans course qui correspond.
 */
const props = defineProps({ order: { type: Object, required: true } })
const emit = defineEmits(['done'])

const RETURNING = ['delivery_failed', 'rescheduled', 'at_hub', 'return_assigned', 'returning']
const toasts = useToastStore()
const candidates = ref([])
const confirming = ref(null)
const busy = ref(false)

async function load() {
  const o = props.order
  const returning = RETURNING.includes(o.status) && (o.return_requested || ['return_assigned', 'returning'].includes(o.status)) && !o.from_warehouse
  if (!returning) {
    candidates.value = []
    return
  }
  try {
    candidates.value = (await http.get(`/orders/${o.id}/reassign-candidates`)).data.data
  } catch {
    candidates.value = []
  }
}

async function reassign(c) {
  busy.value = true
  try {
    const { data } = await http.post(`/orders/${props.order.id}/reassign`, { order_id: c.id })
    toasts.success(`Colis réaffecté à ${c.tracking_code} : il part chez ${c.recipient_name || 'le client'}.`)
    emit('done', data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
    confirming.value = null
  }
}

watch(() => [props.order.id, props.order.status, props.order.return_requested], load)
onMounted(load)
</script>
