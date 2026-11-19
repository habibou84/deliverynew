<template>
  <!-- Disponibilité du livreur, en petit dans l'en-tête à côté de la cloche -->
  <button
    type="button"
    :class="['tap flex h-9 shrink-0 items-center gap-1.5 rounded-full pl-1.5 pr-2.5 text-xs font-semibold text-white transition disabled:opacity-70', available ? 'bg-emerald-700' : 'bg-white/20']"
    role="switch"
    :aria-checked="available"
    :aria-label="available ? 'Disponible : touchez pour passer indisponible' : 'Indisponible : touchez pour commencer votre journée'"
    :disabled="busy"
    @click="toggle"
  >
    <span :class="['relative h-[18px] w-[30px] rounded-full transition', available ? 'bg-white/35' : 'bg-white/25']">
      <span :class="['absolute top-0.5 h-3.5 w-3.5 rounded-full bg-white shadow transition-all', available ? 'left-[14px]' : 'left-0.5']" />
    </span>
    {{ available ? 'Disponible' : 'Indisponible' }}
  </button>
</template>

<script setup>
import { computed, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { currentPosition } from '../../composables/useGeolocation'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const auth = useAuthStore()
const toasts = useToastStore()
const busy = ref(false)
const available = computed(() => !!auth.user?.courier?.is_available)

async function toggle() {
  busy.value = true
  try {
    const position = await currentPosition()
    const { data } = await http.patch('/courier/status', { is_available: !available.value, ...(position || {}) })
    if (auth.user?.courier) auth.user.courier.is_available = data.data.is_available
    toasts.success(data.data.is_available ? 'Vous êtes disponible : vous recevez des missions.' : 'Vous êtes indisponible : plus de nouvelles missions.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>
