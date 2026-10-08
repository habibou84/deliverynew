<template>
  <div class="space-y-4">
    <div class="card p-3 flex items-center justify-between">
      <div>
        <p class="font-semibold">{{ auth.user?.name }}</p>
        <p class="text-xs text-gray-500">{{ available ? 'En service' : 'Hors service' }}</p>
      </div>
      <button :class="available ? 'btn-success' : 'btn-secondary'" @click="toggleAvailability">
        {{ available ? '🟢 Disponible' : '⚪ Indisponible' }}
      </button>
    </div>

    <div class="flex gap-2">
      <button v-for="t in tabs" :key="t.value" :class="['btn flex-1', tab === t.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']" @click="tab = t.value">
        {{ t.label }} <span class="ml-1 rounded-full bg-black/10 px-1.5 text-xs">{{ count(t.value) }}</span>
      </button>
    </div>

    <p v-if="loading" class="text-center text-gray-500 py-8">Chargement…</p>
    <p v-else-if="!visible.length" class="text-center text-gray-500 py-8">Aucune mission {{ tab === 'pickup' ? 'de ramassage' : 'de livraison' }} pour le moment.</p>

    <RouterLink
      v-for="m in visible"
      :key="m.id"
      :to="`/livreur/missions/${m.id}`"
      class="card p-4 block space-y-1 active:bg-slate-50"
    >
      <div class="flex items-center justify-between gap-2">
        <span class="font-mono text-xs">{{ m.order.tracking_code }}</span>
        <StatusBadge :status="m.order.status" :label="m.order.status_label" />
      </div>
      <template v-if="m.type === 'pickup'">
        <p class="font-semibold">{{ m.order.merchant?.business_name }}</p>
        <p class="text-sm">{{ m.order.pickup.zone_name }} · {{ m.order.pickup.address }}</p>
      </template>
      <template v-else>
        <p class="font-semibold">{{ m.order.recipient.name || m.order.recipient.phone }}</p>
        <p class="text-sm">{{ m.order.delivery.zone_name }} · {{ m.order.delivery.address }}</p>
        <p class="text-sm font-medium">À encaisser : {{ money(m.order.amounts.cod_amount) }}</p>
      </template>
      <p v-if="m.status === 'assigned'" class="text-xs text-amber-700 font-medium">Nouvelle mission : à accepter</p>
    </RouterLink>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import http from '../../bootstrap/axios'
import StatusBadge from '../../components/StatusBadge.vue'
import { useAuthStore } from '../../stores/auth'
import { useNotificationStore } from '../../stores/notifications'
import { currentPosition } from '../../composables/useGeolocation'
import { money } from '../../utils/format'

const auth = useAuthStore()
const notifications = useNotificationStore()
const missions = ref([])
const loading = ref(true)
const available = ref(false)
const tab = ref('pickup')
const tabs = [
  { value: 'pickup', label: 'À ramasser' },
  { value: 'delivery', label: 'À livrer' },
  { value: 'return', label: 'Retours' },
]

const visible = computed(() => missions.value.filter((m) => m.type === tab.value))
const count = (type) => missions.value.filter((m) => m.type === type).length

async function load() {
  missions.value = (await http.get('/courier/missions')).data.data
  loading.value = false
  if (!count(tab.value) && count('delivery')) tab.value = 'delivery'
}

async function toggleAvailability() {
  const position = await currentPosition()
  const { data } = await http.patch('/courier/status', { is_available: !available.value, ...(position || {}) })
  available.value = data.data.is_available
  if (auth.user.courier) auth.user.courier.is_available = available.value
}

// Position envoyée toutes les minutes pendant le service
let locationTimer
async function sendLocation() {
  if (!available.value) return
  const position = await currentPosition()
  if (position) http.patch('/courier/status', position).catch(() => {})
}

// Nouvelle mission reçue en temps réel : on recharge
watch(() => notifications.unread, load)

onMounted(async () => {
  load()
  available.value = auth.user?.courier?.is_available ?? false
  locationTimer = setInterval(sendLocation, 60000)
})
onBeforeUnmount(() => clearInterval(locationTimer))
</script>
