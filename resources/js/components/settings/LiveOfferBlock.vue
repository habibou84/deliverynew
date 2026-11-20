<template>
  <!-- Outil live offert aux e-commerçants (payé par l'entreprise sur sa facture Jibiat) -->
  <section v-if="live?.enabled" class="card space-y-2 p-4 text-sm">
    <h2 class="font-semibold">Outil live</h2>
    <label class="flex items-start gap-2">
      <input type="checkbox" class="mt-1" :checked="live.live_offered" :disabled="busy" @change="save($event.target.checked)">
      <span>
        <b>Offrir l'outil live à mes e-commerçants</b>
        <span class="block text-xs text-slate-600">
          Sinon, ils paient eux-mêmes à Jibiat chaque commande reçue en live, par packs ({{ live.free_orders }} commandes offertes au départ, puis de {{ live.packs?.[2]?.unit }} à {{ live.packs?.[0]?.unit }} F la commande selon le pack), ou {{ live.price_month }} F pour 30 jours sans limite.
          Offert : c'est votre entreprise qui paie leurs commandes en live ({{ live.unit_price }} F la commande), sur votre facture Jibiat. Plus de lives, plus de livraisons.
        </span>
      </span>
    </label>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'

const toasts = useToastStore()
const live = ref(null)
const busy = ref(false)

async function save(value) {
  busy.value = true
  try {
    live.value.live_offered = (await http.put('/company/live-offer', { live_offered: value })).data.data.live_offered
    toasts.success('Enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  http.get('/company/live-offer').then(({ data: r }) => { live.value = r.data }).catch(() => {})
})
</script>
